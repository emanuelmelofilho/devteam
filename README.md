# Kanban Module (Devteam)

This repository contains the Kanban module scaffold that will run independently under `devteam/kanban` and interact with the existing `batistacrista/imedia` application.

Goals
- Laravel backend (in `backend/`) — use Composer to create a Laravel project and then copy provided migrations/seeders.
 - Laravel backend (in `backend/`) — use Composer to create a Laravel project and then copy provided migrations/seeders. I generated model/controller/notification/service stubs and an API route file ready to be included.
- React frontend (in `frontend/`) — Kanban board and admin settings UI.
- Agents in `agents/` — workers that consume RabbitMQ queues and perform developer/tester/devops tasks.
- Docker compose to run Postgres and RabbitMQ locally.

Quick start (local development)

The Compose stack starts Postgres, RabbitMQ, the Laravel API and the React frontend:

```bash
docker compose up --build
```

Open the services at:

- Frontend: http://localhost:3001
- Laravel API: http://localhost:8001
- RabbitMQ management: http://localhost:15672
- PostgreSQL: localhost:5433

Laravel migrations run automatically when the backend container starts. To seed
the initial admin user once, run:

```bash
docker compose exec \
  -e ADMIN_NAME="Devteam Admin" \
  -e ADMIN_EMAIL="admin@example.com" \
  -e ADMIN_PASSWORD="uma-senha-forte" \
  backend php artisan db:seed --class=AdminSeeder
```

WhatsApp API
- There is a config UI in the frontend to set the WhatsApp API endpoint and token (stored in the DB via the `whatsapp_settings` table).
- Configure the endpoint and token through the admin UI or local environment. Credentials are not versioned.

Agents
- The `agents/` folder contains worker templates for Developer, Tester and DevOps. They connect to RabbitMQ and process tasks on named queues.

Next steps
- Run the Laravel installation and I will provide the migrations/seeders and agent scripts to drop into the project.

Authentication (API)
- This project uses token authentication via Laravel Sanctum. After installing Laravel you should install Sanctum:

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

- The API exposes `POST /api/auth/login` to get a token and `POST /api/auth/logout` to revoke it.

Running tests
- Basic PHPUnit tests were added under `backend/tests/Feature`. Run them with:

```bash
cd backend
php artisan test
```

Postman
- A Postman collection is included at `postman/Kanban.postman_collection.json` with example requests (login, get projects). Set `{{baseUrl}}` and `{{token}}` in your Postman environment.

Workers / Agents
- Agents connect to RabbitMQ and will call the Kanban API. Ensure `KANBAN_API` is set (e.g. `http://localhost:8000/api/kanban`). Use `agents/publish_example.php` to send example payloads.

Security / CORS / CSRF
- For local development using token-based API auth (Sanctum personal access tokens) CORS may be required if the frontend runs on a different origin. Add or adjust `config/cors.php` and ensure the `HandleCors` middleware is enabled in `app/Http/Kernel.php`.
- If you prefer SPA cookie authentication with Sanctum, follow Laravel Sanctum docs to configure sessions, CSRF cookie endpoints and CORS appropriately.

Production notes
- See `backend/DEPLOY.md` for a minimal production deployment using Docker Compose, process supervision for queue workers, and environment variable recommendations.

Advanced agent payloads
- Examples are in `agents/advanced_payloads.md`.
