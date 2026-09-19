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

You need a VPS with **Docker Engine, the Compose plugin, and the Traefik you already
front your other containers with**. Pulse ships no reverse proxy of its own — it adds one
router to yours. Nothing else is installed on the host; the images ship everything.

1. **Point a hostname at the server.** An `A` record for, say, `pulse.example.com`.

2. **Run the installer.** Copy this one file to the server and run it: it fetches the
   compose files, generates every secret, asks for the handful of things it cannot know,
   and writes a ready-to-use `.env`. It starts nothing.

   ```bash
   mkdir -p /opt/pulse && cd /opt/pulse
   curl -fsSLO https://raw.githubusercontent.com/lucasboerner/pulse/main/install.sh
   bash install.sh
   ```

   Eight questions, seven of them with a default you accept with Enter:

   | Prompt | Where the answer comes from | Default |
   |---|---|---|
   | Public hostname | the `A` record from step 1 | — |
   | Docker network Traefik reads | `docker network ls` — the one your other routed containers sit on | `traefik` |
   | Https entrypoint | Traefik's static config, `entryPoints:` — the one on `:443` | `websecure` |
   | Certificate resolver | Traefik's static config, `certificatesResolvers:` | `letsencrypt` |
   | Mailer DSN | your SMTP transport; the default disables alert mail | `null://null` |
   | From address | header on every alert mail | `Pulse <no-reply@HOST>` |
   | Database name / user | anything; it never leaves this host | `pulse` / `pulse` |

   `APP_SECRET`, `MERCURE_JWT_SECRET` and the database password are generated with
   `openssl rand`, and the frontend URL and CORS pattern are derived from the hostname.
   You never have to invent or paste a secret.

3. **Start the stack:**

   ```bash
   docker compose up -d
   ```

   No `-f` flags are needed: the generated `.env` sets `COMPOSE_FILE` to both compose
   files and pins the project name to `pulse`. First boot waits for PostgreSQL, generates
   the JWT signing keys, and applies the database migrations before the API reports
   healthy.

