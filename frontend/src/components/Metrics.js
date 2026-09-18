import React, {useEffect, useState} from 'react';
import api from '../api';
import TokenUsageSection, {AGENT_COLORS} from './TokenUsageChart';

const AGENT_LABELS = {developer: 'Desenvolvedor', tester: 'Testador', devops: 'DevOps'};
const AGENT_ORDER = ['developer', 'tester', 'devops'];

function formatDuration(seconds) {
  if (seconds === null || seconds === undefined) return '—';
  if (seconds < 60) return `${seconds}s`;
  const minutes = Math.floor(seconds / 60);
  const rest = Math.round(seconds % 60);
  if (minutes < 60) return rest > 0 ? `${minutes}m ${rest}s` : `${minutes}m`;
  const hours = Math.floor(minutes / 60);
  return `${hours}h ${minutes % 60}m`;
}

function formatPercent(value) {
  return value === null || value === undefined ? '—' : `${value}%`;
}

function formatNumber(value) {
  if (value === null || value === undefined) return '—';
  if (value >= 1_000_000) return `${(value / 1_000_000).toFixed(1)}M`;
  if (value >= 1_000) return `${(value / 1_000).toFixed(1)}K`;
  return String(value);
}

function formatCurrency(value) {
  return value === null || value === undefined ? '—' : `$${Number(value).toFixed(2)}`;
}

function BarRow({label, value, max, display, color}) {
  const width = max > 0 ? Math.max(4, Math.round((value / max) * 100)) : 0;
  return (
    <div className="metric-bar-row">
      <span className="metric-bar-label">{label}</span>
      <div className="metric-bar-track"><div className="metric-bar-fill" style={{width: `${width}%`, background: color}} title={`${label}: ${display}`}></div></div>
      <span className="metric-bar-value">{display}</span>
    </div>
  );
}

function Sparkline({points}) {
  const valid = points.filter(p => p.first_pass_rate !== null && p.first_pass_rate !== undefined);
  if (valid.length < 2) return <div className="empty-state small">Sem histórico suficiente no período selecionado.</div>;

  const width = 100;
  const height = 32;
  const step = width / (valid.length - 1);
  const coords = valid.map((p, i) => [i * step, height - (p.first_pass_rate / 100) * height]);
  const path = coords.map(([x, y], i) => `${i === 0 ? 'M' : 'L'}${x.toFixed(2)},${y.toFixed(2)}`).join(' ');
  const last = valid[valid.length - 1];

  return (
    <svg className="sparkline" viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" role="img" aria-label={`Tendência de first pass rate, valor mais recente ${last.first_pass_rate}%`}>
      <path d={path} fill="none" stroke="var(--blue-500)" strokeWidth="2" vectorEffect="non-scaling-stroke" />
      {coords.map(([x, y], i) => (
        <circle key={i} cx={x} cy={y} r="1.6" fill="var(--blue-600)">
          <title>{`${valid[i].date}: ${valid[i].first_pass_rate}%`}</title>
        </circle>
      ))}
    </svg>
  );
}

function InsightCard({tone, title, insight}) {
  if (!insight) return null;
  return (
    <div className={`insight-card ${tone}`}>
      <span className="insight-kicker">{title}</span>
      <strong>{insight.label}</strong>
      <span className="insight-value">{insight.unit === '%' ? `${insight.value}%` : insight.value}</span>
      <p>{insight.message}</p>
    </div>
  );
}

