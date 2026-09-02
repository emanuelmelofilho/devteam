import React, {useEffect, useState} from 'react';
import api from '../api';
import TaskForm from './TaskForm';
import ProjectTechnologyLogos from './ProjectTechnologyLogos';
import ProjectLogo from './ProjectLogo';

const LANES = [
  {id:'todo', label:'A fazer', tone:'slate'},
  {id:'in_progress', label:'Em andamento', tone:'blue'},
  {id:'review', label:'Em revisão', tone:'violet'},
  {id:'publishing', label:'Em publicação', tone:'orange'},
  {id:'published', label:'Publicado', tone:'teal'},
];

const AGENT_LABELS = {developer_worker:'Desenvolvedor',tester_worker:'Testador',devops_worker:'DevOps'};
const ACTIVE_LANES = ['in_progress','review','publishing'];
const AGENTS_BY_LANE = {
  todo: ['developer_worker'],
  in_progress: ['developer_worker'],
  review: ['tester_worker','developer_worker'],
  publishing: ['devops_worker'],
};

export default function KanbanBoard(){
  const [projects, setProjects] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [selectedTask, setSelectedTask] = useState(null);
  const [archiveOpen, setArchiveOpen] = useState({});
  const [retrying, setRetrying] = useState(false);
  const [modalNotice, setModalNotice] = useState(null);
  const [expandedProjects, setExpandedProjects] = useState({});

  function loadProjects(silent = false){
    if (!silent) setLoading(true);
    return api.get('/kanban/projects').then(r=>{
      setProjects(r.data);
      setExpandedProjects(current=>Object.keys(current).length > 0 ? current : (r.data[0] ? {[r.data[0].id]:true} : {}));
      setError('');
    })
      .catch(()=>setError('Não foi possível carregar os projetos.'))
      .finally(()=>setLoading(false));
  }

  useEffect(()=>{
    // refresh every 10s
    const id = setInterval(()=>loadProjects(true), 10000);
    return ()=>clearInterval(id);
  },[]);
  useEffect(()=>{
    loadProjects();
  },[]);

  function onDragStart(e, taskId){
    e.dataTransfer.setData('text/plain', taskId);
  }

  function toggleProject(projectId){
    setExpandedProjects(current=>({...current, [projectId]:!current[projectId]}));
  }

  function onDrop(e, projectId, lane){
    e.preventDefault();
    const taskId = e.dataTransfer.getData('text/plain');
    if (!taskId) return;
    const task = projects.flatMap(project=>project.tasks || []).find(item=>String(item.id) === String(taskId));
    const payload = {lane};
    if (lane === 'review' && !task?.work_summary) {
      const summary = window.prompt('Inclua um breve resumo do que foi realizado:');
      if (!summary) return;
      payload.work_summary = summary;
    }
    if (lane === 'published' && !task?.version) {
      const version = window.prompt('Informe a versão publicada (ex.: 1.2.3):');
      if (!version) return;
      payload.version = version;
    }
    api.patch(`/kanban/tasks/${taskId}`, payload).then(()=>loadProjects(true))
      .catch(error=>setError(error.response?.data?.message || 'Não foi possível mover a tarefa.'));
  }

  function onDragOver(e){ e.preventDefault(); }

  function isArchived(task){
    if (task.lane === 'done') return true;
    if (task.lane !== 'published' || !task.updated_at) return false;
    const today = new Date(); today.setHours(0,0,0,0);
    return new Date(task.updated_at) < today;
  }

  function openTask(task){
    setModalNotice(null);
    setSelectedTask(task);
    api.get(`/kanban/tasks/${task.id}`).then(response=>setSelectedTask(response.data)).catch(()=>{});
  }

  async function deleteTask(){
    if (!selectedTask || selectedTask.lane !== 'todo') return;
    if (!window.confirm(`Excluir a tarefa “${selectedTask.title}”?`)) return;
    try {
      await api.delete(`/kanban/tasks/${selectedTask.id}`);
      setSelectedTask(null);
      loadProjects(true);
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'Não foi possível excluir a tarefa.');
    }
  }

  async function assignAgent(agent){
    if (!selectedTask || !agent) return;
    try {
      const response = await api.patch(`/kanban/tasks/${selectedTask.id}`, {assigned_agent:agent});
      setSelectedTask(current=>({...current,...response.data}));
      setError('');
      setModalNotice({type:'success', text:'Agente designado e tarefa enviada para a fila.'});
      loadProjects(true);
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'Não foi possível designar o agente.');
      setModalNotice({type:'error', text:requestError.response?.data?.message || 'Não foi possível designar o agente.'});
    }
  }

  async function retryTask(){
    if (!selectedTask) return;
    setRetrying(true);
    try {
      const response = await api.post(`/kanban/tasks/${selectedTask.id}/retry`);
      setSelectedTask(current=>({...current,...response.data.task}));
      setError('');
      setModalNotice({type:'success', text:'Tarefa reenfileirada. O worker iniciará o processamento em instantes.'});
      loadProjects(true);
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'Não foi possível reenfileirar a tarefa.');
      setModalNotice({type:'error', text:requestError.response?.data?.message || 'Não foi possível reenfileirar a tarefa.'});
    } finally { setRetrying(false); }
  }

  return (
    <section>
      <div className="page-heading">
        <div><span className="eyebrow blue">VISÃO DO TRABALHO</span><h1>Quadro de projetos</h1><p>Acompanhe o progresso e mova tarefas entre as etapas.</p></div>
        <button className="button secondary" onClick={()=>loadProjects()}>↻ Atualizar</button>
      </div>
      {error && <div className="alert error">{error}</div>}
      {loading && <div className="empty-state">Carregando seus projetos…</div>}
      {!loading && projects.length === 0 && <div className="empty-state"><strong>Nenhum projeto encontrado</strong><span>Crie um projeto pela API para começar.</span></div>}
      {projects.map(p=> (
        <article key={p.id} className="project-card">
          <button className="project-header project-accordion-trigger" type="button" onClick={()=>toggleProject(p.id)} aria-expanded={!!expandedProjects[p.id]} aria-controls={`project-content-${p.id}`}><div className="project-heading-identity"><ProjectLogo project={p} size="large" /><div><div className="project-name-with-logos"><h2>{p.name}</h2><ProjectTechnologyLogos project={p} /></div><p>{p.description || 'Projeto sem descrição'}</p></div></div><span className="project-accordion-meta"><span className="task-total">{(p.tasks || []).length} tarefas</span><i>{expandedProjects[p.id] ? '⌃' : '⌄'}</i></span></button>
          {expandedProjects[p.id] && <div id={`project-content-${p.id}`} className="project-accordion-content">
          <TaskForm projectId={p.id} onCreated={()=>loadProjects(true)} />
          <div className="board-grid">
            {LANES.map(lane=> {
              const tasks = (p.tasks || []).filter(t=>t.lane===lane.id && !isArchived(t));
              return (
              <div className="lane" key={lane.id} onDrop={e=>onDrop(e,p.id,lane.id)} onDragOver={onDragOver}>
                <div className="lane-title"><span className={`status-dot ${lane.tone}`}></span><h3>{lane.label}</h3><span>{tasks.length}</span></div>
                <div className="task-list">
                {tasks.map(t=> (
                  <div className="task-card" key={t.id} draggable onDragStart={e=>onDragStart(e,t.id)} onClick={()=>openTask(t)} role="button" tabIndex="0" onKeyDown={e=>e.key === 'Enter' && openTask(t)}>
                    <div className="task-card-top"><span className="task-id">TASK-{t.id}</span>{ACTIVE_LANES.includes(t.lane) && <span className="execution-loader" title="Atividade em execução"><i></i> Executando</span>}</div>
                    <h4>{t.title}</h4>{t.description && <p>{t.description}</p>}
                    {t.work_summary && <div className="work-summary"><strong>Resumo</strong>{t.work_summary}</div>}
                    <div className="task-meta"><span>{AGENT_LABELS[t.assigned_agent] || 'Sem agente'}</span>{t.branch && <code>{t.branch}</code>}{t.version && <b>v{t.version}</b>}</div>
                  </div>
                ))}
                {tasks.length === 0 && <div className="lane-empty">Solte uma tarefa aqui</div>}
                </div>
              </div>
            )})}
          </div>
          {(() => {
            const archived = (p.tasks || []).filter(isArchived);
            return <div className="archive-section">
              <button className="archive-toggle" onClick={()=>setArchiveOpen(current=>({...current,[p.id]:!current[p.id]}))} aria-expanded={!!archiveOpen[p.id]}>
                <span><i>✓</i> Finalizadas</span><span>{archived.length} {archived.length === 1 ? 'tarefa' : 'tarefas'} <b>{archiveOpen[p.id] ? '⌃' : '⌄'}</b></span>
              </button>
              {archiveOpen[p.id] && <div className="archive-list">{archived.length === 0 ? <div className="archive-empty">Nenhuma tarefa finalizada anteriormente.</div> : archived.map(task=><button key={task.id} className="archive-item" onClick={()=>openTask(task)}><span>✓</span><div><strong>{task.title}</strong><small>{task.version ? `Versão ${task.version}` : 'Finalizada'}</small></div><time>{new Date(task.updated_at).toLocaleDateString('pt-BR')}</time></button>)}</div>}
            </div>;
          })()}
          </div>}
        </article>
      ))}
      {selectedTask && <div className="modal-backdrop" onMouseDown={e=>e.target === e.currentTarget && setSelectedTask(null)}>
        <section className="task-modal" role="dialog" aria-modal="true" aria-labelledby="task-modal-title">
          <button className="modal-close" onClick={()=>setSelectedTask(null)} aria-label="Fechar">×</button>
          <div className="modal-kicker"><span className="task-id">TASK-{selectedTask.id}</span><span className={`modal-status ${selectedTask.lane}`}>{LANES.find(l=>l.id === selectedTask.lane)?.label || 'Finalizada'}</span></div>
          <h2 id="task-modal-title">{selectedTask.title}</h2>
          <p className="modal-description">{selectedTask.description || 'Sem descrição.'}</p>
          {modalNotice && <div className={`alert ${modalNotice.type}`}>{modalNotice.text}</div>}
          <div className="detail-grid">
            <div><small>Agente responsável</small>{AGENTS_BY_LANE[selectedTask.lane] ? <select className="agent-select" value={selectedTask.assigned_agent || ''} onChange={event=>assignAgent(event.target.value)}><option value="" disabled>Selecione um agente</option>{AGENTS_BY_LANE[selectedTask.lane].map(agent=><option key={agent} value={agent}>{AGENT_LABELS[agent]}</option>)}</select> : <strong>{AGENT_LABELS[selectedTask.assigned_agent] || 'Não designado'}</strong>}</div>
            <div><small>Branch</small><strong>{selectedTask.branch || '—'}</strong></div>
            <div><small>Versão</small><strong>{selectedTask.version ? `v${selectedTask.version}` : '—'}</strong></div>
            <div><small>Atualizada em</small><strong>{selectedTask.updated_at ? new Date(selectedTask.updated_at).toLocaleString('pt-BR') : '—'}</strong></div>
          </div>
          {selectedTask.work_summary && <div className="modal-summary"><small>Resumo do trabalho</small><p>{selectedTask.work_summary}</p></div>}
          {selectedTask.comments?.length > 0 && <div className="modal-comments"><small>Histórico</small>{selectedTask.comments.map(comment=><p key={comment.id}>{comment.comment}</p>)}</div>}
          <footer className="modal-actions">{selectedTask.lane === 'todo' ? <button className="button danger" onClick={deleteTask}>Excluir tarefa</button> : <span className="delete-hint">A exclusão só está disponível em “A fazer”.</span>}{['todo','in_progress','review','publishing'].includes(selectedTask.lane) && <button className="button primary" onClick={retryTask} disabled={retrying}>{retrying ? 'Reenfileirando…' : '↻ Reenfileirar'}</button>}<button className="button secondary" onClick={()=>setSelectedTask(null)}>Fechar</button></footer>
        </section>
      </div>}
    </section>
  );
}
