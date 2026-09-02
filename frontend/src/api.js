import axios from 'axios';

const api = axios.create({
  baseURL: process.env.REACT_APP_API_URL || 'http://localhost:8000/api',
  timeout: 5000,
  headers: { Accept: 'application/json' },
});

// attach token from localStorage if present
api.interceptors.request.use(cfg => {
  const token = localStorage.getItem('kanban_token');
  if (token) cfg.headers = Object.assign(cfg.headers || {}, { Authorization: `Bearer ${token}` });
  return cfg;
});

api.interceptors.response.use(response => response, error => {
  if (error.response?.status === 401) {
    localStorage.removeItem('kanban_token');
    localStorage.removeItem('kanban_user');
    window.location.reload();
  }

  return Promise.reject(error);
});

export default api;