export default function Metrics() {
  const [projects, setProjects] = useState([]);
  const [projectId, setProjectId] = useState('');
  const [days, setDays] = useState(30);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => { api.get('/kanban/projects').then(({data}) => setProjects(data)).catch(() => {}); }, []);

  function load() {
    setLoading(true);
    const params = {days};
    if (projectId) params.project_id = projectId;
    api.get('/kanban/metrics/summary', {params})
      .then(({data}) => { setData(data); setError(''); })
      .catch(() => setError('Não foi possível carregar as métricas.'))
      .finally(() => setLoading(false));
  }

  useEffect(load, [projectId, days]);

  // A saved goal only changes one number, so patch it in place instead of refetching (which
  // would blank the whole page while it reloads).
  function updateGoal(provider, dailyGoal) {
    setData(current => ({...current, token_usage: {...current.token_usage, providers: current.token_usage.providers.map(item => item.id === provider ? {...item, daily_goal: dailyGoal} : item)}}));
  }

  const maxDuration = data ? Math.max(1, ...AGENT_ORDER.map(a => data.avg_duration_by_agent?.[a] || 0)) : 1;
  const maxTokens = data ? Math.max(1, ...AGENT_ORDER.map(a => data.tokens_by_agent?.[a] || 0)) : 1;
  const insights = data?.insights;

  return (
    <section>
      <div className="page-heading">
        <div><span className="eyebrow blue">DESEMPENHO DO DEVTEAM</span><h1>Métricas</h1><p>Acompanhe tempo, custo e qualidade das entregas dos agentes.</p></div>
        <div className="metrics-filters">
          <label className="log-filter"><span>Projeto</span><select value={projectId} onChange={e => setProjectId(e.target.value)}><option value="">Todos os projetos</option>{projects.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}</select></label>
          <label className="log-filter"><span>Período</span><select value={days} onChange={e => setDays(Number(e.target.value))}>{[7, 30, 90].map(d => <option key={d} value={d}>{d} dias</option>)}</select></label>
          <button className="button secondary" onClick={load}>↻ Atualizar</button>
        </div>
      </div>

      {error && <div className="alert error">{error}</div>}
      {loading && <div className="empty-state">Calculando métricas…</div>}

      {!loading && data && (
        <>
          {insights?.note && <div className="alert" style={{background: 'var(--blue-50)', color: 'var(--blue-700)'}}>{insights.note}</div>}
          {(insights?.critical || insights?.stable) && (
            <div className="insight-grid">
              <InsightCard tone="critical" title="🔴 Ponto crítico" insight={insights.critical} />
              <InsightCard tone="stable" title="🟢 Ponto estável" insight={insights.stable} />
            </div>
          )}

          <div className="metrics-kpis">
            <div className="metric-kpi"><small>Tarefas concluídas</small><strong>{data.tasks_done}</strong></div>
            <div className="metric-kpi"><small>First pass rate</small><strong>{formatPercent(data.first_pass_rate)}</strong></div>
            <div className="metric-kpi"><small>Tentativas médias</small><strong>{data.avg_retries ?? '—'}</strong></div>
            <div className="metric-kpi"><small>Bugs pós-publicação</small><strong>{formatPercent(data.escaped_defect_rate)}</strong></div>
            <div className="metric-kpi"><small>Sucesso de deploy</small><strong>{formatPercent(data.deploy?.success_rate)}</strong></div>
          </div>

          <TokenUsageSection usage={data.token_usage} onGoalSaved={updateGoal} />

          <div className="metrics-grid">
            <div className="metrics-card">
              <h3>Tempo médio por agente</h3>
              {AGENT_ORDER.map(agent => (
                <BarRow key={agent} label={AGENT_LABELS[agent]} value={data.avg_duration_by_agent?.[agent] || 0} max={maxDuration} display={formatDuration(data.avg_duration_by_agent?.[agent])} color={AGENT_COLORS[agent]} />
              ))}
            </div>

            <div className="metrics-card">
              <h3>Tokens por agente</h3>
              {AGENT_ORDER.map(agent => (
                <BarRow key={agent} label={AGENT_LABELS[agent]} value={data.tokens_by_agent?.[agent] || 0} max={maxTokens} display={data.cost_by_agent?.[agent] != null ? `${formatNumber(data.tokens_by_agent?.[agent])} · ${formatCurrency(data.cost_by_agent[agent])}` : formatNumber(data.tokens_by_agent?.[agent])} color={AGENT_COLORS[agent]} />
              ))}
              <div className="metrics-foot">
                <span>Custo total <b>{formatCurrency(data.cost_total)}</b></span>
                <span>Custo/tarefa <b>{formatCurrency(data.cost_per_task)}</b></span>
                <span>Tokens/tarefa <b>{formatNumber(data.tokens_per_task)}</b></span>
              </div>
            </div>

            <div className="metrics-card">
              <h3>Tendência do first pass rate</h3>
              <Sparkline points={data.history || []} />
            </div>

            <div className="metrics-card">
              <h3>Deploy</h3>
              <div className="metrics-foot">
                <span>Total <b>{data.deploy?.total ?? 0}</b></span>
                <span>Falhas <b>{data.deploy?.failed ?? 0}</b></span>
                <span>Sucesso <b>{formatPercent(data.deploy?.success_rate)}</b></span>
              </div>
              {data.deploy?.failures_by_type && Object.keys(data.deploy.failures_by_type).length > 0 && (
                <ul className="metrics-list">
                  {Object.entries(data.deploy.failures_by_type).map(([type, count]) => <li key={type}><span>{type}</span><b>{count}</b></li>)}
                </ul>
              )}
            </div>

            <div className="metrics-card">
              <h3>Lead time vs. tempo de processamento</h3>
              <div className="metrics-foot">
                <span>Lead time médio <b>{formatDuration(data.lead_time_avg_seconds)}</b></span>
                <span>Processamento médio <b>{formatDuration(data.processing_time_avg_seconds)}</b></span>
              </div>
              <p className="metrics-hint">A diferença é o tempo que a tarefa passou esperando entre filas e etapas.</p>
            </div>
          </div>
        </>
      )}
    </section>
  );
}
