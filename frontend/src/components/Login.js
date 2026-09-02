import React, {useState} from 'react';
import api from '../api';

export default function Login({onLogin}){
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  async function submit(e){
    e.preventDefault();
    setError('');
    setLoading(true);
    try{
      const res = await api.post('/auth/login', {email, password});
      const token = res.data.token;
      localStorage.setItem('kanban_token', token);
      localStorage.setItem('kanban_user', JSON.stringify(res.data.user));
      if (onLogin) onLogin(res.data.user);
    }catch(err){
      if (err.response?.status === 401) setError('E-mail ou senha inválidos. Confira os dados e tente novamente.');
      else if (err.response?.data?.message) setError(err.response.data.message);
      else setError('Não foi possível conectar à API. Tente novamente em instantes.');
    }finally{ setLoading(false); }
  }

  return (
    <main className="login-page">
      <section className="login-hero">
        <div className="login-hero-content">
          <span className="eyebrow">DEVTEAM WORKSPACE</span>
          <h1>Projetos fluindo.<br/>Time conectado.</h1>
          <p>Visualize prioridades, acompanhe cada entrega e mantenha todo o time na mesma direção.</p>
          <div className="hero-pills"><span>Visão clara</span><span>Fluxo ágil</span><span>Entregas melhores</span></div>
        </div>
        <div className="orb orb-one"></div><div className="orb orb-two"></div>
      </section>
      <section className="login-panel">
        <form onSubmit={submit} className="login-card">
          <div className="login-logo"><span className="brand-mark large">D</span></div>
          <span className="eyebrow blue">BEM-VINDO DE VOLTA</span>
          <h2>Acesse sua conta</h2>
          <p className="muted">Entre com suas credenciais para continuar.</p>
          {error && <div className="alert error" role="alert">{error}</div>}
          <label className="field"><span>E-mail</span><input type="email" autoComplete="email" placeholder="voce@empresa.com" value={email} onChange={e=>setEmail(e.target.value)} required /></label>
          <label className="field"><span>Senha</span><input type="password" autoComplete="current-password" placeholder="Digite sua senha" value={password} onChange={e=>setPassword(e.target.value)} required /></label>
          <button className="button primary full" type="submit" disabled={loading}>{loading ? 'Entrando…' : 'Entrar no workspace'}</button>
          <small className="login-footer">Ambiente seguro e protegido</small>
        </form>
      </section>
    </main>
  );
}
