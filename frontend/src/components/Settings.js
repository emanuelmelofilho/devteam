import React, {useEffect, useState} from 'react';
import api from '../api';

function AgentKeyCard({name, icon, title, description, endpoint, placeholder}){
  const [secret, setSecret] = useState({api_key:'', configured:false, masked_key:null, updated_at:null});
  const [message, setMessage] = useState('');
  const [saving, setSaving] = useState(false);
  const [showKey, setShowKey] = useState(false);

  useEffect(()=>{
    api.get(endpoint).then(r=>setSecret(current=>({...current,...r.data}))).catch(()=>{});
  },[endpoint]);

  function save(event){
    event.preventDefault();
    setSaving(true); setMessage('');
    api.post(endpoint, {api_key:secret.api_key}).then(({data})=>{
      setSecret({...data,api_key:''});
      setMessage(`Chave do ${name} salva com segurança.`);
    }).catch(error=>{
      const validation = error.response?.data?.errors;
      setMessage(validation ? Object.values(validation).flat()[0] : `Não foi possível salvar a chave do ${name}.`);
    }).finally(()=>setSaving(false));
  }

  return (
    <form className="settings-card codex-settings-card" onSubmit={save}><div className={`settings-icon ${icon}`}>{name[0]}</div><div className="settings-content"><div className="setting-status-row"><div><h2>{title}</h2><p className="muted">{description}</p></div><span className={secret.configured ? 'configured' : ''}>{secret.configured ? 'Configurada' : 'Não configurada'}</span></div>
    {message && <div className={`alert ${message.includes('segurança') ? 'success' : 'error'}`}>{message}</div>}
    {secret.configured && <div className="secret-summary"><div><small>Chave atual</small><code>{secret.masked_key}</code></div>{secret.updated_at && <small>Atualizada em {new Date(secret.updated_at).toLocaleString('pt-BR')}</small>}</div>}
    <label className="field"><span>{secret.configured ? 'Nova chave da API' : 'Chave da API'}</span><div className="secret-input"><input type={showKey ? 'text' : 'password'} autoComplete="new-password" placeholder={placeholder} value={secret.api_key} onChange={event=>setSecret({...secret,api_key:event.target.value})} required /><button type="button" onClick={()=>setShowKey(current=>!current)}>{showKey ? 'Ocultar' : 'Exibir'}</button></div><small>A chave é enviada somente ao backend, criptografada antes de ser salva e nunca retornada por completo.</small></label>
    <button className="button primary" type="submit" disabled={saving}>{saving ? 'Salvando…' : secret.configured ? 'Alterar chave' : 'Salvar chave'}</button></div></form>
  );
}

function ProviderCard(){
  const [provider, setProvider] = useState({provider:'codex', providers:[]});
  const [message, setMessage] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(()=>{
    api.get('/kanban/agent-provider').then(r=>setProvider(r.data)).catch(()=>{});
  },[]);

  function choose(id){
    if (id === provider.provider || saving) return;
    setSaving(true); setMessage('');
    api.post('/kanban/agent-provider', {provider:id}).then(({data})=>{
      setProvider({provider:data.provider, providers:data.providers});
      setMessage('Provedor alterado. As próximas execuções já usam a nova escolha.');
    }).catch(()=>setMessage('Não foi possível alterar o provedor.')).finally(()=>setSaving(false));
  }

  return (
    <div className="settings-card"><div className="settings-icon codex-icon">IA</div><div className="settings-content"><h2>Provedor dos agentes</h2><p className="muted">Escolha qual IA executa Developer, Tester e DevOps.</p>
    {message && <div className={`alert ${message.includes('alterado.') ? 'success' : 'error'}`}>{message}</div>}
    <div className="provider-options">{provider.providers.map(item=>(
      <button type="button" key={item.id} className={`provider-option ${provider.provider === item.id ? 'active' : ''}`} onClick={()=>choose(item.id)} disabled={saving} aria-pressed={provider.provider === item.id}><strong>{item.label}</strong><small>{item.configured ? 'Chave configurada' : 'Sem chave: usa o login do CLI no host'}</small></button>
    ))}</div></div></div>
  );
}

export default function Settings(){
  const [config, setConfig] = useState({api_url:'', token:'', phone:'', client_token:''});
  const [message, setMessage] = useState('');

  useEffect(()=>{
    api.get('/kanban/whatsapp-settings').then(r=>{
      if (r.data) setConfig(r.data);
    }).catch(()=>{});
  },[]);

  function save(){
    setMessage('');
    api.post('/kanban/whatsapp-settings', config).then(()=>setMessage('Configurações salvas com sucesso.')).catch(()=>setMessage('Não foi possível salvar as configurações.'));
  }

  return (
    <section><div className="page-heading"><div><span className="eyebrow blue">INTEGRAÇÕES</span><h1>Configurações</h1><p>Gerencie as conexões externas do seu workspace.</p></div></div>
      <div className="settings-grid"><div className="settings-card"><div className="settings-icon">W</div><div className="settings-content"><h2>Integração WhatsApp</h2><p className="muted">Configure o serviço usado para receber notificações das tarefas.</p>
      {message && <div className={`alert ${message.includes('sucesso') ? 'success' : 'error'}`}>{message}</div>}
      <label className="field"><span>URL da API</span><input placeholder="https://api.exemplo.com" value={config.api_url || ''} onChange={e=>setConfig({...config, api_url:e.target.value})} /></label>
      <label className="field"><span>Token de acesso</span><input type="password" placeholder="••••••••••••" value={config.token || ''} onChange={e=>setConfig({...config, token:e.target.value})} /></label>
      <label className="field"><span>Número para notificações</span><input placeholder="Ex.: 5511999999999" value={config.phone || ''} onChange={e=>setConfig({...config, phone:e.target.value})} /></label>
      <label className="field"><span>Client-Token da Z-API</span><input type="password" placeholder={config.has_client_token ? 'Configurado — deixe vazio para manter' : 'Informe o token de segurança'} value={config.client_token || ''} onChange={e=>setConfig({...config, client_token:e.target.value})} /></label>
      <button className="button primary" onClick={save}>Salvar alterações</button></div></div>
      <ProviderCard />
      <AgentKeyCard name="Codex" icon="codex-icon" title="Chave do Codex" description="Configure a chave da API da OpenAI utilizada pelas automações." endpoint="/kanban/codex-settings" placeholder="sk-••••••••••••••••••••" />
      <AgentKeyCard name="Claude" icon="claude-icon" title="Chave do Claude" description="Configure a chave da API da Anthropic utilizada pelas automações." endpoint="/kanban/claude-settings" placeholder="sk-ant-••••••••••••••••" /></div>
    </section>
  );
}
