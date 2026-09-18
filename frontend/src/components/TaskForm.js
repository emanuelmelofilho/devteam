import React, {useState} from 'react';
import api from '../api';

// What the change is decides which part of the version DevOps raises when it publishes.
export const TASK_TYPES = {
  feature: {label: 'Nova funcionalidade', versionHint: 'Sobe o primeiro número da versão e zera os outros dois (ex.: 1.0.12 → 2.0.0).'},
  improvement: {label: 'Melhoria de funcionalidade', versionHint: 'Sobe o segundo número da versão e zera o último (ex.: 1.0.12 → 1.1.0).'},
  fix: {label: 'Correção de funcionalidade', versionHint: 'Sobe o terceiro número da versão (ex.: 1.0.12 → 1.0.13).'},
};

export default function TaskForm({projectId, onCreated}){
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [type, setType] = useState('');
  const [assignedAgent, setAssignedAgent] = useState('developer_worker');
  const [originTaskId, setOriginTaskId] = useState('');
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  async function submit(event){
    event.preventDefault();
    setSaving(true);
    setError('');
    try{
      const payload = {project_id: projectId, title, description, type, assigned_agent: assignedAgent};
      if (originTaskId) payload.origin_task_id = originTaskId;
      const response = await api.post('/kanban/tasks', payload);
      setTitle(''); setDescription(''); setType(''); setAssignedAgent('developer_worker'); setOriginTaskId('');
      setOpen(false);
      if (onCreated) onCreated(response.data);
    }catch(requestError){
      const validation = requestError.response?.data?.errors;
      const firstError = validation && Object.values(validation).flat()[0];
      setError(firstError || 'Não foi possível criar a tarefa.');
    }finally{ setSaving(false); }
  }

  function close(){
    if (saving) return;
    setOpen(false);
    setError('');
  }

  return <>
    <div className="task-create-row"><button className="button add-task" type="button" onClick={()=>setOpen(true)}>＋ Nova tarefa</button></div>
    {open && <div className="modal-backdrop" onMouseDown={event=>event.target === event.currentTarget && close()}>
      <form onSubmit={submit} className="task-modal task-create-modal" role="dialog" aria-modal="true" aria-labelledby={`new-task-title-${projectId}`}>
        <button className="modal-close" type="button" onClick={close} aria-label="Fechar">×</button>
        <span className="modal-kicker-label">NOVA TAREFA</span>
        <h2 id={`new-task-title-${projectId}`}>Criar nova tarefa</h2>
        <p className="modal-description">Descreva com detalhes o trabalho que deverá ser executado.</p>
        {error && <div className="alert error">{error}</div>}
        <label className="field"><span>Título</span><input placeholder="Título da tarefa" value={title} onChange={event=>setTitle(event.target.value)} required autoFocus /></label>
        <label className="field"><span>Tipo de alteração</span><select value={type} onChange={event=>setType(event.target.value)} required><option value="" disabled>Selecione o tipo</option>{Object.entries(TASK_TYPES).map(([id, item])=><option key={id} value={id}>{item.label}</option>)}</select>{type && <small>{TASK_TYPES[type].versionHint}</small>}</label>
        <label className="field"><span>Solicitação</span><textarea placeholder="Informe requisitos, contexto, comportamento esperado e critérios de aceite." value={description} onChange={event=>setDescription(event.target.value)} required /></label>
        <label className="field"><span>Quem vai executar</span><select value={assignedAgent} onChange={event=>setAssignedAgent(event.target.value)} required><option value="developer_worker">Desenvolvedor</option><option value="tester_worker">Testador</option><option value="devops_worker">DevOps</option></select></label>
        <label className="field"><span>Tarefa de origem (opcional)</span><input type="number" min="1" placeholder="Nº da tarefa que gerou este bug, se aplicável" value={originTaskId} onChange={event=>setOriginTaskId(event.target.value)} /></label>
        <footer className="modal-actions"><button className="button ghost" type="button" onClick={close} disabled={saving}>Cancelar</button><button className="button primary" type="submit" disabled={saving}>{saving ? 'Criando…' : 'Criar tarefa'}</button></footer>
      </form>
    </div>}
  </>;
}
