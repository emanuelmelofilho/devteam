import React, {useEffect, useState} from 'react';
import api from '../api';

export default function Settings(){
  const [config, setConfig] = useState({api_url:'', token:'', phone:'', client_token:''});
  const [message, setMessage] = useState('');
  const [codex, setCodex] = useState({api_key:'', configured:false, masked_key:null, updated_at:null});
  const [codexMessage, setCodexMessage] = useState('');
  const [savingCodex, setSavingCodex] = useState(false);
  const [showCodexKey, setShowCodexKey] = useState(false);

  useEffect(()=>{
    api.get('/kanban/whatsapp-settings').then(r=>{
      if (r.data) setConfig(r.data);
    }).catch(()=>{});
    api.get('/kanban/codex-settings').then(r=>setCodex(current=>({...current,...r.data}))).catch(()=>{});
  },[]);

  function save(){
    setMessage('');
    api.post('/kanban/whatsapp-settings', config).then(()=>setMessage('Configurações salvas com sucesso.')).catch(()=>setMessage('Não foi possível salvar as configurações.'));
  }

  function saveCodex(event){
    event.preventDefault();
    setSavingCodex(true); setCodexMessage('');
    api.post('/kanban/codex-settings', {api_key:codex.api_key}).then(({data})=>{
      setCodex({...data,api_key:''});
      setCodexMessage('Chave do Codex salva com segurança.');
    }).catch(error=>{
      const validation = error.response?.data?.errors;
      setCodexMessage(validation ? Object.values(validation).flat()[0] : 'Não foi possível salvar a chave do Codex.');
    }).finally(()=>setSavingCodex(false));
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
      <form className="settings-card codex-settings-card" onSubmit={saveCodex}><div className="settings-icon codex-icon">C</div><div className="settings-content"><div className="setting-status-row"><div><h2>Chave do Codex</h2><p className="muted">Configure a chave da API da OpenAI utilizada pelas automações.</p></div><span className={codex.configured ? 'configured' : ''}>{codex.configured ? 'Configurada' : 'Não configurada'}</span></div>
      {codexMessage && <div className={`alert ${codexMessage.includes('segurança') ? 'success' : 'error'}`}>{codexMessage}</div>}
      {codex.configured && <div className="secret-summary"><div><small>Chave atual</small><code>{codex.masked_key}</code></div>{codex.updated_at && <small>Atualizada em {new Date(codex.updated_at).toLocaleString('pt-BR')}</small>}</div>}
      <label className="field"><span>{codex.configured ? 'Nova chave da API' : 'Chave da API'}</span><div className="secret-input"><input type={showCodexKey ? 'text' : 'password'} autoComplete="new-password" placeholder="sk-••••••••••••••••••••" value={codex.api_key} onChange={event=>setCodex({...codex,api_key:event.target.value})} required /><button type="button" onClick={()=>setShowCodexKey(current=>!current)}>{showCodexKey ? 'Ocultar' : 'Exibir'}</button></div><small>A chave é enviada somente ao backend, criptografada antes de ser salva e nunca retornada por completo.</small></label>
      <button className="button primary" type="submit" disabled={savingCodex}>{savingCodex ? 'Salvando…' : codex.configured ? 'Alterar chave' : 'Salvar chave'}</button></div></form></div>
    </section>
  );
}
