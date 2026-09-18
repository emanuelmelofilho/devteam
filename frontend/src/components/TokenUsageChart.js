import React, {useState} from 'react';
import api from '../api';

export const AGENTS = [
  {id: 'developer', label: 'Desenvolvedor'},
  {id: 'tester', label: 'Testador'},
  {id: 'devops', label: 'DevOps'},
];
// Color follows the agent, never its rank: the values live in styles.css as --agent-*.
export const AGENT_COLORS = {developer: 'var(--agent-developer)', tester: 'var(--agent-tester)', devops: 'var(--agent-devops)'};

const W = 640;
const H = 240;
const M = {left: 52, right: 12, top: 16, bottom: 28};
const PLOT_W = W - M.left - M.right;
const PLOT_H = H - M.top - M.bottom;
const GAP = 2;
const MAX_BAR = 24;
const RADIUS = 4;

const compact = new Intl.NumberFormat('pt-BR', {notation: 'compact', maximumFractionDigits: 1});
const full = new Intl.NumberFormat('pt-BR');

function formatDay(iso, withYear = false) {
  const [year, month, day] = iso.split('-');
  return withYear ? `${day}/${month}/${year}` : `${day}/${month}`;
}

// Round tick values (1 / 2 / 2.5 / 5 x 10^n) so the axis reads 0, 250 mil, 500 mil...
function niceScale(max) {
  if (max <= 0) return {max: 1, ticks: [0, 1]};
  const rough = max / 4;
  const power = 10 ** Math.floor(Math.log10(rough));
  const step = [1, 2, 2.5, 5, 10].map(multiplier => multiplier * power).find(candidate => candidate >= rough);
  const top = Math.ceil(max / step) * step;
  const ticks = [];
  for (let value = 0; value <= top + step / 1000; value += step) ticks.push(value);
  return {max: top, ticks};
}

// Column segment with a rounded data-end and a square baseline.
function segmentPath(x, y, width, height, radius) {
  const r = Math.min(radius, width / 2, height);
  if (r <= 0) return `M${x},${y + height}V${y}H${x + width}V${y + height}Z`;
  return `M${x},${y + height}V${y + r}Q${x},${y} ${x + r},${y}H${x + width - r}Q${x + width},${y} ${x + width},${y + r}V${y + height}Z`;
}

