import React from 'react';

export default function ProjectLogo({project, size = 'medium'}){
  if (project.logo_url) {
    return <span className={`project-logo ${size}`}><img src={project.logo_url} alt={`Logo de ${project.name}`} /></span>;
  }

  return <span className={`project-logo project-logo-fallback ${size}`} aria-label={`Projeto ${project.name}`}>{(project.name || 'P').charAt(0).toUpperCase()}</span>;
}
