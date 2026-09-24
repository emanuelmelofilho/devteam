import {execFileSync, spawn} from 'node:child_process';
import {existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import {homedir, tmpdir} from 'node:os';
import {isAbsolute, join, resolve} from 'node:path';
import {runCodex as execCodex, runClaude as execClaude} from './process_manager.mjs';

const role = process.argv[2];
const roles = {
  developer: {queue:'tasks_developer', agent:'developer_worker'},
  tester: {queue:'tasks_tester', agent:'tester_worker'},
  devops: {queue:'tasks_devops', agent:'devops_worker'},
};
if (!roles[role]) throw new Error('Use: node agents/runtime/host_worker.mjs developer|tester|devops');

const rabbitUrl = process.env.RABBITMQ_MANAGEMENT_URL || 'http://127.0.0.1:15672';
const rabbitAuth = Buffer.from(`${process.env.RABBITMQ_USER || 'guest'}:${process.env.RABBITMQ_PASS || 'guest'}`).toString('base64');
const apiBase = process.env.KANBAN_API || 'http://127.0.0.1:8000/api/kanban';
const codexBin = process.env.CODEX_BIN || 'codex';
const codexModel = process.env.CODEX_MODEL || null;
const claudeBin = process.env.CLAUDE_BIN || 'claude';
// Claude Code's own default is Opus 5 (US$ 5/25 per million tokens), which cost about US$ 4-5 per
// finished task. Sonnet 5 (US$ 2/10) is the default here; set CLAUDE_MODEL to use another model.
const claudeModel = process.env.CLAUDE_MODEL || 'claude-sonnet-5';
const claudeEffort = process.env.CLAUDE_EFFORT || null;
// Hard ceiling per run so a looping agent cannot drain the account. On Opus 5 a normal run cost
// US$ 0.7-2.6, so the cap is generous; it is a safety net, not a budget.
const claudeMaxBudgetUsd = process.env.CLAUDE_MAX_BUDGET_USD || '5';
// Each tool definition is re-read on every turn, and Claude Code ships ~30 by default (about
// 15K tokens of a ~28K-token context). The agents only need shell, files and search; the tester
// must not edit code, so it does not get the edit tools.
const claudeTools = {
  developer: 'Bash,Read,Edit,Write,Glob,Grep',
  tester: 'Bash,Read,Glob,Grep',
  devops: 'Bash,Read,Edit,Write,Glob,Grep',
};
const schemaPath = resolve('agents/contracts/result.schema.json');
const promptTemplate = readFileSync(resolve(`agents/prompts/${role}.md`), 'utf8');
const promptVersion = (promptTemplate.match(/<!--\s*version:\s*(\S+)\s*-->/) || [null, null])[1];
const requestTimeoutMs = Number(process.env.WORKER_HTTP_TIMEOUT_MS || 15000);
let stopping = false;

const delay = ms => new Promise(resolvePromise=>setTimeout(resolvePromise, ms));
const log = message => console.log(`[${role}_worker] ${new Date().toISOString()} ${message}`);

async function api(path, token, options = {}) {
  const response = await fetch(`${apiBase}${path}`, {
    ...options,
    signal: options.signal || AbortSignal.timeout(requestTimeoutMs),
    headers: {'Accept':'application/json','Content-Type':'application/json','Authorization':`Bearer ${token}`,...options.headers},
  });
  const body = response.status === 204 ? null : await response.json().catch(()=>null);
  if (!response.ok) throw new Error(body?.message || `API HTTP ${response.status}`);
  return body;
}

async function comment(taskId, token, text) {
  return api(`/tasks/${taskId}/comments`, token, {method:'POST',body:JSON.stringify({comment:text})});
}

// Provider chosen in Settings when the task was queued; messages queued before
// the Claude option existed carry no provider and keep running on Codex.
const providerFor = task => task.provider === 'claude' ? 'claude' : 'codex';
const providerLabel = provider => provider === 'claude' ? 'Claude' : 'Codex';

async function agentApiKey(task) {
  if (!task.credential_token) return null;
  const provider = providerFor(task);
  const response = await api('/worker/credential', task.api_token, {
    method:'POST',
    body:JSON.stringify({token:task.credential_token,task_id:task.task_id,provider}),
  });
  if (!response?.api_key) throw new Error(`Backend não retornou a credencial temporária do ${providerLabel(provider)}.`);
  return response.api_key;
}

function projectDirectory(value) {
  if (!value) throw new Error('Projeto sem diretório configurado.');
  const directory = isAbsolute(value) ? value : join(homedir(), value);
  if (!existsSync(directory)) throw new Error(`Diretório não encontrado: ${directory}`);
  return directory;
}

// Working copy for a 'repository' project: the app owns and manages this folder (clone/fetch/pull),
// unlike a 'local' project's directory, which the human manages. Same base already used to install
// the worker's own runtime/prompts.
const REPO_WORKSPACE_ROOT = join(homedir(), 'Library/Application Support/Devteam/repos');
const repositoryWorkDirectory = task => join(REPO_WORKSPACE_ROOT, String(task.project_id));

// Resolves a host path the same way projectDirectory() does (absolute stays as-is, otherwise
// joined onto homedir()), plus tolerating a leading `~/`, the form people naturally type for a key.
function resolveHostPath(value) {
  const stripped = value.replace(/^~\//, '');
  return isAbsolute(stripped) ? stripped : join(homedir(), stripped);
}

// Fetches the real HTTPS username/password by trading the one-time token — sibling of agentApiKey().
async function repositoryCredential(task) {
  if (!task.repository_credential_token) return null;
  const response = await api('/worker/credential', task.api_token, {
    method:'POST',
    body:JSON.stringify({token:task.repository_credential_token,task_id:task.task_id,purpose:'repository_password'}),
  });
  if (!response?.repository_password) throw new Error('Backend não retornou a credencial temporária do repositório.');
  return response;
}

// A GIT_ASKPASS script, rather than an https://user:pass@host URL or a CLI flag, keeps the password
// out of argv (visible to any other process via `ps`) and out of `git remote -v`/shell history.
function writeAskpassScript(directory, username, password) {
  const scriptPath = join(directory, 'askpass.sh');
  writeFileSync(scriptPath, '#!/bin/sh\ncase "$1" in\n  Username*) printf %s "$GIT_ASKPASS_USERNAME" ;;\n  *) printf %s "$GIT_ASKPASS_PASSWORD" ;;\nesac\n', {mode:0o700});
  return {...process.env, GIT_ASKPASS:scriptPath, GIT_ASKPASS_USERNAME:username, GIT_ASKPASS_PASSWORD:password};
}

// Builds the git child-process environment for a repository project: HTTPS goes through the askpass
// script above, SSH points GIT_SSH_COMMAND at a key already on this host (never its content). Host
// key checking is left on by default — the host's own ~/.ssh/known_hosts must already trust the
// remote, same caution as the hand-written SSH deploy step in agents/prompts/devops.md.
async function gitEnvironmentFor(task, temp) {
  const baseEnvironment = {...process.env, GIT_TERMINAL_PROMPT:'0'};
  if (task.project_repository_auth_type === 'https') {
    const credential = await repositoryCredential(task);
    if (!credential) return baseEnvironment;
    const username = task.project_repository_username || credential.repository_username || '';
    return writeAskpassScript(temp, username, credential.repository_password);
  }
  if (task.project_repository_auth_type === 'ssh' && task.project_repository_ssh_key_path) {
    const keyPath = resolveHostPath(task.project_repository_ssh_key_path);
    return {...baseEnvironment, GIT_SSH_COMMAND:`ssh -i ${keyPath} -o IdentitiesOnly=yes`};
  }
  return baseEnvironment;
}

const gitIn = (directory, args, env) => execFileSync('git', args, {cwd:directory, env, encoding:'utf8', timeout:120000, stdio:['ignore','pipe','pipe']}).trim();

// Clones or updates the working copy for a 'repository' project. Never forces, never resets
// destructively — same spirit as the deploy rules in agents/prompts/devops.md.
async function syncRepository(task) {
  const directory = repositoryWorkDirectory(task);
  const temp = mkdtempSync(join(tmpdir(), `devteam-git-${role}-`));
  try {
    const env = await gitEnvironmentFor(task, temp);
    if (!existsSync(directory)) {
      mkdirSync(REPO_WORKSPACE_ROOT, {recursive:true});
      gitIn(REPO_WORKSPACE_ROOT, ['clone', task.project_repository_url, directory], env);
    } else {
      gitIn(directory, ['remote', 'set-url', 'origin', task.project_repository_url], env);
      gitIn(directory, ['fetch', 'origin'], env);
      gitIn(directory, ['remote', 'set-head', 'origin', '--auto'], env);
      const defaultBranch = gitIn(directory, ['symbolic-ref', '--short', 'refs/remotes/origin/HEAD'], env).replace(/^origin\//, '');
      gitIn(directory, ['checkout', '-B', defaultBranch, '--track', `origin/${defaultBranch}`], env);
      gitIn(directory, ['pull', '--ff-only'], env);
    }
    return directory;
  } finally { rmSync(temp, {recursive:true, force:true}); }
}

// Local projects behave exactly as before (projectDirectory, unchanged); a 'repository' project is
// cloned/updated on demand instead. Tasks queued before this field existed default to 'local'.
const resolveProjectDirectory = task => task.project_source_type === 'repository'
  ? syncRepository(task)
  : Promise.resolve(projectDirectory(task.project_directory));

// The kind of change decides which part of the version DevOps raises, following semantic
// versioning: the numbers to the right of the one that goes up return to zero.
const CHANGE_TYPES = {
  feature: {label: 'Nova funcionalidade', rule: 'aumente em 1 o primeiro número e zere o segundo e o terceiro', example: '1.0.12 → 2.0.0'},
  improvement: {label: 'Melhoria de funcionalidade', rule: 'aumente em 1 o segundo número, zere o terceiro e mantenha o primeiro', example: '1.0.12 → 1.1.0'},
  fix: {label: 'Correção de funcionalidade', rule: 'aumente em 1 o terceiro número e mantenha o primeiro e o segundo', example: '1.0.12 → 1.0.13'},
};
const changeType = task => CHANGE_TYPES[task.type] || CHANGE_TYPES.fix;
const versionRule = task => {
  const type = changeType(task);
  return `Esta tarefa é do tipo "${type.label}", então, partindo da versão atual, ${type.rule} (ex.: ${type.example}).`;
};

// Builds the small "Task Context" bundle (see agents/contracts/context.schema.json)
// interpolated into the role's prompt template, instead of dumping the whole
// project into the prompt.
function buildContext(task) {
  const stack = [task.project_stack?.frontend, task.project_stack?.backend, task.project_stack?.database].filter(Boolean).join(' + ');
  const lines = [
    `Tarefa #${task.task_id}: ${task.title}`,
    `Descrição: ${task.description || 'sem descrição'}`,
    `Projeto: ${task.project_name}`,
    `Tipo de alteração: ${changeType(task).label}`,
  ];
  if (stack) lines.push(`Stack: ${stack}`);
  if (task.work_summary) lines.push(`Último resultado registrado: ${task.work_summary}`);
  lines.push('Na resposta estruturada, informe branch e commit_hash quando existirem; caso contrário, use null.');
  return lines.join('\n');
}

function promptFor(task) {
  const branch = task.branch || `agent/task-${task.task_id}`;
  return promptTemplate
    .replace('{{context}}', buildContext(task))
    .replaceAll('{{version_rule}}', versionRule(task))
    .replaceAll('{{branch}}', branch)
    .replaceAll('{{attempt}}', String(task.attempt ?? 1))
    .replaceAll('{{max_attempts}}', String(task.max_attempts ?? 3));
}

async function runCodexAgent(task, apiKey, directory) {
  const temp = mkdtempSync(join(tmpdir(), `devteam-${role}-`));
  const output = join(temp, 'result.json');
  const args = ['exec','--approve-for-me','--ephemeral','--skip-git-repo-check','--output-schema',schemaPath,'--output-last-message',output,'--cd',directory,promptFor(task)];
  try {
    let childEnvironment = process.env;
    if (apiKey) {
      const authDirectory = join(temp, 'auth');
      mkdirSync(authDirectory, {mode:0o700});
      childEnvironment = {...process.env, CODEX_HOME:authDirectory};
      const loginCode = await new Promise((resolvePromise, reject)=>{
        const login = spawn(codexBin, ['login','--with-api-key'], {env:childEnvironment,stdio:['pipe','ignore','inherit']});
        login.once('error', reject);
        login.once('exit', code=>resolvePromise(code ?? 1));
        login.stdin.end(`${apiKey}\n`);
      });
      if (loginCode !== 0) throw new Error('Codex recusou a autenticação por chave da API.');
    }
    const {exitCode, usage} = await execCodex({bin: codexBin, args, env: childEnvironment});
    if (exitCode !== 0 || !existsSync(output)) throw new Error(`Codex terminou com código ${exitCode}`);
    const structured = JSON.parse(readFileSync(output, 'utf8'));
    return {...structured, usage, model: codexModel};
  } finally { rmSync(temp, {recursive:true,force:true}); }
}

async function runClaudeAgent(task, apiKey, directory) {
  const temp = mkdtempSync(join(tmpdir(), `devteam-${role}-`));
  // The prompt goes through stdin because variadic flags would swallow a positional argument.
  const args = ['-p','--output-format','stream-json','--verbose','--json-schema',readFileSync(schemaPath,'utf8'),'--permission-mode','bypassPermissions','--no-session-persistence',
    '--tools',claudeTools[role],'--disable-slash-commands','--exclude-dynamic-system-prompt-sections','--max-budget-usd',claudeMaxBudgetUsd];
  if (claudeModel) args.push('--model', claudeModel);
  if (claudeEffort) args.push('--effort', claudeEffort);
  try {
    let childEnvironment = process.env;
    if (apiKey) {
      // Ephemeral config dir keeps the run away from the host's own Claude login and settings.
      const configDirectory = join(temp, 'claude');
      mkdirSync(configDirectory, {mode:0o700});
      childEnvironment = {...process.env, ANTHROPIC_API_KEY:apiKey, CLAUDE_CONFIG_DIR:configDirectory};
      delete childEnvironment.CLAUDE_CODE_OAUTH_TOKEN;
    }
    const {exitCode, usage, structured, model, cost, turns, error, warning} = await execClaude({bin: claudeBin, args, env: childEnvironment, cwd: directory, stdinText: promptFor(task)});
    if (warning) log(`Claude foi interrompido por limite (${warning}) depois de entregar o resultado; o resultado foi aceito`);
    if (error) throw new Error(`Claude terminou com erro: ${error}`);
    if (exitCode !== 0 || !structured) throw new Error(`Claude terminou com código ${exitCode}`);
    return {...structured, usage, model: model || claudeModel, cost, turns};
  } finally { rmSync(temp, {recursive:true,force:true}); }
}

const runAgent = (task, apiKey, directory) => providerFor(task) === 'claude' ? runClaudeAgent(task, apiKey, directory) : runCodexAgent(task, apiKey, directory);

// Providers don't obey string limits reliably (Claude writes a sentence where Codex wrote a
// word) and the backend rejects the whole report when a field is too long.
const clamp = (value, max) => typeof value === 'string' ? value.slice(0, max) : value ?? null;

// What the agent said it did is not enough to understand the solution, so read what the commit
// actually contains. The patch is capped: it is history for people to read, not a backup.
const PATCH_LIMIT = 60000;
const withoutNul = text => text.replace(/\u0000/g, '');
const git = (directory, args) => execFileSync('git', ['-C', directory, ...args], {encoding: 'utf8', maxBuffer: 32 * 1024 * 1024, timeout: 20000, stdio: ['ignore', 'pipe', 'ignore']});

function commitEvidence(directory, hash) {
  if (!/^[a-f0-9]{7,40}$/i.test(hash || '')) return {};
  const show = (...flags) => git(directory, ['show', '--no-ext-diff', '--no-textconv', '--no-color', ...flags, hash, '--']);
  try {
    const files = show('--numstat', '--format=').split('\n').filter(Boolean).slice(0, 300).map(line => {
      const [additions, deletions, ...path] = line.split('\t');
      return {path: path.join('\t'), additions: Number(additions) || 0, deletions: Number(deletions) || 0};
    });
    let patch = withoutNul(show('--patch', '--format='));
    if (patch.length > PATCH_LIMIT) patch = `${patch.slice(0, PATCH_LIMIT)}\n… diff truncado (${patch.length - PATCH_LIMIT} caracteres omitidos)`;
    return {commit_message: withoutNul(show('-s', '--format=%B')).trim().slice(0, 5000) || null, files, patch: patch || null};
  } catch (error) {
    log(`não foi possível ler o commit ${hash}: ${error.message.split('\n')[0]}`);
    return {};
  }
}

const START_COMMENTS = {
  tester: 'Tester iniciou a validação da implementação.',
  devops: 'DevOps iniciou a preparação da publicação.',
};

// Runs the agent and reports the outcome to the orchestrator. The worker
// never decides the next lane itself — /tasks/{id}/report applies the state
// machine (agents/orchestrator/state_machine.php) and returns where the task
// ended up, which we only log/comment on for human visibility.
async function processTask(task) {
  const token = task.api_token;
  if (!task.task_id || !token) throw new Error('Mensagem sem task_id ou token.');
  const currentTask = await api(`/tasks/${task.task_id}`, token);
  const expected = {
    developer: {agent:'developer_worker', lanes:['todo','in_progress','blocked']},
    tester: {agent:'tester_worker', lanes:['review']},
    devops: {agent:'devops_worker', lanes:['publishing']},
  }[role];
  if (currentTask.assigned_agent !== expected.agent || !expected.lanes.includes(currentTask.lane)) {
    log(`ignorando mensagem obsoleta da tarefa #${task.task_id}; estado atual=${currentTask.lane}, agente=${currentTask.assigned_agent || 'nenhum'}`);
    return;
  }
  task = {...task, ...currentTask};
  log(`iniciando tarefa #${task.task_id} (${task.title}), tentativa ${task.attempt ?? 1}/${task.max_attempts ?? 3}, provedor ${providerLabel(providerFor(task))}`);

  if (role === 'developer' && task.lane !== 'in_progress') {
    await api(`/tasks/${task.task_id}`, token, {method:'PATCH',body:JSON.stringify({lane:'in_progress'})});
  }
  if (START_COMMENTS[role]) await comment(task.task_id, token, START_COMMENTS[role]);

  const directory = await resolveProjectDirectory(task);
  const result = await runAgent(task, await agentApiKey(task), directory);
  const evidence = commitEvidence(directory, result.commit_hash);

  const report = {
    execution_id: task.execution_id ?? null,
    outcome: result.outcome,
    summary: clamp(result.summary, 5000),
    branch: clamp(result.branch ?? task.branch, 255),
    commit_hash: result.commit_hash ?? null,
    version: result.version ?? null,
    tests: result.tests ?? null,
    next: clamp(result.next, 30),
    error_type: clamp(result.error_type, 100),
    error_message: result.error_message ?? null,
    usage: result.usage ?? null,
    model: result.model ?? null,
    cost_usd: result.cost ?? null,
    solution: clamp(result.solution, 8000),
    ...evidence,
    prompt_version: promptVersion,
  };
  const response = await api(`/tasks/${task.task_id}/report`, token, {method:'POST', body:JSON.stringify(report)});
  const spent = result.turns != null ? `, ${result.turns} passos${result.cost != null ? `, US$ ${result.cost.toFixed(4)}` : ''}` : '';
  log(`tarefa #${task.task_id}: outcome=${result.outcome} -> raia ${response.task?.lane}${spent}`);

  if (result.outcome === 'bug') {
    await comment(task.task_id, token, `Erro encontrado: ${result.summary}`);
  } else if (result.outcome === 'blocked') {
    await comment(task.task_id, token, `${roles[role].agent} bloqueado: ${result.summary}`);
  } else if (result.outcome === 'publish_blocked') {
    await comment(task.task_id, token, `Envio/deploy bloqueado; tarefa mantida em Em Publicação: ${result.summary}`);
  }
}

async function nextMessage() {
  const response = await fetch(`${rabbitUrl}/api/queues/%2F/${roles[role].queue}/get`, {
    method:'POST', headers:{'Authorization':`Basic ${rabbitAuth}`,'Content-Type':'application/json'},
    signal: AbortSignal.timeout(requestTimeoutMs),
    body:JSON.stringify({count:1,ackmode:'ack_requeue_false',encoding:'auto',truncate:100000}),
  });
  if (!response.ok) throw new Error(`RabbitMQ HTTP ${response.status}`);
  return (await response.json())[0];
}

process.on('SIGINT', ()=>{stopping=true;}); process.on('SIGTERM', ()=>{stopping=true;});
log(`ativo, aguardando ${roles[role].queue} (prompt v${promptVersion ?? '?'})`);
while (!stopping) {
  try {
    const message = await nextMessage();
    if (!message) { await delay(3000); continue; }
    const task = JSON.parse(message.payload);
    try { await processTask(task); }
    catch (error) {
      log(`falha na tarefa #${task.task_id || '?'}: ${error.message}`);
      if (task.task_id && task.api_token) await comment(task.task_id, task.api_token, `${roles[role].agent} bloqueado: ${error.message}`).catch(()=>{});
    }
  } catch (error) { log(`fila indisponível: ${error.message}`); await delay(5000); }
}
log('encerrado');
