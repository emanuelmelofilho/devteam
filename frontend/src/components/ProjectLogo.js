import React, {useEffect, useState} from 'react';
import api from '../api';

function logoSource(project){
  const value = project.logo_url || project.logo_path;
  if (!value) return null;

  try {
    const apiOrigin = new URL(api.defaults.baseURL, window.location.origin).origin;
    const logo = new URL(value, apiOrigin);
    return new URL(`${logo.pathname}${logo.search}`, apiOrigin).toString();
  } catch {
    return value;
  }
}

export default function ProjectLogo({project, size = 'medium'}){
  const source = logoSource(project);
  const [failed, setFailed] = useState(false);

  useEffect(()=>setFailed(false), [source]);

  if (source && !failed) {
    return <span className={`project-logo ${size}`}><img src={source} alt={`Logo de ${project.name}`} onError={()=>setFailed(true)} /></span>;
  }

  return <span className={`project-logo project-logo-fallback ${size}`} aria-label={`Projeto ${project.name}`}>{(project.name || 'P').charAt(0).toUpperCase()}</span>;
}
