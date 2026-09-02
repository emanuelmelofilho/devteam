import React from 'react';

export const TECHNOLOGIES = {
  frontend: [{id:'React',icon:'react/react-original.svg',color:'#087ea4'},{id:'Vue.js',icon:'vuejs/vuejs-original.svg',color:'#42b883'},{id:'Angular',icon:'angularjs/angularjs-original.svg',color:'#dd0031'},{id:'Next.js',icon:'nextjs/nextjs-original.svg',color:'#111827'},{id:'HTML/CSS',icon:'html5/html5-original.svg',color:'#e44d26'}],
  backend: [{id:'Laravel',icon:'laravel/laravel-original.svg',color:'#ff2d20'},{id:'Node.js',icon:'nodejs/nodejs-original-wordmark.svg',color:'#339933'},{id:'Django',icon:'django/django-plain.svg',color:'#0c4b33'},{id:'Spring Boot',icon:'spring/spring-original.svg',color:'#6db33f'},{id:'.NET',icon:'dot-net/dot-net-original.svg',color:'#512bd4'},{id:'Symfony',icon:'symfony/symfony-original.svg',color:'#111827'},{id:'Zend',icon:'zend/zend-original.svg',color:'#68b604'}],
  database: [{id:'PostgreSQL',icon:'postgresql/postgresql-original.svg',color:'#336791'},{id:'MySQL',icon:'mysql/mysql-original-wordmark.svg',color:'#00758f'},{id:'MongoDB',icon:'mongodb/mongodb-original.svg',color:'#47a248'},{id:'SQLite',icon:'sqlite/sqlite-original.svg',color:'#0f80cc'},{id:'Redis',icon:'redis/redis-original.svg',color:'#dc382d'},{id:'SQL Server',icon:'microsoftsqlserver/microsoftsqlserver-original.svg',color:'#cc2927'},{id:'Oracle',icon:'oracle/oracle-original.svg',color:'#f80000'}],
};

export const logoUrl = icon => `https://cdn.jsdelivr.net/gh/devicons/devicon@latest/icons/${icon}`;

export default function ProjectTechnologyLogos({project, showNames = false}){
  const selected = ['frontend','backend','database'].flatMap(type=>{
    if (!project[type]) return [];
    const technology = TECHNOLOGIES[type].find(item=>item.id === project[type]);
    return technology ? [{...technology,type}] : [];
  });

  if (selected.length === 0) return null;

  return <span className={`project-technology-logos ${showNames ? 'with-names' : ''}`}>{selected.map(technology=><span key={technology.type} title={`${technology.type}: ${technology.id}`}><img src={logoUrl(technology.icon)} alt={technology.id} />{showNames && <small>{technology.id}</small>}</span>)}</span>;
}
