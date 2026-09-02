# Deployment guide (Laravel + RabbitMQ + Postgres)

This file describes a minimal production deployment for the Kanban module.

Prerequisites
- Docker and Docker Compose on the server
- A domain name and TLS certificate (Let’s Encrypt or similar)

Example `docker-compose.prod.yml` (simplified):

```yaml
version: '3.8'
services:
  app:
    build: ./backend
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
      - DB_HOST=postgres
      - RABBITMQ_HOST=rabbitmq
    volumes:
      - ./backend:/var/www/html
    depends_on:
      - postgres
      - rabbitmq
  postgres:
    image: postgres:15
    environment:
      POSTGRES_USER: kanban
      POSTGRES_PASSWORD: kanban
      POSTGRES_DB: kanban
    volumes:
      - pgdata:/var/lib/postgresql/data
  rabbitmq:
    image: rabbitmq:3-management
    environment:
      RABBITMQ_DEFAULT_USER: guest
      RABBITMQ_DEFAULT_PASS: guest
    ports:
      - 15672:15672
volumes:
  pgdata:
```

Notes
- Use a process manager (Supervisor) to run `php artisan queue:work` and the agent scripts.
- Configure `APP_KEY`, `DB_*`, `RABBITMQ_*`, and `WHATSAPP_*` environment variables in your environment or through a secrets manager.
- Use a reverse proxy (nginx) to serve the Laravel application and SSL termination.
