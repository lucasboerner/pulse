# Pulse — Project Guidelines for Claude Code

## Project Overview

Full-stack application:
- **Backend**: Symfony 8.0 with API Platform 4.3, PHP 8.4, FrankenPHP
- **Frontend**: Next.js 16 with React 19, TypeScript 5, Tailwind CSS 4
- **Infrastructure**: Docker Compose with OrbStack for local development; a self-hosting compose file (`compose.selfhost.yaml`) pulls published GHCR images. Five long-running containers — `api`, `worker`, `app`, `mercure` (SSE hub for live updates), `database` — with `mailpit` in development only.

## Core Principles

1. **Always use Docker** — run all commands through `docker compose exec`
2. **Code Quality First** — PHP CS Fixer + PHPStan (level 8) after backend changes; ESLint + `tsc --noEmit` after frontend changes
3. **Test Everything** — every API endpoint gets test coverage
4. **Never Commit Secrets** — `.env` files hold defaults; real values are local overrides or GitHub secrets

## Code Quality Hooks

Claude Code's `PostToolUse` hook (`.claude/settings.json`) runs PHP CS Fixer +
PHPStan after every PHP edit under `api/`. The Git hooks need one install:

```bash
bash .claude/setup-hooks.sh
```

### Manual checks

```bash
# Backend
docker compose exec api vendor/bin/php-cs-fixer fix --diff
docker compose exec api vendor/bin/phpstan analyse
docker compose exec api bin/phpunit

# Frontend
docker compose exec app yarn lint
docker compose exec app yarn typecheck
```

## Development Guidelines

Key architectural rules:

- **No EntityListeners for relation side-effects** — never use lifecycle hooks to
  create or mutate related entities. The service that performs the write is
  responsible for all related writes.
- **The browser never calls the API** — all traffic is server-side.

Frontend architecture and conventions live in [docs/frontend/](docs/frontend/).

## Migrations

**Never write migration files by hand.** Always generate them:

```bash
docker compose exec api bin/console make:migration
```

After generating, **stop there** — do not run `doctrine:migrations:migrate`. Tell the
user a migration was generated and let them apply it. (They also apply automatically
on the next `api` container boot.)

## Quick Commands Reference

```bash
# Infrastructure
docker compose up -d                    # Start all services
docker compose down                     # Stop all services

# Backend
docker compose exec api bin/console cache:clear
docker compose exec api bin/console make:migration   # generate only — never execute
docker compose exec api bin/phpunit

# Frontend
docker compose exec app yarn dev
docker compose exec app yarn build
docker compose exec app yarn lint
docker compose exec app yarn typecheck

# Database
docker compose exec database psql -U app app
```

## Project Structure

```
pulse/
├── api/                 # Symfony backend
│   ├── src/             # Application code
│   ├── config/          # Configuration
│   ├── migrations/      # Database migrations (organized BY_YEAR)
│   ├── tests/           # PHPUnit tests + Foundry factories
│   └── frankenphp/      # Caddyfile, entrypoint, PHP ini overrides
├── app/                 # Next.js frontend
│   └── src/
│       ├── app/         # App Router: root layout + globals.css + routes
│       ├── features/    # Per-domain feature folders (components + lib + types)
│       ├── components/  # ui/ (shadcn) + providers.tsx
│       ├── lib/         # api/ (client.ts reads, actions.ts writes), utils.ts
│       ├── types/       # Shared cross-cutting types (api.ts)
│       └── hooks/       # Cross-cutting client hooks
├── docs/                # Guidelines + frontend docs
├── .claude/             # Claude Code configuration (settings, hooks)
├── .github/workflows/   # CI (lint, typecheck, tests)
├── compose.yaml         # Local development stack
├── compose.selfhost.yaml # Self-hosting stack (published GHCR images)
└── CLAUDE.md            # This file
```

## Important Notes

- **Environment Parity**: always use `docker compose exec` for commands
- **Adding an `app/` npm dependency needs an in-container install too**: the `app` container's `node_modules` is an anonymous volume, so after `yarn add` on the host run `docker compose exec app yarn install` — otherwise the pre-commit hook's in-container `tsc` cannot resolve the new package.
- **Code Standards**: PSR-12 for PHP (PHP CS Fixer `@Symfony`), ESLint for TypeScript
- **API Content-Type**: `application/vnd.api+json` — JSON-LD/Hydra is **disabled**;
  only `json` + `jsonapi` formats are enabled in `api/config/packages/api_platform.yaml`.
  API docs render with Scalar at `/api/docs`.
- **JSON:API attribute naming**: a resource property named `type` is written and read as
  `_type` in payloads — API Platform reserves `type` for the resource type — so `Monitor`'s
  `type` field is `_type` over the wire.
- **JSON:API omits null top-level attributes**: a null resource attribute is absent from the
  payload (reads as `undefined` after flattening), while nulls inside nested arrays stay — so
  compare nullable frontend reads with `== null`, never `=== null`.
- **API resources are DTOs**: the exposed shapes live in `api/src/ApiResource/` (e.g.
  `MonitorResource`) and map onto entities with Symfony's Object Mapper via `#[Map]` +
  `stateOptions: new Options(entityClass: …)`. The read direction reads the entity's `#[Map]`,
  the write direction the DTO's; read-only fields opt out of writes with `#[Map(if: false)]`.
- **Database**: PostgreSQL 16 in dev/prod, SQLite for tests. The migrations are
  PostgreSQL-shaped and must never run against the test database — Foundry's
  `ResetDatabase` builds the test schema from entity metadata.
- **Primary Keys**: UUID v7 via `App\Entity\EntityIdTrait`, never auto-increment
- **No authentication yet**: the template ships without auth. See
  `docs/frontend/data-and-forms.md` → Authentication for where the seam is.
- **Mercure hub is 1.0**: `dunglas/mercure` enforces RFC 9068, so every publisher and (future) subscriber JWT must be protocol `1.0` with a trusted `iss` (`https://localhost` by default), an `aud` pinned to the exact URL that side posts to, and a `typ: at+jwt` header — the legacy bundle-default token is rejected with a 401.
- **Async code needs a worker restart**: `worker` and `scheduler` run long-lived `messenger:consume`, so after changing message-handler code run `docker compose restart worker scheduler` before testing the async path live — the live-mounted edit alone is not picked up by the running process.
- **New fields on an existing API resource need an `api` restart**: adding/removing a serialized property or `#[Groups]` on an existing resource is silently ignored by the running FrankenPHP worker (it caches serialization metadata in memory); `cache:clear` alone is not enough — run `docker compose restart api`. A brand-new resource/route is picked up without it.
- **Rollup ordering is the retention guard**: the hourly job (`App\Rollup\CheckRollupJob`) must aggregate a bucket, upsert, commit, and only then delete raw `check_result` rows — and the delete cutoff is the *earlier* of `now − retention days` and the start of the oldest not-yet-rolled-up bucket. Never replace that with a plain `now − 7 days` delete: a rollup job that has been failing leaves the only surviving copy of that history in raw rows a plain window delete would destroy.
- **Committed secrets in `api/.env` leak into the published image**: the prod builder runs `composer dump-env prod`, which bakes `api/.env` into `.env.local.php`, and an empty container env var does *not* override a baked non-empty one — so a real secret (e.g. `JWT_PASSPHRASE`) must live in `.env.dev`/`.env.test` (committed, never baked), never in `.env`.
