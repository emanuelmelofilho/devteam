import React, {useEffect, useState} from 'react';
import api from '../api';

export const EVENT_LABELS = {'devteam.updated':'Devteam atualizado','project.created':'Projeto criado','project.updated':'Projeto atualizado','project.deleted':'Projeto excluído','task.created':'Atividade criada','task.started':'Iniciada','task.moved':'Etapa alterada','task.finished':'Finalizada','task.blocked':'Bloqueada','task.step':'Etapa executada'};

const AGENT_LABELS = {developer: 'Desenvolvedor', tester: 'Testador', devops: 'DevOps'};
const PROVIDER_LABELS = {claude: 'Claude', codex: 'Codex'};
const OUTCOME_LABELS = {success: 'Concluída com sucesso', bug: 'Bug encontrado', blocked: 'Bloqueada', publish_blocked: 'Publicação bloqueada', error: 'Falhou', running: 'Em execução'};

// Who did it: an AI agent when the step came from a worker, otherwise the person (or the system).
export function actorLabel(log) {
  if (log.execution) return `${AGENT_LABELS[log.execution.agent] || log.execution.agent} (IA)`;
  return log.user?.name || 'Sistema';
}

function formatDuration(ms) {
  if (ms === null || ms === undefined) return '—';
  const seconds = Math.round(ms / 1000);
  if (seconds < 60) return `${seconds}s`;
  const minutes = Math.floor(seconds / 60);
  return `${minutes}m ${seconds % 60}s`;
}

function formatCost(value) {
  if (value === null || value === undefined) return '—';
  return `US$ ${Number(value).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 4})}`;
}

function diffClass(line) {
  if (line.startsWith('+++') || line.startsWith('---') || line.startsWith('diff --git') || line.startsWith('index ')) return 'diff-meta';
  if (line.startsWith('+')) return 'diff-add';
  if (line.startsWith('-')) return 'diff-del';
  if (line.startsWith('@@')) return 'diff-hunk';
  return '';
}

function Diff({patch}) {
  const lines = patch.split('\n');
  return <pre className="log-diff" tabIndex="0" aria-label="Alterações do commit">{lines.map((line, index) => <span key={index} className={diffClass(line)}>{line}{'\n'}</span>)}</pre>;
}

function Section({title, children}) {
  return <section className="log-section"><h3>{title}</h3>{children}</section>;
}

export default function LogDetail({log, onClose}) {
  const [full, setFull] = useState(null);
  const [loadError, setLoadError] = useState(false);
  const [showDiff, setShowDiff] = useState(false);

  // The list omits the (possibly large) patch, so fetch the complete step when one was recorded.
  useEffect(() => {
    setFull(null); setLoadError(false); setShowDiff(false);
    if (!log.execution) return undefined;
    let active = true;
    api.get(`/kanban/logs/${log.id}`).then(({data}) => active && setFull(data)).catch(() => active && setLoadError(true));
    return () => { active = false; };
  }, [log]);

  const execution = full?.execution || log.execution;
  const tests = execution?.metadata;
  const hasDetails = execution && (execution.summary || execution.solution || execution.commit_message || execution.files?.length || execution.error_message);
  const commitHash = execution?.commit_hash || log.metadata?.commit_hash;
  const branch = execution?.branch || log.metadata?.branch;

  return (
    <div className="modal-backdrop" onMouseDown={event => event.target === event.currentTarget && onClose()}>
      <section className="log-detail-modal" role="dialog" aria-modal="true" aria-labelledby="log-detail-title">
        <button className="modal-close" onClick={onClose} aria-label="Fechar">×</button>
        <span className={`log-event ${log.event.replace('.', '-')}`}>{EVENT_LABELS[log.event] || log.event}</span>
        <h2 id="log-detail-title">{log.title}</h2>
        <p className="log-detail-description">{log.details}</p>

        <div className="log-detail-context">
          <div><small>Escopo</small><strong>{log.project?.name || 'Devteam'}</strong></div>
          <div><small>Atividade</small><strong>{log.task?.title || '—'}</strong></div>
          <div><small>Executado por</small><strong>{execution ? `${AGENT_LABELS[execution.agent] || execution.agent} · IA${execution.model ? ` (${execution.model})` : ''}` : (log.user?.name || 'Sistema')}</strong></div>
          <div><small>{execution ? 'Acionado por' : 'Data'}</small><strong>{execution ? (log.user?.name || 'Sistema') : new Date(log.created_at).toLocaleString('pt-BR')}</strong></div>
          {execution && <div><small>Resultado</small><strong>{OUTCOME_LABELS[execution.status] || execution.status}</strong></div>}
          {execution && <div><small>Data</small><strong>{new Date(log.created_at).toLocaleString('pt-BR')}</strong></div>}
        </div>

        {execution && !hasDetails && <p className="log-note">Este passo foi registrado antes do histórico detalhado; só o resumo acima está disponível.</p>}

        {execution?.summary && <Section title="O que foi feito"><p className="log-text">{execution.summary}</p></Section>}
        {execution?.solution && <Section title="Solução adotada"><p className="log-text">{execution.solution}</p></Section>}
        {execution?.error_message && <Section title="Erro informado"><p className="log-text">{execution.error_message}</p></Section>}

        <div className="log-git-data">
          <div><small>Branch</small><code>{branch || 'Não informada'}</code></div>
          <div><small>Hash do commit</small><code>{commitHash || 'Não informado'}</code></div>
        </div>
        {execution?.commit_message && <Section title="Mensagem do commit"><pre className="log-commit-message">{execution.commit_message}</pre></Section>}

        {execution?.files?.length > 0 && (
          <Section title={`Arquivos alterados (${execution.files.length})`}>
            <ul className="log-files">{execution.files.map(file => <li key={file.path}><code>{file.path}</code><span><b className="diff-add">+{file.additions}</b> <b className="diff-del">−{file.deletions}</b></span></li>)}</ul>
          </Section>
        )}

        {tests && (tests.executed !== undefined || tests.passed !== undefined) && (
          <Section title="Testes"><p className="log-text">{tests.executed === false ? 'Não executados.' : `${tests.passed ?? 0} passaram, ${tests.failed ?? 0} falharam.`}</p></Section>
        )}

        {execution && Number(execution.has_patch) === 1 && (
          <Section title="Alterações (diff)">
            {!showDiff && <button type="button" className="button secondary" onClick={() => setShowDiff(true)}>Ver diff do commit</button>}
            {showDiff && !full && !loadError && <p className="log-note">Carregando alterações…</p>}
            {showDiff && loadError && <p className="log-note error">Não foi possível carregar as alterações.</p>}
            {showDiff && full?.execution?.patch && <Diff patch={full.execution.patch} />}
          </Section>
        )}

        {execution && (
          <Section title="Execução">
            <div className="log-run">
              <span>Tentativa <b>{execution.attempt}</b></span>
              <span>Duração <b>{formatDuration(execution.duration_ms)}</b></span>
              <span>Tokens <b>{execution.total_tokens != null ? Number(execution.total_tokens).toLocaleString('pt-BR') : '—'}</b></span>
              <span>Custo <b>{formatCost(execution.estimated_cost)}</b></span>
              <span>Provedor <b>{PROVIDER_LABELS[execution.provider] || '—'}</b></span>
              {execution.prompt_version && <span>Prompt <b>v{execution.prompt_version}</b></span>}
            </div>
          </Section>
        )}

        <footer className="modal-actions"><button className="button secondary" onClick={onClose}>Fechar</button></footer>
      </section>
    </div>
  );
}
