import React, {useEffect, useRef, useState} from 'react';
import api from '../api';
import ProjectTechnologyLogos, {TECHNOLOGIES, logoUrl} from './ProjectTechnologyLogos';
import ProjectLogo from './ProjectLogo';

const EMPTY_FORM = {name:'', directory:'', description:'', frontend:'', backend:'', database:'', logo:null, logo_url:null, remove_logo:false};
const CATEGORY_DETAILS = {
  frontend: {icon:'▣', description:'Tecnologias para a interface e experiência do usuário'},
  backend: {icon:'⌸', description:'Tecnologias para o servidor, lógica de negócio e APIs'},
  database: {icon:'◉', description:'Sistemas de gerenciamento e armazenamento de dados'},
};

function TechnologyPicker({label, type, value, onChange}){
  const details = CATEGORY_DETAILS[type];
  return <fieldset className={`technology-picker ${type}`}><legend className="sr-only">{label}</legend><div className="technology-category"><i>{details.icon}</i><div><strong>{label}</strong><p>{details.description}</p></div></div><div className="technology-options">{TECHNOLOGIES[type].map(technology=><button key={technology.id} type="button" className={value === technology.id ? 'selected' : ''} onClick={()=>onChange(value === technology.id ? '' : technology.id)} aria-pressed={value === technology.id}><span className="technology-logo"><img src={logoUrl(technology.icon)} alt="" /></span><strong>{technology.id}</strong><small>{value === technology.id ? 'Selecionado' : 'Selecionar'}</small></button>)}</div></fieldset>;
}

