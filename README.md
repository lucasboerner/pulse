# Pulse

Pulse is a self-hosted, open-source uptime and domain monitor. One `docker compose up`
gives you a running instance — no seats, no per-check pricing, no SaaS account.
Single-operator by design: no registration, no roles, no multi-tenancy.

Version 1 runs **HTTP checks**. TLS-certificate, domain-expiry (RDAP) and DNS checks are
on the roadmap; nothing below promises a check type that does not exist yet.

## Features

- **HTTP checks** as often as every 15 seconds, per monitor.
- **Incidents** open automatically on the first failed check and close on the first
  recovery.
- **Alert mail** to each monitor's own list of subscribers when an incident opens or
  resolves.
- **Live status** over Server-Sent Events — the dashboard updates without polling.
- **A 24-hour latency chart** and a **90-day status history** on every monitor.
- **Monitor groups** to organise the fleet.

## Quickstart

You need a host with **Docker Engine and the Compose plugin** — a fresh Debian or Ubuntu
VPS is enough. Nothing else is installed on the host; the images ship everything.

1. **Create a directory and fetch the two files you need:**

   ```bash
   mkdir pulse && cd pulse
   curl -fsSLO https://raw.githubusercontent.com/lucasboerner/pulse/main/compose.selfhost.yaml
   curl -fsSL  https://raw.githubusercontent.com/lucasboerner/pulse/main/.env.example -o .env
   ```

