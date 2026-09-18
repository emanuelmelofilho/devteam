import React, {useEffect, useState} from 'react';
import api from '../api';
import LogDetail, {EVENT_LABELS, actorLabel} from './LogDetail';

export default function Logs(){
  const [projects, setProjects] = useState([]);
  const [logs, setLogs] = useState([]);
  const [scope, setScope] = useState('devteam');
  const [perPage, setPerPage] = useState(5);
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState({current_page:1,last_page:1,total:0,from:0,to:0});
  const [selectedLog, setSelectedLog] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(()=>{ api.get('/kanban/projects').then(({data})=>setProjects(data)); },[]);
  useEffect(()=>{
    setLoading(true);
    const params = {page, per_page:perPage};
    if (scope.startsWith('project:')) params.project_id = scope.split(':')[1];
    else params.scope = scope;
    api.get('/kanban/logs', {params}).then(({data})=>{
      setLogs(data.data);
      setPagination({current_page:data.current_page,last_page:data.last_page,total:data.total,from:data.from || 0,to:data.to || 0});
    }).finally(()=>setLoading(false));
  },[scope, perPage, page]);

  function changeScope(event){ setScope(event.target.value); setPage(1); setSelectedLog(null); }
  function changePerPage(event){ setPerPage(Number(event.target.value)); setPage(1); }

  return <section>
    <div className="page-heading"><div><span className="eyebrow blue">HISTÓRICO EXPLICADO</span><h1>Logs</h1><p>Consulte separadamente as alterações do Devteam e de cada projeto.</p></div><label className="log-filter"><span>Escopo</span><select value={scope} onChange={changeScope}><option value="devteam">Devteam</option><option value="projects">Todos os projetos</option>{projects.map(project=><option key={project.id} value={`project:${project.id}`}>{project.name}</option>)}</select></label></div>
    <div className="logs-card">
      <div className="logs-toolbar"><span>{pagination.total} {pagination.total === 1 ? 'registro' : 'registros'}</span><label>Exibir <select value={perPage} onChange={changePerPage}>{[5,10,20,50].map(size=><option key={size} value={size}>{size}</option>)}</select> por página</label></div>
      {loading ? <div className="empty-state">Carregando histórico…</div> : logs.length === 0 ? <div className="empty-state"><strong>Nenhum registro neste escopo</strong><span>As alterações aparecerão aqui automaticamente.</span></div> : <div className="logs-table-wrap"><table className="logs-table"><thead><tr><th>Evento</th><th>Descrição</th><th>Projeto</th><th>Responsável</th><th>Data</th><th></th></tr></thead><tbody>{logs.map(log=><tr key={log.id} tabIndex="0" onClick={()=>setSelectedLog(log)} onKeyDown={event=>event.key === 'Enter' && setSelectedLog(log)}><td><span className={`log-event ${log.event.replace('.', '-')}`}>{EVENT_LABELS[log.event] || log.event}</span></td><td><strong>{log.title}</strong><small>{log.details}</small></td><td>{log.project?.name || (log.scope === 'devteam' ? 'Devteam' : '—')}</td><td>{actorLabel(log)}</td><td>{new Date(log.created_at).toLocaleString('pt-BR')}</td><td><span className="log-open">›</span></td></tr>)}</tbody></table></div>}
      {pagination.last_page > 1 && <div className="logs-pagination"><span>Exibindo {pagination.from}–{pagination.to} de {pagination.total}</span><div><button disabled={page === 1} onClick={()=>setPage(current=>current-1)}>Anterior</button>{Array.from({length:pagination.last_page},(_,index)=>index+1).map(number=><button key={number} className={number === page ? 'active' : ''} onClick={()=>setPage(number)}>{number}</button>)}<button disabled={page === pagination.last_page} onClick={()=>setPage(current=>current+1)}>Próxima</button></div></div>}
    </div>
    {selectedLog && <LogDetail log={selectedLog} onClose={()=>setSelectedLog(null)} />}
  </section>;
}
