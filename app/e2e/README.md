# End-to-end tests

Playwright specs that drive a real browser against the running compose stack (`api`, `worker`,
`scheduler`, `mercure`, `database`). The two load-bearing cases — the live status flip and "the
browser never calls the API" — can't be faithfully mocked, so the suite exercises the whole stack.

## Prerequisites

- Docker with the dev stack running.
- Node and Yarn on the host (Playwright runs on the host, not in a container).

## Run it

```bash
docker compose up -d --wait          # start the stack
cd app
yarn install                         # once, or after a dependency change
yarn playwright install chromium     # once — downloads the browser binary
yarn e2e                             # run all specs
```

Expected: **21 passed** in ~40s. The suite is deterministic and runs green twice in a row with no
manual cleanup in between.

> **`yarn e2e` resets the database.** Global setup truncates every table (keeping the schema),
> creates the test operator, and logs in once. When you're done and want the demonstration fleet
> back, reload it:
>
> ```bash
> docker compose exec api bin/console doctrine:fixtures:load --no-interaction
> ```

## Handy variants

```bash
yarn e2e e2e/04-live-flip.spec.ts    # one file
yarn e2e --ui                        # the interactive UI — best debugging tool
yarn e2e:report                      # open the last HTML report
```

A failed spec writes a trace and screenshot under `app/test-results/` and the HTML report under
`app/playwright-report/` (both git-ignored).

## Configuration

Overridable by environment variable, each with a committed default (see `e2e/constants.ts`):

| Variable | Default | Meaning |
| --- | --- | --- |
| `E2E_BASE_URL` | `http://localhost:3000` | the app the browser drives |
| `E2E_API_URL` | `http://localhost:8080` | the API, for seeding fixtures (test process only) |
| `E2E_USERNAME` / `E2E_PASSWORD` / `E2E_EMAIL` | `e2e-operator` / … | the test operator (throwaway DB only) |

Serial by design (`workers: 1`): the Mercure topic is a single global channel and the database is
shared, so parallel specs would see each other's live updates and monitors.

## What the specs cover

One file per theme, numbered so they run in a fixed order (dead-hub last, so it can't disturb the
live-flip case):

1. `01-auth-gate` — unauthenticated redirect to `/login`, no API call, `?next=` preserved.
2. `02-login` — wrong vs. correct credentials; signed-in `/login` bounces to `/`.
3. `03-overview` — the four stat blocks and the monitor list, from server markup (JavaScript off).
4. `04-live-flip` — a created monitor flips to Up in place, proven to happen with no reload.
5. `05-no-browser-api` — the browser never hits `/api/*`.
6. `06-validation` — duplicate URL and timeout-vs-interval (server 422 mapped to the field), blank name (client).
7. `07-row-actions` — edit, pause/resume, delete, with the toast copy.
8. `08-design-invariants` — the wordmark, the nav, status as a badge/dot (never a row fill).
9. `09-dead-hub` — with Mercure stopped, pages and actions still work; the hub is restarted after.

## Monitor targets

Monitors created by a spec point at real, reachable hosts (the worker performs the check):

- **Up:** `https://example.com/?…` — reachable from the worker container both locally and in CI, and
  it carries a top-level domain (the monitor URL validator rejects bare hosts like `http://api`).
- **Down:** `http://…​.invalid` — an unroutable reserved TLD, so DNS fails instantly instead of
  burning the 8-second timeout.

Every target is unique per monitor (the `url` + `type` pair is unique application-wide).

## CI

The `e2e` job in `.github/workflows/ci.yml` runs on pull requests: it boots the stack (with cached
image layers), installs Chromium only, runs the suite, and uploads the HTML report plus
`docker compose logs` on failure.
