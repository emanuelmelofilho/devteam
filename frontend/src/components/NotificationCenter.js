import React, {useEffect, useRef, useState} from 'react';
import api from '../api';

export default function NotificationCenter(){
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState([]);
  const [unread, setUnread] = useState(0);
  const previousUnread = useRef(0);

  function load(){
    api.get('/kanban/notifications').then(({data})=>{
      if (data.unread_count > previousUnread.current && 'Notification' in window && Notification.permission === 'granted') {
        const newest = data.notifications.find(item=>!item.read_at);
        if (newest) new Notification('Devteam', {body:newest.data.message});
      }
      previousUnread.current = data.unread_count;
      setItems(data.notifications);
      setUnread(data.unread_count);
    }).catch(()=>{});
  }

  useEffect(()=>{
    load();
    const timer = setInterval(load, 10000);
    return ()=>clearInterval(timer);
  },[]);

  async function toggle(){
    const next = !open;
    setOpen(next);
    if (next && 'Notification' in window && Notification.permission === 'default') Notification.requestPermission();
  }

  async function readAll(){
    await api.patch('/kanban/notifications/read-all');
    setItems(current=>current.map(item=>({...item,read_at:item.read_at || new Date().toISOString()})));
    setUnread(0);
    previousUnread.current = 0;
  }

  return <div className="notification-center">
    <button className="notification-trigger" onClick={toggle} aria-label={`${unread} notificações não lidas`} aria-expanded={open}>♢{unread > 0 && <b>{unread}</b>}</button>
    {open && <div className="notification-panel">
      <div className="notification-heading"><div><strong>Notificações</strong><small>Inícios e conclusões de atividades</small></div>{unread > 0 && <button onClick={readAll}>Marcar como lidas</button>}</div>
      <div className="notification-list">{items.length === 0 ? <p className="notification-empty">Nenhuma notificação ainda.</p> : items.map(item=><article key={item.id} className={item.read_at ? '' : 'unread'}><span className={`notification-symbol ${item.data.event}`}>{item.data.event === 'finished' ? '✓' : '▶'}</span><div><strong>{item.data.message}</strong><small>{new Date(item.created_at).toLocaleString('pt-BR')}</small></div></article>)}</div>
    </div>}
  </div>;
}
