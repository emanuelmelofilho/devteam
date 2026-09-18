# Agent Context & Rules

## 🎯 Visão Geral do Projeto

- **Nome:** Devteam Kanban
- **Descrição:** Plataforma Kanban para gerenciamento de projetos e tarefas, com autenticação, notificações, registros de atividade, integração com WhatsApp e workers de Developer, Tester e DevOps via RabbitMQ.
- **Stack:** Laravel 13, PHP 8.3, Laravel Sanctum, React 18, Axios, PostgreSQL 15, RabbitMQ e Docker Compose.

## 🛠️ Configuração e Comandos de Desenvolvimento

Execute os comandos a partir da raiz deste repositório (`kanban/`), salvo quando indicado.

- **Subir todo o ambiente:** `docker compose up --build`
- **Subir em segundo plano:** `docker compose up -d --build`
- **Parar o ambiente:** `docker compose down`
- **Executar migrations:** `docker compose exec backend php artisan migrate`
- **Testar o backend:** `docker compose exec backend php artisan test`
- **Formatar o backend:** `docker compose exec backend ./vendor/bin/pint`
- **Instalar dependências do frontend:** `cd frontend && npm install`
- **Executar o frontend localmente:** `cd frontend && npm start`
- **Testar o frontend uma vez:** `cd frontend && npm test -- --watchAll=false`
- **Gerar o build do frontend:** `cd frontend && npm run build`

O ambiente Docker disponibiliza o frontend em `http://localhost:3001`, a API em `http://localhost:8001`, o RabbitMQ Management em `http://localhost:15672` e o PostgreSQL na porta `5433`.

Nunca inclua credenciais reais em arquivos versionados. Ao adicionar uma variável de ambiente, documente-a também no `.env.example` correspondente.

## 📂 Estrutura do Repositório

- `/backend`: API Laravel, regras de negócio, persistência, autenticação e testes PHPUnit.
- `/backend/app/Http/Controllers/Api`: controllers dos endpoints da API.
- `/backend/app/Models`: models Eloquent.
- `/backend/app/Services`: integrações e serviços de domínio.
- `/backend/database/migrations`: schema versionado do banco de dados.
- `/backend/database/seeders`: dados iniciais e administrativos.
- `/backend/routes`: rotas web e de API.
- `/backend/tests`: testes unitários e de integração do backend.
- `/frontend/src/components`: telas e componentes React.
- `/frontend/src/api.js`: configuração central das chamadas HTTP.
- `/agents`: orquestração dos agentes autônomos, organizada em subpastas:
  - `/agents/orchestrator`: máquina de estados (`state_machine.php`) e roteamento de filas (`task_router.php`) — fonte única de verdade do workflow, requerida diretamente pelo backend (`OrchestratorService`) via bind mount somente leitura no `docker-compose.yml`; nenhum worker decide raia sozinho.
  - `/agents/workers`: consumidores PHP legados/placeholder das filas RabbitMQ.
  - `/agents/contracts`: JSON Schemas do payload das filas (`task.schema.json`), do resultado dos workers (`result.schema.json`) e do "Task Context" injetado nos prompts (`context.schema.json`).
  - `/agents/prompts`: prompt de cada papel (Developer/Tester/DevOps) em Markdown, com `{{placeholders}}` e um cabeçalho `<!-- version: N -->` usado como `prompt_version` nas métricas.
  - `/agents/runtime`: `host_worker.mjs` (consome RabbitMQ, roda `codex exec` ou `claude -p` conforme o provedor escolhido em Configurações e reporta o resultado via `POST /tasks/{id}/report`) e `process_manager.mjs` (spawn do Codex CLI com `--json` e do Claude Code com `stream-json`, capturando tokens de uso).
  - `/agents/launchd`: templates de LaunchAgents do macOS para rodar os workers localmente.
- `/docs/modules`: documentação funcional por módulo.
- `/postman`: coleção de requisições da API.
- `/deploy` e `/backend/deploy`: configurações de execução e supervisão em produção.

## 📜 Estilo de Código e Regras

- **Backend:** siga as convenções do Laravel e PSR-12; use tipos de parâmetros e retornos sempre que forem compatíveis com o código existente.
- **Frontend:** mantenha React funcional e JavaScript moderno. Não migre arquivos para TypeScript nem introduza Tailwind sem solicitação explícita.
- **Componentes:** prefira componentes funcionais pequenos, com responsabilidades bem definidas.
- **API:** preserve o contrato existente, a autenticação via Sanctum e o formato consistente das respostas JSON.
- **Banco de dados:** toda alteração de schema deve ser feita por uma nova migration; não edite migrations já aplicadas para representar mudanças posteriores.
- **Imports:** respeite o padrão relativo já utilizado no frontend e o autoload PSR-4 no backend.
- **Dependências:** não adicione bibliotecas quando a stack atual resolver o problema de forma simples.
- **Idioma:** escreva código, identificadores e mensagens de commit em inglês. Documentação e explicações ao usuário podem ser em português.
- **Escopo:** evite refatorações não relacionadas à tarefa e preserve compatibilidade com os serviços do `docker-compose.yml`.
- **Segurança:** nunca registre tokens, senhas ou dados sensíveis em logs, fixtures, exemplos ou commits.

Ao trabalhar especificamente em `/backend`, leia e respeite também o `backend/AGENTS.md`; as instruções mais próximas do arquivo alterado têm precedência.

## 🧪 Testes e Validação

- Para mudanças no backend, crie ou atualize testes em `/backend/tests` e execute `docker compose exec backend php artisan test`.
- Para mudanças no frontend, execute `cd frontend && npm test -- --watchAll=false` e `cd frontend && npm run build`.
- Para alterações de integração, valide os endpoints afetados e confirme a comunicação entre frontend, API, PostgreSQL e RabbitMQ quando aplicável.
- Não altere testes apenas para ocultar uma regressão; corrija a causa no código.

## 🏁 Definition of Done (DoD)

Antes de considerar uma tarefa concluída, confirme que:

1. A implementação atende ao requisito sem mudanças fora do escopo.
2. O código compila e o build aplicável termina sem erros.
3. Os testes existentes continuam passando e novos testes cobrem o comportamento alterado quando necessário.
4. O backend está formatado conforme o Laravel Pint quando houver alterações PHP.
5. Alterações de API, configuração ou comportamento relevante estão documentadas.
6. Nenhuma dependência, credencial ou arquivo gerado desnecessário foi adicionado.
7. Migrations são reversíveis e seguras para dados existentes, quando aplicável.
