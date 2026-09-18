# Pulse

A Symfony 8 / API Platform backend and a Next.js 16 frontend in one repository,
with a working local Docker stack, CI, and a self-hosting Docker Compose file
that pulls published container images.


---

## The example slice

A minimal `Example` resource ships end to end — entity, repository, migration,
Foundry factory, API test, and the frontend page that reads it. It exists so the
first `docker compose up` proves something, and so CI is green on the first push
(PHPUnit exits non-zero on an empty test suite).

Delete it once you have a real resource:

```bash
grep -rl 'EXAMPLE — safe to delete' . --exclude-dir={node_modules,vendor,.next,var}
```

---

## Tech stack

### Backend (`api/`)
- PHP 8.4, Symfony 8.0, API Platform 4.3
- Doctrine ORM 3 + Migrations, PostgreSQL 16 (SQLite for tests)
- FrankenPHP (Caddy, worker mode)
- Serves **JSON:API** (`application/vnd.api+json`); JSON-LD/Hydra disabled, Scalar docs at `/api/docs`
- UUID v7 primary keys, Gedmo timestamps + soft delete
- Symfony Messenger (Doctrine transport) with a `worker` service
- Mercure hub for live updates (Server-Sent Events) — the worker publishes, the browser subscribes
- PHPStan level 8, PHP CS Fixer (`@Symfony`), PHPUnit 13 + Zenstruck Foundry

### Frontend (`app/`)
- Next.js 16 (App Router), React 19, TypeScript 5 (strict)
- Tailwind CSS 4 (CSS-first tokens, no `tailwind.config.js`) + shadcn/ui
- react-hook-form + Zod
- Fully server-rendered: reads in Server Components, writes in Server Actions — the
  browser never calls the API

### Infrastructure
- Docker Compose for local development — five long-running services (`api`, `worker`, `app`, `mercure`, `database`) plus `mailpit`
- OrbStack local domains (`*.pulse.orb.local`)
- Self-hosting via `compose.selfhost.yaml`, which pulls published GitHub Container Registry images — no build toolchain on the host, and you bring your own reverse proxy for TLS
- GitHub Actions CI (lint, type-check, tests) on every push and pull request

---

## Development setup

```bash
docker compose up -d
```

First boot builds the images, waits for PostgreSQL, and applies the migrations
automatically.

### Services

| Service | URL | Notes |
|---|---|---|
| Frontend | `http://app.pulse.orb.local` (or `localhost:3000`) | Next.js dev server |
| API | `http://api.pulse.orb.local` (or `localhost:8080`) | FrankenPHP |
| API docs | `http://api.pulse.orb.local/api/docs` | Scalar |
| Health probe | `http://api.pulse.orb.local/health` | `{"status":"ok"}` |
| Mercure hub | `http://mercure.pulse.orb.local` (or `localhost:3001`) | SSE hub for live updates |
| Mail inbox | `http://mailpit.pulse.orb.local` (or `localhost:8025`) | Mailpit — nothing leaves the machine |
| Database | `database:5432` internally, `localhost:5432` on the host | PostgreSQL 16 |

Without OrbStack the published host ports (`3000`, `8080`, `3001`, `8025`, `5432`)
work the same; only the `*.orb.local` names need it.

---

## Common commands

### Backend
```bash
docker compose exec api bin/console cache:clear
docker compose exec api bin/console make:migration      # generate only — never execute
docker compose exec api bin/phpunit
docker compose exec api vendor/bin/phpstan analyse
docker compose exec api vendor/bin/php-cs-fixer fix --diff
docker compose exec api composer require vendor/package
```

### Frontend
```bash
docker compose exec app yarn lint
docker compose exec app yarn typecheck
docker compose exec app yarn build
cd app && npx shadcn@latest add <component>   # locally, not through Docker
```

### Database
```bash
docker compose exec database psql -U app app
```

---

## Documentation

| Read | For |
|---|---|
| [CLAUDE.md](CLAUDE.md) | Project conventions, quick command reference, structure |
| [docs/frontend/](docs/frontend/) | Frontend architecture, conventions, data & forms |
| [.claude/README.md](.claude/README.md) | Claude Code hooks |

---

## Self-hosting

`compose.selfhost.yaml` runs the whole stack from published GitHub Container
Registry images — no build toolchain on the host:

```bash
docker compose -f compose.selfhost.yaml up -d
```

Set the required environment variables first (`APP_SECRET`, the `POSTGRES_*`
credentials, `MAILER_DSN`, `MERCURE_JWT_SECRET`, `CORS_ALLOW_ORIGIN`,
`APP_FRONTEND_URL`, `DEFAULT_URI`) — see [.env.example](.env.example) and the
comment header of `compose.selfhost.yaml`. Put your own reverse proxy in front
of the published ports for TLS; the project does not ship one.
