import {spawn} from 'node:child_process';
import {existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync} from 'node:fs';
import {homedir, tmpdir} from 'node:os';
import {isAbsolute, join, resolve} from 'node:path';

const role = process.argv[2];
const roles = {
  developer: {queue:'tasks_developer', agent:'developer_worker'},
  tester: {queue:'tasks_tester', agent:'tester_worker'},
  devops: {queue:'tasks_devops', agent:'devops_worker'},
};
if (!roles[role]) throw new Error('Use: node agents/host_worker.mjs developer|tester|devops');

const rabbitUrl = process.env.RABBITMQ_MANAGEMENT_URL || 'http://127.0.0.1:15672';
const rabbitAuth = Buffer.from(`${process.env.RABBITMQ_USER || 'guest'}:${process.env.RABBITMQ_PASS || 'guest'}`).toString('base64');
const apiBase = process.env.KANBAN_API || 'http://127.0.0.1:8000/api/kanban';
const codexBin = process.env.CODEX_BIN || 'codex';
const schema = resolve('agents/worker_output_schema.json');
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

async function codexApiKey(task) {
  if (!task.credential_token) return null;
  const response = await api('/worker/codex-credential', task.api_token, {
    method:'POST',
    body:JSON.stringify({token:task.credential_token,task_id:task.task_id}),
  });
  if (!response?.api_key) throw new Error('Backend não retornou a credencial temporária do Codex.');
  return response.api_key;
}

function projectDirectory(value) {
  if (!value) throw new Error('Projeto sem diretório configurado.');
  const directory = isAbsolute(value) ? value : join(homedir(), value);
  if (!existsSync(directory)) throw new Error(`Diretório não encontrado: ${directory}`);
  return directory;
}

function promptFor(task) {
  const context = `Tarefa #${task.task_id}: ${task.title}\nDescrição: ${task.description || 'sem descrição'}\nProjeto: ${task.project_name}\nNa resposta estruturada, informe branch e commit_hash quando existirem; caso contrário, use null.`;
  if (role === 'developer') return `${context}\n\nTrabalhe como desenvolvedor autônomo. Use sempre a branch developer (crie-a a partir da branch atual se necessário), analise a tarefa, implemente somente o solicitado, rode verificações proporcionais ao risco e não faça push nem merge em main. Ao final retorne outcome=success, um resumo concreto, a branch utilizada e o hash completo do commit, quando houver; use blocked se não puder concluir.`;
  if (role === 'tester') return `${context}\n\nAtue como testador. Não altere código-fonte. Inspecione a implementação na branch developer, execute os testes relevantes e procure regressões, textos incorretos e problemas responsivos. Retorne outcome=success se aprovado, outcome=bug se encontrar defeito, ou blocked se não puder testar. O summary deve trazer evidências curtas.`;
  return `${context}\n\nAtue como DevOps e conclua publicação e deploy de produção. Verifique a branch developer e rode validações proporcionais somente aos componentes alterados. Quando um runtime necessário não existir no host, use o fluxo Docker documentado pelo próprio projeto, se disponível. Não bloqueie por runtime ausente de um componente que não foi alterado e já estava fora do escopo da tarefa. Antes do merge, faça fetch de origin e integre mudanças remotas sem force push. Faça merge de developer em main apenas se as validações relevantes passarem. Determine a próxima versão patch semântica (ex.: 1.2.3), atualize essa versão nos arquivos da aplicação que a exibem ou empacotam (incluindo package.json e o lockfile correspondente), faça commit da atualização, crie a tag git e envie main e a nova tag para origin. O origin oficial é https://github.com/emanuelmelofilho/logoscenter.git. Depois do push, implante no servidor root@159.203.191.217 usando exclusivamente a chave ~/.ssh/verbohub_digitalocean. Como o repositório é privado e /opt/verbohub não contém .git, não tente clonar ou executar git pull no servidor: envie exatamente git archive main por SSH para um diretório temporário de release, copie do release ativo apenas .env e .env.production, registre o hash de origin/main em .deployed-revision, valide docker-compose.prod.yml e então troque os diretórios de forma recuperável, mantendo o anterior como backup. No /opt/verbohub novo, execute docker compose -p verbohub -f docker-compose.prod.yml up -d --build; os volumes nomeados de banco, RabbitMQ e storage devem ser preservados. Se a ativação falhar, restaure o diretório anterior e os containers. Confirme que .deployed-revision é igual a origin/main, que todos os containers esperados estão ativos/saudáveis e teste as respostas HTTP/HTTPS da aplicação. Nunca use force push, reset destrutivo, apague backups ou descarte alterações/segredos existentes no servidor. O fluxo deve ser idempotente: se main já contiver a tarefa e sua tag, não crie outra versão somente para repetir um deploy incompleto; reutilize a versão mais recente e prossiga da etapa pendente. Só retorne outcome=success após confirmar push, versão no código, commit de produção e saúde dos serviços. Use publish_blocked para falha de rede, SSH, autenticação, push ou deploy; use blocked em conflito, falha relevante de testes ou árvore insegura.`;
}