export default function Projects(){
  const [projects, setProjects] = useState([]);
  const [form, setForm] = useState(EMPTY_FORM);
  const directoryPicker = useRef(null);
  const logoPicker = useRef(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState(null);
  const [editingId, setEditingId] = useState(null);
  const [deletingId, setDeletingId] = useState(null);
  const [modalOpen, setModalOpen] = useState(false);

  function loadProjects(){
    setLoading(true);
    api.get('/kanban/projects').then(res=>setProjects(res.data))
      .catch(()=>setMessage({type:'error', text:'Não foi possível carregar os projetos.'}))
      .finally(()=>setLoading(false));
  }

  useEffect(loadProjects, []);

  async function submit(event){
    event.preventDefault(); setSaving(true); setMessage(null);
    const wasEditing = !!editingId;
    try{
      const payload = new FormData();
      ['name','directory','description','frontend','backend','database'].forEach(field=>payload.append(field, form[field] || ''));
      if (form.logo) payload.append('logo', form.logo);
      if (form.remove_logo) payload.append('remove_logo', '1');
      if (editingId) payload.append('_method', 'PATCH');
      const response = editingId
        ? await api.post(`/kanban/projects/${editingId}`, payload)
        : await api.post('/kanban/projects', payload);
      setProjects(current=>editingId
        ? current.map(project=>project.id === editingId ? {...project,...response.data} : project)
        : [...current, {...response.data, tasks: response.data.tasks || []}]);
      setForm(EMPTY_FORM);
      setEditingId(null);
      setModalOpen(false);
      setMessage({type:'success', text:wasEditing ? 'Projeto atualizado com sucesso.' : 'Projeto cadastrado com sucesso.'});
    }catch(error){
      const validation = error.response?.data?.errors;
      const firstError = validation && Object.values(validation).flat()[0];
      setMessage({type:'error', text:firstError || `Não foi possível ${editingId ? 'atualizar' : 'cadastrar'} o projeto.`});
    }finally{ setSaving(false); }
  }

  function startEditing(project){
    setEditingId(project.id);
    setForm({name:project.name || '', directory:project.directory || '', description:project.description || '', frontend:project.frontend || '', backend:project.backend || '', database:project.database || '', logo:null, logo_url:project.logo_url || null, remove_logo:false});
    setMessage(null);
    setModalOpen(true);
  }

  function closeModal(){
    if (saving) return;
    setModalOpen(false);
    setEditingId(null);
    setForm(EMPTY_FORM);
    setMessage(null);
  }

  function startCreating(){
    setEditingId(null);
    setForm(EMPTY_FORM);
    setMessage(null);
    setModalOpen(true);
  }

  function selectDirectory(event){
    const firstFile = event.target.files?.[0];
    if (!firstFile) return;
    const relativePath = firstFile.webkitRelativePath || firstFile.name;
    setForm(current=>({...current, directory:relativePath.split('/')[0]}));
    event.target.value = '';
  }

  function selectLogo(event){
    const file = event.target.files?.[0];
    if (!file) return;
    setForm(current=>({...current, logo:file, logo_url:URL.createObjectURL(file), remove_logo:false}));
  }

  function removeLogo(){
    setForm(current=>({...current, logo:null, logo_url:null, remove_logo:true}));
    if (logoPicker.current) logoPicker.current.value = '';
  }

  async function deleteProject(project){
    const taskCount = (project.tasks || []).length;
    const taskWarning = taskCount > 0
      ? ` e ${taskCount} ${taskCount === 1 ? 'tarefa vinculada' : 'tarefas vinculadas'}`
      : '';

    if (!window.confirm(`Excluir o projeto “${project.name}”${taskWarning}? Esta ação não pode ser desfeita.`)) return;

    setDeletingId(project.id);
    setMessage(null);

    try{
      await api.delete(`/kanban/projects/${project.id}`);
      setProjects(current=>current.filter(item=>item.id !== project.id));
      if (editingId === project.id) closeModal();
      setMessage({type:'success', text:'Projeto excluído com sucesso.'});
    }catch(error){
      setMessage({type:'error', text:error.response?.data?.message || 'Não foi possível excluir o projeto.'});
    }finally{
      setDeletingId(null);
    }
  }

  return <section>
    <div className="page-heading"><div><span className="eyebrow blue">ORGANIZAÇÃO</span><h1>Projetos</h1><p>Cadastre os repositórios que serão acompanhados pelo time.</p></div><button className="button primary" onClick={startCreating}>＋ Novo projeto</button></div>
    {message && !modalOpen && <div className={`alert ${message.type}`}>{message.text}</div>}
    <div className="projects-layout">
      <div className="project-list-panel">
        <div className="list-heading"><div><h2>Projetos cadastrados</h2><p>{projects.length} {projects.length === 1 ? 'projeto' : 'projetos'} no workspace</p></div><button className="button secondary" onClick={loadProjects}>↻ Atualizar</button></div>
        {loading ? <div className="empty-state">Carregando projetos…</div> : projects.length === 0 ? <div className="empty-state"><strong>Nenhum projeto cadastrado</strong><span>Cadastre o primeiro projeto para começar.</span></div> :
          <div className="project-list">{projects.map(project=><article className={`project-list-item ${editingId === project.id ? 'editing' : ''}`} key={project.id}><ProjectLogo project={project} /><div><div className="project-name-with-logos"><h3>{project.name}</h3><ProjectTechnologyLogos project={project} /></div><code title={project.directory}>{project.directory || 'Diretório não informado'}</code>{project.description && <p>{project.description}</p>}</div><div className="project-item-actions"><span className="task-total">{(project.tasks || []).length} tarefas</span><div className="project-action-buttons"><button className="project-icon-button edit-project-button" type="button" onClick={()=>startEditing(project)} disabled={deletingId === project.id} aria-label="Alterar projeto" data-tooltip="Alterar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l10.5-10.5a2.8 2.8 0 0 0-4-4L4 16v4Zm9-12 3 3" /></svg></button><button className={`project-icon-button delete-project-button ${deletingId === project.id ? 'loading' : ''}`} type="button" onClick={()=>deleteProject(project)} disabled={deletingId === project.id} aria-label="Excluir projeto" data-tooltip={deletingId === project.id ? 'Excluindo' : 'Excluir'}><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m3 0-1 13H7L6 7m4 4v5m4-5v5" /></svg></button></div></div></article>)}</div>}
      </div>
    </div>
    {modalOpen && <div className="modal-backdrop" onMouseDown={event=>event.target === event.currentTarget && closeModal()}>
      <form className="project-modal" onSubmit={submit} role="dialog" aria-modal="true" aria-labelledby="project-modal-title">
        <button className="modal-close" type="button" onClick={closeModal} aria-label="Fechar">×</button>
        <div className="settings-icon project-icon">P</div><div className="settings-content">
          <h2 id="project-modal-title">{editingId ? 'Editar projeto' : 'Novo projeto'}</h2><p className="muted">{editingId ? 'Atualize as informações e salve as alterações.' : 'Informe como o projeto será identificado e onde seus arquivos estão.'}</p>
          {message && <div className={`alert ${message.type}`}>{message.text}</div>}
          <div className="project-logo-field"><div className="project-logo-preview">{form.logo_url ? <img src={form.logo_url} alt="Pré-visualização da logo" /> : <span>{(form.name || 'P').charAt(0).toUpperCase()}</span>}</div><div><strong>Logo do projeto</strong><p>PNG, JPG, WebP ou SVG de até 2 MB.</p><div><button className="button secondary" type="button" onClick={()=>logoPicker.current?.click()}>{form.logo_url ? 'Substituir logo' : 'Selecionar logo'}</button>{form.logo_url && <button className="button ghost logo-remove" type="button" onClick={removeLogo}>Remover</button>}</div><input ref={logoPicker} type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" onChange={selectLogo} /></div></div>
          <label className="field"><span>Nome do projeto</span><input placeholder="Ex.: Portal institucional" value={form.name} onChange={e=>setForm({...form,name:e.target.value})} required /></label>
          <label className="field"><span>Diretório</span><div className="directory-control"><input placeholder="Ex.: /Users/voce/projetos/portal" value={form.directory} onClick={()=>directoryPicker.current?.click()} onChange={e=>setForm({...form,directory:e.target.value})} required /><button type="button" onClick={()=>directoryPicker.current?.click()}>Selecionar pasta</button></div><input ref={directoryPicker} className="directory-picker" type="file" webkitdirectory="" directory="" multiple onChange={selectDirectory} /><small>Clique no campo para selecionar uma pasta ou informe o caminho manualmente.</small></label>
          <label className="field"><span>Descrição <em>opcional</em></span><textarea placeholder="Uma breve descrição do projeto" value={form.description} onChange={e=>setForm({...form,description:e.target.value})} /></label>
          <div className="stack-fields"><TechnologyPicker label="Frontend" type="frontend" value={form.frontend} onChange={frontend=>setForm({...form,frontend})} /><TechnologyPicker label="Backend" type="backend" value={form.backend} onChange={backend=>setForm({...form,backend})} /><TechnologyPicker label="Banco de dados" type="database" value={form.database} onChange={database=>setForm({...form,database})} /></div>
          <div className="project-form-actions"><button className="button ghost" type="button" onClick={closeModal}>Cancelar</button><button className="button primary" type="submit" disabled={saving}>{saving ? 'Salvando…' : editingId ? 'Salvar alterações' : 'Cadastrar projeto'}</button></div>
        </div>
      </form>
    </div>}
  </section>;
}