2. **Fill in `.env`.** Open it and set every variable in the self-hosting section — the
   file explains each one. At minimum you must set `APP_SECRET`, the `POSTGRES_*`
   credentials, `MAILER_DSN`, `MAILER_FROM`, `CORS_ALLOW_ORIGIN`, `APP_FRONTEND_URL`,
   `DEFAULT_URI` and `MERCURE_JWT_SECRET`. See [Configuration](#configuration) for the
   full list. A minimal `.env` for a first run on `http://localhost:3000` looks like:

   ```dotenv
   POSTGRES_DB=pulse
   POSTGRES_USER=pulse
   POSTGRES_PASSWORD=change-me-to-something-long
   APP_SECRET=$(openssl rand -hex 16)
   MAILER_DSN=smtp://user:pass@smtp.example.com:587
   MAILER_FROM=Pulse <no-reply@pulse.example.com>
   CORS_ALLOW_ORIGIN=^http://localhost:3000$
   APP_FRONTEND_URL=http://localhost:3000
   DEFAULT_URI=http://localhost:3000
   MERCURE_JWT_SECRET=$(openssl rand -hex 32)
   ```

   (Run the `openssl` commands and paste the results — `.env` does not expand shell
   substitutions.)

3. **Start the stack:**

   ```bash
   docker compose -f compose.selfhost.yaml up -d
   ```

   First boot waits for PostgreSQL, generates the JWT signing keys, and applies the
   database migrations before the API reports healthy.

4. **Create the first user.** There is no registration — you make operator accounts from
   the command line. This step is not optional; without it you cannot log in:

   ```bash
   docker compose -f compose.selfhost.yaml exec api bin/console app:user:create
   ```

   It prompts for a username, an e-mail address (where this operator's alert mail goes)
   and a password.

5. **Open the frontend and log in.** Visit `http://localhost:3000` (or your server's
   address on the port you set) and sign in with the account you just created.

6. **Add a monitor.** Give it a URL and a check interval; the first result lands within a
   few seconds and the detail page starts filling in.

## Configuration

Every variable Pulse reads, set in `.env` next to `compose.selfhost.yaml`. The required
ones have no default — the stack will not start without them.

| Variable | Required | Default | What it does |
|---|---|---|---|
| `APP_SECRET` | yes | — | Application crypto secret. Generate with `openssl rand -hex 16`. |
| `POSTGRES_DB` | yes | — | Database name. |
| `POSTGRES_USER` | yes | — | Database user. |
| `POSTGRES_PASSWORD` | yes | — | Database password. |
| `MAILER_DSN` | yes | — | Mail transport for alerts, e.g. `smtp://user:pass@smtp.example.com:587`. |
| `MAILER_FROM` | yes | — | Default `From` header, e.g. `Pulse <no-reply@pulse.example.com>`. |
| `CORS_ALLOW_ORIGIN` | yes | — | Allowed browser origin as a regex, e.g. `^https://pulse\.example\.com$`. |
| `APP_FRONTEND_URL` | yes | — | Public URL of the frontend, used in alert-mail links. |
| `DEFAULT_URI` | yes | — | Base URL for links built off-request (CLI/worker contexts). |
| `MERCURE_JWT_SECRET` | yes | — | Signs and validates the live-update tokens. Generate with `openssl rand -hex 32`. |
| `IMAGE_TAG` | no | `latest` | Which published image tag to pull. |
| `APP_PORT` | no | `3000` | Host port for the frontend. |
| `API_PORT` | no | `8080` | Host port for the API. |
| `MERCURE_PORT` | no | `3001` | Host port for the Mercure hub. |
| `PULSE_RAW_RETENTION_DAYS` | no | `7` | Days of raw check results to keep. Widening it stores more raw detail; hourly rollups are kept forever regardless. |
| `POSTGRES_VERSION` | no | `16` | `serverVersion` in the database connection string. |
| `POSTGRES_CHARSET` | no | `utf8` | Charset in the database connection string. |
| `JWT_SECRET_KEY` | no | `/data/jwt/private.pem` | Private signing-key path. Generated on first boot if absent. |
| `JWT_PUBLIC_KEY` | no | `/data/jwt/public.pem` | Public signing-key path. Generated on first boot if absent. |
| `JWT_PASSPHRASE` | no | *(empty)* | Encrypts the private key. Empty means an unencrypted key on a volume only you can read. |

The JWT keys are generated automatically on the first `up` and reused after, so you never
have to create them. They live on the persisted `api_data` volume and survive
`docker compose down`. Set the paths only if you want to supply your own key pair.

## Behind a reverse proxy

Pulse ships no reverse proxy — you terminate TLS in front of the published frontend port.
The proxy needs to front **only the frontend**: the browser never calls the API directly
(all data is fetched server-side), and **the Mercure hub needs no proxy rule of its own**
because the frontend streams live updates same-origin through its own route. This is a
real simplification — most comparable projects make you add a rule for the hub.

A complete Caddy site block, proxying your domain to the frontend on port 3000:

```caddy
pulse.example.com {
    reverse_proxy localhost:3000
}
```

Caddy obtains and renews the certificate automatically. With TLS in front, set
`APP_FRONTEND_URL`, `DEFAULT_URI` and `CORS_ALLOW_ORIGIN` to the `https://` domain and
restart the stack. The published API and Mercure ports (`8080`, `3001`) are for direct or
native-client access; firewall them off if you do not need them.

## Updating and backing up

**Update** to a newer release by moving `IMAGE_TAG` (or leaving it at `latest`) and
pulling:

```bash
docker compose -f compose.selfhost.yaml pull
docker compose -f compose.selfhost.yaml up -d
```

Database migrations run automatically when the `api` container boots the new image.

**Back up** the two named volumes:

- `database_data` — all monitors, results, rollups and incidents.
- `api_data` — the JWT signing keys (and other runtime state). Losing it forces new keys,
  which logs everyone out until they sign in again.

## Development

The development stack (`compose.yaml`) builds the images locally and adds Mailpit as a
mail sink. It is separate from the self-hosting stack above and is not needed to run
Pulse.

```bash
docker compose up -d
```

First boot builds the images, waits for PostgreSQL, and applies migrations. With
[OrbStack](https://orbstack.dev) the services get local domains:

| Service | URL | Notes |
|---|---|---|
| Frontend | `http://app.pulse.orb.local` (or `localhost:3000`) | Next.js |
| API | `http://api.pulse.orb.local` (or `localhost:8080`) | FrankenPHP |
| API docs | `http://api.pulse.orb.local/api/docs` | Scalar |
| Health probe | `http://api.pulse.orb.local/health` | `{"status":"ok"}` |
| Mercure hub | `http://mercure.pulse.orb.local` (or `localhost:3001`) | SSE hub |
| Mail inbox | `http://mailpit.pulse.orb.local` (or `localhost:8025`) | Mailpit |
| Database | `localhost:5432` | PostgreSQL 16 |

Without OrbStack the published host ports work the same; only the `*.orb.local` names
need it.

Common commands:

```bash
# Backend
docker compose exec api bin/console app:user:create        # create an operator
docker compose exec api bin/console doctrine:fixtures:load  # load the demo fleet (see below)
docker compose exec api vendor/bin/php-cs-fixer fix --diff
docker compose exec api vendor/bin/phpstan analyse
docker compose exec api bin/phpunit

# Frontend
docker compose exec app yarn lint
docker compose exec app yarn typecheck
docker compose exec app yarn build
```

`doctrine:fixtures:load` fills an empty database with a demonstration fleet (seven
monitors, incidents, and 90 days of history) so you can see the product without waiting a
day for data. It is development-only and never loaded automatically; it **purges the
database first**. The two seeded operator accounts (`ops`, `oncall`) share the password
`operator-password`.

More documentation:

| Read | For |
|---|---|
| [CLAUDE.md](CLAUDE.md) | Project conventions, command reference, structure |
| [docs/frontend/](docs/frontend/) | Frontend architecture, conventions, data & forms |

## Releasing

*(Maintainer notes — self-hosters can skip this.)*

Publishing happens in [`.github/workflows/publish.yml`](.github/workflows/publish.yml),
which builds both images for `linux/amd64` and `linux/arm64` and pushes them to the GitHub
Container Registry. It runs on a pushed `v*` tag:

```bash
git tag v1.0.0
git push origin v1.0.0
```

A non-prerelease tag (no hyphen) moves `latest`; a prerelease like `v1.0.0-rc.1` publishes
only its own version tag. You can also trigger the workflow manually from the Actions tab
with **Run workflow** — run it against a tag ref so the version tags resolve.

Two one-time steps must be done by hand:

- **Make the GHCR packages public.** After the first publish the `pulse-api` and
  `pulse-app` packages are private; flip them to public in their GitHub package settings,
  or self-hosters cannot pull them.
- **Create the `NEXT_SERVER_ACTIONS_ENCRYPTION_KEY` repository secret** with
  `openssl rand -base64 32`. It keeps Next.js Server Action IDs stable across releases so
  open browser tabs keep working after a redeploy. Without it the build still succeeds; it
  just churns those IDs between releases.

## Licence

Pulse is released under the [MIT License](LICENSE).
