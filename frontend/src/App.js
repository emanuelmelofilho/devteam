import React, {useState} from 'react';
import KanbanBoard from './components/KanbanBoard';
import Settings from './components/Settings';
import Login from './components/Login';
import Projects from './components/Projects';
import Logs from './components/Logs';
import Metrics from './components/Metrics';
import NotificationCenter from './components/NotificationCenter';

function App(){
  const [view, setView] = useState('board');
  const [user, setUser] = useState(() => {
    try{ return JSON.parse(localStorage.getItem('kanban_user')); }catch(e){return null}
  });

  async function handleLogout(){
    try { await (await import('./api')).default.post('/auth/logout'); } catch (e) {}
    localStorage.removeItem('kanban_token');
    localStorage.removeItem('kanban_user');
    setUser(null);
  }

  return (
    user ? (
      <div className="app-shell">
        <header className="topbar">
          <div className="brand">
            <span className="brand-mark">D</span>
            <div><strong>Devteam</strong><small>Workspace Kanban</small></div>
          </div>
          <nav className="main-nav" aria-label="Navegação principal">
            <button className={view === 'board' ? 'active' : ''} onClick={()=>setView('board')}>Quadro</button>
            <button className={view === 'projects' ? 'active' : ''} onClick={()=>setView('projects')}>Projetos</button>
            <button className={view === 'logs' ? 'active' : ''} onClick={()=>setView('logs')}>Logs</button>
            <button className={view === 'metrics' ? 'active' : ''} onClick={()=>setView('metrics')}>Métricas</button>
            <button className={view === 'settings' ? 'active' : ''} onClick={()=>setView('settings')}>Configurações</button>
          </nav>
          <div className="user-menu">
            <NotificationCenter />
            <span className="avatar">{(user.name || user.email || 'U').charAt(0).toUpperCase()}</span>
            <div><strong>{user.name}</strong><small>{user.email}</small></div>
            <button className="button ghost" onClick={handleLogout}>Sair</button>
          </div>
        </header>
        <main className="page-content">
          {view === 'board' ? <KanbanBoard /> : view === 'projects' ? <Projects /> : view === 'logs' ? <Logs /> : view === 'metrics' ? <Metrics /> : <Settings />}
        </main>
      </div>
    ) : <Login onLogin={u=>setUser(u)} />
  );
}

export default App;