async function runCodex(task, apiKey) {
  const directory = projectDirectory(task.project_directory);
  const temp = mkdtempSync(join(tmpdir(), `devteam-${role}-`));
  const output = join(temp, 'result.json');
  const args = ['exec','--approve-for-me','--ephemeral','--skip-git-repo-check','--output-schema',schema,'--output-last-message',output,'--cd',directory,promptFor(task)];
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
    const exitCode = await new Promise((resolvePromise, reject)=>{
      const child = spawn(codexBin, args, {env:childEnvironment,stdio:['ignore','inherit','inherit']});
      child.once('error', reject);
      child.once('exit', code=>resolvePromise(code ?? 1));
    });
    if (exitCode !== 0 || !existsSync(output)) throw new Error(`Codex terminou com código ${exitCode}`);
    return JSON.parse(readFileSync(output, 'utf8'));
  } finally { rmSync(temp, {recursive:true,force:true}); }
}

async function processTask(task) {
  const token = task.api_token;
  if (!task.task_id || !token) throw new Error('Mensagem sem task_id ou token.');
  const currentTask = await api(`/tasks/${task.task_id}`, token);
  const expected = {
    developer: {agent:'developer_worker', lanes:['todo','in_progress']},
    tester: {agent:'tester_worker', lanes:['review']},
    devops: {agent:'devops_worker', lanes:['publishing']},
  }[role];
  if (currentTask.assigned_agent !== expected.agent || !expected.lanes.includes(currentTask.lane)) {
    log(`ignorando mensagem obsoleta da tarefa #${task.task_id}; estado atual=${currentTask.lane}, agente=${currentTask.assigned_agent || 'nenhum'}`);
    return;
  }
  task = {...task, ...currentTask};
  log(`iniciando tarefa #${task.task_id} (${task.title})`);

  if (role === 'developer') {
    if (task.lane !== 'in_progress') await api(`/tasks/${task.task_id}`, token, {method:'PATCH',body:JSON.stringify({lane:'in_progress'})});
    const result = await runCodex(task, await codexApiKey(task));
    if (result.outcome === 'success') {
      await api(`/tasks/${task.task_id}`, token, {method:'PATCH',body:JSON.stringify({lane:'review',work_summary:result.summary,branch:result.branch,commit_hash:result.commit_hash})});
      log(`tarefa #${task.task_id} entregue para revisão`);
    } else await comment(task.task_id, token, `Desenvolvedor bloqueado: ${result.summary}`);
  } else if (role === 'tester') {
    await comment(task.task_id, token, 'Tester iniciou a validação da implementação.');
    const result = await runCodex(task, await codexApiKey(task));
    if (result.outcome === 'success') {
      await api(`/tasks/${task.task_id}`, token, {method:'PATCH',body:JSON.stringify({lane:'publishing',work_summary:result.summary,branch:result.branch,commit_hash:result.commit_hash})});
      log(`tarefa #${task.task_id} aprovada e enviada para publicação`);
    } else if (result.outcome === 'publish_blocked') {
      await comment(task.task_id, token, `Envio ao GitHub bloqueado; tarefa mantida em Em Publicação: ${result.summary}`);
      log(`tarefa #${task.task_id} mantida em publicação para nova tentativa`);
    } else {
      await comment(task.task_id, token, `Erro encontrado nos testes: ${result.summary}`);
      await api(`/tasks/${task.task_id}`, token, {method:'PATCH',body:JSON.stringify({lane:'in_progress'})});
      log(`tarefa #${task.task_id} devolvida ao desenvolvedor`);
    }
  } else {
    await comment(task.task_id, token, 'DevOps iniciou a preparação da publicação.');
    const result = await runCodex(task, await codexApiKey(task));
    if (result.outcome === 'success' && /^\d+\.\d+\.\d+$/.test(result.version || '')) {
      await api(`/tasks/${task.task_id}`, token, {method:'PATCH',body:JSON.stringify({lane:'published',version:result.version,work_summary:result.summary,branch:result.branch,commit_hash:result.commit_hash})});
      log(`tarefa #${task.task_id} publicada na versão ${result.version}`);
    } else if (result.outcome === 'publish_blocked') {
      await comment(task.task_id, token, `Publicação ou deploy bloqueado; tarefa mantida em Em Publicação: ${result.summary}`);
      log(`tarefa #${task.task_id} mantida em publicação para nova tentativa`);
    } else {
      await comment(task.task_id, token, `Publicação bloqueada: ${result.summary}`);
      await api(`/tasks/${task.task_id}`, token, {method:'PATCH',body:JSON.stringify({lane:'in_progress'})});
      log(`tarefa #${task.task_id} devolvida automaticamente ao desenvolvedor`);
    }
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
log(`ativo, aguardando ${roles[role].queue}`);
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
