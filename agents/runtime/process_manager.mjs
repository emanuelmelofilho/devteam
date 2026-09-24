import {spawn} from 'node:child_process';

const EMPTY_USAGE = {input_tokens: 0, cached_input_tokens: 0, output_tokens: 0, reasoning_output_tokens: 0};

function addUsage(total, usage) {
  if (!usage) return total;
  return {
    input_tokens: total.input_tokens + (usage.input_tokens || 0),
    cached_input_tokens: total.cached_input_tokens + (usage.cached_input_tokens || 0),
    output_tokens: total.output_tokens + (usage.output_tokens || 0),
    reasoning_output_tokens: total.reasoning_output_tokens + (usage.reasoning_output_tokens || 0),
  };
}

/**
 * Spawns a child process, appending `--json` so `codex exec` streams a JSONL
 * event log on stdout. Each line is still echoed to this process' stdout for
 * log parity with the previous plain `stdio: 'inherit'` behaviour, while
 * every `turn.completed` event's `usage` is accumulated: the model does not
 * reliably self-report token usage inside the --output-schema structured
 * result, but the Codex CLI event stream does (input_tokens,
 * cached_input_tokens, output_tokens, reasoning_output_tokens).
 *
 * @returns {Promise<{exitCode:number, usage:object}>}
 */
export function runCodex({bin, args, env, stdinText}) {
  return new Promise((resolvePromise, reject) => {
    const fullArgs = [...args, '--json'];
    const child = spawn(bin, fullArgs, {env, stdio: [stdinText ? 'pipe' : 'ignore', 'pipe', 'inherit']});
    let usage = {...EMPTY_USAGE};
    let buffer = '';

    child.stdout.on('data', chunk => {
      buffer += chunk.toString('utf8');
      let newlineIndex;
      while ((newlineIndex = buffer.indexOf('\n')) !== -1) {
        const line = buffer.slice(0, newlineIndex);
        buffer = buffer.slice(newlineIndex + 1);
        if (!line.trim()) continue;
        process.stdout.write(`${line}\n`);
        try {
          const event = JSON.parse(line);
          if (event.type === 'turn.completed' && event.usage) usage = addUsage(usage, event.usage);
        } catch {
          // Not every stdout line is guaranteed JSON (e.g. banners); ignore.
        }
      }
    });

    child.once('error', reject);
    child.once('exit', code => resolvePromise({exitCode: code ?? 1, usage}));

    if (stdinText) child.stdin.end(stdinText);
  });
}

const CLAUDE_LOG_LIMIT = 300;
const clip = value => {
  const text = String(value ?? '').replace(/\s+/g, ' ').trim();
  return text.length > CLAUDE_LOG_LIMIT ? `${text.slice(0, CLAUDE_LOG_LIMIT)}…` : text;
};

// `claude -p --output-format stream-json --verbose` emits every message in full,
// which would flood the worker logs; keep one short line per assistant step.
function logClaudeEvent(event) {
  if (event.type === 'assistant') {
    for (const block of event.message?.content || []) {
      if (block.type === 'text' && block.text?.trim()) process.stdout.write(`[claude] ${clip(block.text)}\n`);
      else if (block.type === 'tool_use') process.stdout.write(`[claude] ${block.name} ${clip(JSON.stringify(block.input))}\n`);
    }
  } else if (event.type === 'result') {
    const cost = typeof event.total_cost_usd === 'number' ? ` · US$ ${event.total_cost_usd.toFixed(4)}` : '';
    const steps = event.num_turns != null ? ` · ${event.num_turns} passos` : '';
    const model = mainModel(event.modelUsage);
    process.stdout.write(`[claude] resultado: ${event.subtype}${event.is_error ? ' (erro)' : ''}${steps}${cost}${model ? ` · ${model}` : ''}\n`);
  }
}

// Maps Claude's usage onto the Codex-shaped payload the backend expects, where
// `input_tokens` already includes the cached portion.
function claudeUsage(usage) {
  if (!usage) return {...EMPTY_USAGE};
  const cacheRead = usage.cache_read_input_tokens || 0;
  return {
    input_tokens: (usage.input_tokens || 0) + (usage.cache_creation_input_tokens || 0) + cacheRead,
    cached_input_tokens: cacheRead,
    output_tokens: usage.output_tokens || 0,
    reasoning_output_tokens: 0,
  };
}

function mainModel(modelUsage) {
  const models = Object.entries(modelUsage || {});
  if (models.length === 0) return null;
  return models.sort((a, b) => (b[1].costUSD || 0) - (a[1].costUSD || 0))[0][0];
}

/**
 * Spawns `claude -p --output-format stream-json` and reads its event stream.
 * Unlike Codex, Claude has no `--output-last-message` file: the structured
 * answer (validated by `--json-schema`) and the usage arrive in the final
 * `result` event, so both are returned here.
 *
 * `model` is the model that accounted for most of the cost (Claude Code also calls a small
 * model for housekeeping), `cost` is Claude Code's own USD figure for the run and `turns` the
 * number of model round trips, which is what drives token use: every turn re-reads the context.
 *
 * @returns {Promise<{exitCode:number, usage:object, structured:object|null, model:string|null, cost:number|null, turns:number|null, error:string|null, warning:string|null}>}
 */
export function runClaude({bin, args, env, cwd, stdinText}) {
  return new Promise((resolvePromise, reject) => {
    const child = spawn(bin, args, {env, cwd, stdio: ['pipe', 'pipe', 'inherit']});
    let buffer = '';
    let final = null;
    let delivered = null;

    const handleLine = line => {
      if (!line.trim()) return;
      try {
        const event = JSON.parse(line);
        logClaudeEvent(event);
        if (event.type === 'result') final = event;
        // The answer is a StructuredOutput tool call; keep it because a limit (budget, turns)
        // can end the run right after it and the final event would then carry no answer.
        if (event.type === 'assistant') {
          for (const block of event.message?.content || []) {
            if (block.type === 'tool_use' && block.name === 'StructuredOutput' && block.input) delivered = block.input;
          }
        }
      } catch {
        // Ignore non-JSON output (banners, warnings).
      }
    };

    child.stdout.on('data', chunk => {
      buffer += chunk.toString('utf8');
      let newlineIndex;
      while ((newlineIndex = buffer.indexOf('\n')) !== -1) {
        handleLine(buffer.slice(0, newlineIndex));
        buffer = buffer.slice(newlineIndex + 1);
      }
    });

    child.once('error', reject);
    child.once('exit', code => {
      handleLine(buffer);
      let structured = final?.structured_output ?? null;
      if (!structured && final && !final.is_error) {
        try { structured = JSON.parse(final.result); } catch { /* not JSON */ }
      }
      // A run cut off by a limit after it already gave its answer still did the work.
      const limitAfterAnswer = Boolean(delivered) && /^error_max_/.test(final?.subtype || '');
      if (!structured && limitAfterAnswer) structured = delivered;
      resolvePromise({
        exitCode: code ?? 1,
        usage: claudeUsage(final?.usage),
        structured,
        model: mainModel(final?.modelUsage),
        cost: typeof final?.total_cost_usd === 'number' ? final.total_cost_usd : null,
        turns: final?.num_turns ?? null,
        error: final?.is_error && !limitAfterAnswer ? clip(final.result) || final.subtype : null,
        warning: limitAfterAnswer ? final.subtype : null,
      });
    });

    child.stdin.end(stdinText ?? '');
  });
}