function TokenChart({provider, days}) {
  const [active, setActive] = useState(null);
  const [asTable, setAsTable] = useState(false);
  const {series, total, baseline, daily_goal: goal} = provider;

  const perDay = days.map((_, index) => AGENTS.map(agent => series[agent.id][index]));
  const dayTotals = perDay.map(values => values.reduce((sum, value) => sum + value, 0));
  const reference = goal ?? baseline;
  const referenceLabel = goal ? 'Meta diária' : 'Média dos dias com uso';
  const referenceShort = goal ? 'Meta' : 'Média';

  const scale = niceScale(Math.max(0, ...dayTotals, reference || 0));
  const yOf = value => M.top + PLOT_H - (value / scale.max) * PLOT_H;
  const slot = PLOT_W / days.length;
  const barWidth = Math.max(2, Math.min(MAX_BAR, slot * 0.7));
  const xOf = index => M.left + slot * index + (slot - barWidth) / 2;
  const tickEvery = Math.max(1, Math.ceil(days.length / 6));
  const xTicks = [];
  for (let index = days.length - 1; index >= 0; index -= tickEvery) xTicks.unshift(index);

  function pointerIndex(event) {
    const rect = event.currentTarget.getBoundingClientRect();
    const x = (event.clientX - rect.left) * (W / rect.width);
    if (x < M.left || x > W - M.right) return null;
    return Math.min(days.length - 1, Math.floor((x - M.left) / slot));
  }

  function onKeyDown(event) {
    const move = {ArrowLeft: -1, ArrowRight: 1}[event.key];
    if (move) {
      event.preventDefault();
      setActive(current => Math.min(days.length - 1, Math.max(0, (current ?? days.length) + move)));
    } else if (event.key === 'Home' || event.key === 'End') {
      event.preventDefault();
      setActive(event.key === 'Home' ? 0 : days.length - 1);
    } else if (event.key === 'Escape') setActive(null);
  }

  const referenceY = reference ? yOf(reference) : null;
  const labelBelow = referenceY !== null && referenceY - 8 < M.top + 8;
  const referenceText = reference ? `${referenceShort} ${compact.format(reference)}/dia` : '';
  const referenceTextWidth = referenceText.length * 6 + 10;

  return (
    <div className="metrics-card token-card">
      <div className="token-card-head">
        <div>
          <h3>{provider.label}</h3>
          <p className="token-summary"><b>{full.format(total)}</b> tokens no período{baseline ? <> · média de <b>{compact.format(baseline)}</b> por dia com uso</> : null}</p>
        </div>
        {total > 0 && <button type="button" className="token-toggle" onClick={() => setAsTable(current => !current)}>{asTable ? 'Ver gráfico' : 'Ver tabela'}</button>}
      </div>

      {total === 0 && <div className="empty-state small">Sem uso de tokens neste provedor no período selecionado.</div>}

      {total > 0 && !asTable && (
        <div className="token-legend" aria-label="Legenda">
          {AGENTS.map(agent => <span key={agent.id}><i className="token-swatch" style={{background: AGENT_COLORS[agent.id]}} />{agent.label}</span>)}
          {reference && <span><i className="token-dash" />{referenceLabel}</span>}
        </div>
      )}

      {total > 0 && !asTable && (
        <div className="token-plot" tabIndex={0} role="group" aria-label={`Uso diário de tokens por agente — ${provider.label}. Use as setas para percorrer os dias.`}
          onKeyDown={onKeyDown} onFocus={() => setActive(current => current ?? days.length - 1)} onBlur={() => setActive(null)}>
          <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={`Gráfico de colunas: ${full.format(total)} tokens no período. Os mesmos valores estão na tabela.`}
            onPointerMove={event => setActive(pointerIndex(event))} onPointerLeave={() => setActive(null)}>
            {scale.ticks.map(value => (
              <g key={value}>
                <line x1={M.left} x2={W - M.right} y1={yOf(value)} y2={yOf(value)} className={value === 0 ? 'token-axis' : 'token-grid'} />
                <text x={M.left - 8} y={yOf(value) + 3.5} textAnchor="end" className="token-tick">{compact.format(value)}</text>
              </g>
            ))}
            {xTicks.map(index => <text key={index} x={M.left + slot * (index + 0.5)} y={H - 8} textAnchor="middle" className="token-tick">{formatDay(days[index])}</text>)}

            {active !== null && <rect x={M.left + slot * active} y={M.top} width={slot} height={PLOT_H} className="token-band" />}

            {perDay.map((values, index) => {
              let base = M.top + PLOT_H;
              const top = values.reduce((last, value, position) => (value > 0 ? position : last), -1);
              return (
                <g key={days[index]}>
                  {values.map((value, position) => {
                    if (!value) return null;
                    const height = (value / scale.max) * PLOT_H;
                    const y = base - height;
                    const stacked = base !== M.top + PLOT_H;
                    base = y;
                    return <path key={AGENTS[position].id} d={segmentPath(xOf(index), y, barWidth, Math.max(1, height - (stacked ? GAP : 0)), position === top ? RADIUS : 0)} style={{fill: AGENT_COLORS[AGENTS[position].id]}} />;
                  })}
                </g>
              );
            })}

            {referenceY !== null && (
              <g>
                <line x1={M.left} x2={W - M.right} y1={referenceY} y2={referenceY} className="token-reference" />
                <rect x={W - M.right - referenceTextWidth - 2} y={labelBelow ? referenceY + 4 : referenceY - 20} width={referenceTextWidth} height={16} rx={4} className="token-reference-pill" />
                <text x={W - M.right - 7} y={labelBelow ? referenceY + 16 : referenceY - 8} textAnchor="end" className="token-reference-label">{referenceText}</text>
              </g>
            )}
          </svg>

          {active !== null && (
            <div className="token-tooltip" style={{left: `${((M.left + slot * (active + 0.5)) / W) * 100}%`, transform: active > days.length / 2 ? 'translateX(calc(-100% - 10px))' : 'translateX(10px)'}} role="status">
              <span className="token-tooltip-day">{formatDay(days[active], true)}</span>
              {AGENTS.map((agent, position) => (
                <span key={agent.id} className="token-tooltip-row"><i className="token-key" style={{background: AGENT_COLORS[agent.id]}} /><b>{full.format(perDay[active][position])}</b><small>{agent.label}</small></span>
              ))}
              <span className="token-tooltip-row total"><b>{full.format(dayTotals[active])}</b><small>Total do dia</small></span>
              {reference && <span className="token-tooltip-row"><i className="token-key ref" /><b>{full.format(reference)}</b><small>{referenceShort}</small></span>}
            </div>
          )}
        </div>
      )}

      {total > 0 && asTable && (
        <div className="token-table-wrap">
          <table className="token-table">
            <thead><tr><th>Dia</th>{AGENTS.map(agent => <th key={agent.id}>{agent.label}</th>)}<th>Total</th></tr></thead>
            <tbody>
              {days.map((day, index) => dayTotals[index] > 0 && (
                <tr key={day}><td>{formatDay(day, true)}</td>{perDay[index].map((value, position) => <td key={AGENTS[position].id}>{full.format(value)}</td>)}<td><b>{full.format(dayTotals[index])}</b></td></tr>
              )).reverse()}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

function GoalsForm({providers, onSaved}) {
  const [edits, setEdits] = useState({});
  const [message, setMessage] = useState(null);
  const [saving, setSaving] = useState(false);

  const valueOf = provider => edits[provider.id] ?? provider.daily_goal ?? '';

  async function save(event) {
    event.preventDefault();
    const changes = [];
    for (const provider of providers) {
      const raw = String(valueOf(provider)).trim();
      const parsed = raw === '' ? null : Number(raw);
      if (parsed !== null && (!Number.isInteger(parsed) || parsed < 1)) {
        setMessage({type: 'error', text: `Meta de ${provider.label}: informe um número inteiro maior que zero.`});
        return;
      }
      if (parsed !== (provider.daily_goal ?? null)) changes.push([provider.id, parsed]);
    }
    if (changes.length === 0) { setMessage({type: '', text: 'Nenhuma alteração para salvar.'}); return; }

    setSaving(true); setMessage(null);
    try {
      for (const [provider, value] of changes) {
        await api.put(`/kanban/token-goals/${provider}`, {daily_tokens: value});
        onSaved(provider, value);
      }
      setEdits({});
      setMessage({type: 'success', text: 'Metas salvas.'});
    } catch {
      setMessage({type: 'error', text: 'Não foi possível salvar as metas.'});
    } finally { setSaving(false); }
  }

  return (
    <form className="token-goals" onSubmit={save}>
      {providers.map(provider => (
        <label key={provider.id} className="log-filter"><span>Meta diária · {provider.label}</span>
          <input type="number" min="1" step="1" inputMode="numeric" placeholder="Ex.: 500000" value={valueOf(provider)} onChange={event => setEdits({...edits, [provider.id]: event.target.value})} />
        </label>
      ))}
      <button className="button secondary" type="submit" disabled={saving}>{saving ? 'Salvando…' : 'Salvar metas'}</button>
      {message && <div className={`alert ${message.type}`}>{message.text}</div>}
    </form>
  );
}

export default function TokenUsageSection({usage, onGoalSaved}) {
  if (!usage?.providers?.length || !usage.days?.length) return null;

  return (
    <div className="token-usage">
      <div className="token-usage-head">
        <h2>Uso de tokens por agente</h2>
        <p>Tokens consumidos por dia em cada provedor. A linha tracejada é a meta diária ideal; sem meta, ela mostra a média dos dias com uso.</p>
      </div>
      <GoalsForm providers={usage.providers} onSaved={onGoalSaved} />
      <div className="token-grid">
        {usage.providers.map(provider => <TokenChart key={provider.id} provider={provider} days={usage.days} />)}
      </div>
    </div>
  );
}
