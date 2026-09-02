import React, {useEffect, useState} from 'react';
import api from '../api';

const EVENT_LABELS = {'devteam.updated':'Devteam atualizado','project.created':'Projeto criado','project.updated':'Projeto atualizado','project.deleted':'Projeto excluído','task.created':'Atividade criada','task.started':'Iniciada','task.moved':'Etapa alterada','task.finished':'Finalizada'};

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
      {loading ? <div className="empty-state">Carregando histórico…</div> : logs.length === 0 ? <div className="empty-state"><strong>Nenhum registro neste escopo</strong><span>As alterações aparecerão aqui automaticamente.</span></div> : <div className="logs-table-wrap"><table className="logs-table"><thead><tr><th>Evento</th><th>Descrição</th><th>Projeto</th><th>Responsável</th><th>Data</th><th></th></tr></thead><tbody>{logs.map(log=><tr key={log.id} tabIndex="0" onClick={()=>setSelectedLog(log)} onKeyDown={event=>event.key === 'Enter' && setSelectedLog(log)}><td><span className={`log-event ${log.event.replace('.', '-')}`}>{EVENT_LABELS[log.event] || log.event}</span></td><td><strong>{log.title}</strong><small>{log.details}</small></td><td>{log.project?.name || (log.scope === 'devteam' ? 'Devteam' : '—')}</td><td>{log.user?.name || 'Sistema'}</td><td>{new Date(log.created_at).toLocaleString('pt-BR')}</td><td><span className="log-open">›</span></td></tr>)}</tbody></table></div>}
      {pagination.last_page > 1 && <div className="logs-pagination"><span>Exibindo {pagination.from}–{pagination.to} de {pagination.total}</span><div><button disabled={page === 1} onClick={()=>setPage(current=>current-1)}>Anterior</button>{Array.from({length:pagination.last_page},(_,index)=>index+1).map(number=><button key={number} className={number === page ? 'active' : ''} onClick={()=>setPage(number)}>{number}</button>)}<button disabled={page === pagination.last_page} onClick={()=>setPage(current=>current+1)}>Próxima</button></div></div>}
    </div>
    {selectedLog && <div className="modal-backdrop" onMouseDown={event=>event.target === event.currentTarget && setSelectedLog(null)}><section className="log-detail-modal" role="dialog" aria-modal="true" aria-labelledby="log-detail-title"><button className="modal-close" onClick={()=>setSelectedLog(null)} aria-label="Fechar">×</button><span className={`log-event ${selectedLog.event.replace('.', '-')}`}>{EVENT_LABELS[selectedLog.event] || selectedLog.event}</span><h2 id="log-detail-title">{selectedLog.title}</h2><p className="log-detail-description">{selectedLog.details}</p><div className="log-detail-context"><div><small>Escopo</small><strong>{selectedLog.project?.name || 'Devteam'}</strong></div><div><small>Atividade</small><strong>{selectedLog.task?.title || '—'}</strong></div><div><small>Responsável</small><strong>{selectedLog.user?.name || 'Sistema'}</strong></div><div><small>Data</small><strong>{new Date(selectedLog.created_at).toLocaleString('pt-BR')}</strong></div></div><div className="log-git-data"><div><small>Branch</small><code>{selectedLog.metadata?.branch || 'Não informada'}</code></div><div><small>Hash do commit</small><code>{selectedLog.metadata?.commit_hash || 'Não informado'}</code></div></div><footer className="modal-actions"><button className="button secondary" onClick={()=>setSelectedLog(null)}>Fechar</button></footer></section></div>}
  </section>;
}