4. **Create the first user.** There is no registration — you make operator accounts from
   the command line. This step is not optional; without it you cannot log in:

   ```bash
   docker compose exec api bin/console app:user:create
   ```

   It prompts for a username, an e-mail address (where this operator's alert mail goes)
   and a password.

5. **Open `https://pulse.example.com` and log in** with the account you just created.

6. **Add a monitor.** Give it a URL and a check interval; the first result lands within a
   few seconds and the detail page starts filling in.

### How it is wired

The installer leaves three files in the directory: `compose.selfhost.yaml` (the stack —
`api`, `worker`, `scheduler`, `app`, `mercure`, `database`), `compose.traefik.yaml` (the
overlay) and `.env`. The overlay joins the `app` container to your existing Traefik
network and labels it with a single router on your hostname. Traefik itself is untouched.

Only the frontend is routed. The browser never calls the API — all data is fetched
server-side — and **the Mercure hub needs no rule of its own** either, because the
frontend streams live updates same-origin through its own `/mercure` route. That is a
real simplification: most comparable projects make you add a rule for the hub.

The stack's own host ports (`3000`, `8080`, `3001`) are bound to `127.0.0.1` by the
installer, so nothing but the proxy and a local shell can reach them.

Three things worth knowing:

- The session cookie is `Secure` in production, so the site must be served over https.
  Logging in over plain http fails silently.
- Serving a wildcard or otherwise static certificate instead of ACME? Drop the
  `tls.certresolver` label from `compose.traefik.yaml` and leave `tls` on its own.
- Do not put a `compress` middleware on this router — `/mercure` is a long-lived
  `text/event-stream` that must not be buffered or transformed.

## Configuration

`install.sh` writes every variable below into `.env`. Edit that file by hand only to
change something afterwards, then `docker compose up -d` to apply it.

| Variable | Required | Default | What it does |
|---|---|---|---|
| `PULSE_HOST` | yes | — | Public hostname the Traefik router matches. |
| `TRAEFIK_NETWORK` | yes | `traefik` | External docker network Traefik reads. |
| `TRAEFIK_ENTRYPOINT` | yes | `websecure` | Name of Traefik's https entrypoint. |
| `TRAEFIK_CERTRESOLVER` | yes | `letsencrypt` | Name of Traefik's certificate resolver. |
| `APP_SECRET` | yes | — | Application crypto secret. Generated with `openssl rand -hex 16`. |
| `POSTGRES_DB` | yes | — | Database name. |
| `POSTGRES_USER` | yes | — | Database user. |
| `POSTGRES_PASSWORD` | yes | — | Database password. Hex only: it is interpolated into a URL-shaped DSN. |
| `MAILER_DSN` | yes | — | Mail transport for alerts, e.g. `smtp://user:pass@smtp.example.com:587`. `null://null` disables mail. |
| `MAILER_FROM` | yes | — | Default `From` header, e.g. `Pulse <no-reply@pulse.example.com>`. |
| `CORS_ALLOW_ORIGIN` | yes | — | Allowed browser origin as a regex, e.g. `^https://pulse\.example\.com$`. |
| `APP_FRONTEND_URL` | yes | — | Public URL of the frontend, used in alert-mail links. |
| `DEFAULT_URI` | yes | — | Base URL for links built off-request (CLI/worker contexts). |
| `MERCURE_JWT_SECRET` | yes | — | Signs and validates the live-update tokens. Generated with `openssl rand -hex 32`. |
| `COMPOSE_FILE` | no | *(both files)* | Lets every `docker compose` command run without `-f` flags. |
| `COMPOSE_PROJECT_NAME` | no | `pulse` | Keeps container and volume names independent of the directory name. |
| `IMAGE_TAG` | no | `latest` | Which published image tag to pull. |
| `APP_PORT` | no | `127.0.0.1:3000` | Host binding for the frontend. Traefik reaches it over the docker network instead. |
| `API_PORT` | no | `127.0.0.1:8080` | Host binding for the API — local debugging only. |
| `MERCURE_PORT` | no | `127.0.0.1:3001` | Host binding for the Mercure hub — local debugging only. |
| `PULSE_RAW_RETENTION_DAYS` | no | `7` | Days of raw check results to keep. Widening it stores more raw detail; hourly rollups are kept forever regardless. |
| `POSTGRES_VERSION` | no | `16` | `serverVersion` in the database connection string. |
| `POSTGRES_CHARSET` | no | `utf8` | Charset in the database connection string. |
| `JWT_SECRET_KEY` | no | `/data/jwt/private.pem` | Private signing-key path. Generated on first boot if absent. |
| `JWT_PUBLIC_KEY` | no | `/data/jwt/public.pem` | Public signing-key path. Generated on first boot if absent. |
| `JWT_PASSPHRASE` | no | *(empty)* | Encrypts the private key. Empty means an unencrypted key on a volume only you can read. |

The JWT keys are generated automatically on the first `up` and reused after, so you never
have to create them. They live on the persisted `api_data` volume and survive
`docker compose down`. Set the paths only if you want to supply your own key pair.

Re-running `install.sh --force` writes a fresh `.env` (backing the old one up first) —
which rolls every generated secret. Changing `APP_SECRET` or the database password on a
running instance means the stack can no longer read its own database, so edit `.env` by
hand instead.

## Updating and backing up

**Update** to a newer release by moving `IMAGE_TAG` (or leaving it at `latest`) and
pulling:

```bash
docker compose pull
docker compose up -d
```

Database migrations run automatically when the `api` container boots the new image.

**Back up** the two named volumes:

- `database_data` — all monitors, results, rollups and incidents.
- `api_data` — the JWT signing keys (and other runtime state). Losing it forces new keys,
  which logs everyone out until they sign in again.

## Development

The development stack (`compose.yaml`) builds the images locally and adds Mailpit as a
mail sink. It is separate from the self-hosting stack above, needs no proxy, and is not
needed to run Pulse.

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
