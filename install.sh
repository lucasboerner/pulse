#!/usr/bin/env bash
#
# Pulse — self-hosting installer.
#
# Copy this one file to the server and run it. It fetches the compose files it
# needs, generates every secret, asks for the handful of things it cannot know,
# and writes a ready-to-use `.env` next to itself:
#
#   curl -fsSLO https://raw.githubusercontent.com/lucasboerner/pulse/main/install.sh
#   bash install.sh
#
# It writes files and starts nothing. The last thing it prints is the command
# that brings the stack up.
#
# Running it again in a *different* directory gives a second, independent
# instance on the same host: it asks for an instance name (compose project +
# traefik router) and picks host ports nothing is listening on yet.
#
# Flags:
#   --no-download   use the compose files already in this directory
#   --force         overwrite an existing .env (the old one is backed up)

set -euo pipefail

RAW_BASE="https://raw.githubusercontent.com/lucasboerner/pulse/main"
ENV_FILE=".env"
COMPOSE_FILES=(compose.selfhost.yaml compose.traefik.yaml)

DOWNLOAD=1
FORCE=0
for arg in "$@"; do
  case "$arg" in
    --no-download) DOWNLOAD=0 ;;
    --force) FORCE=1 ;;
    -h|--help) sed -n '2,/^[^#]/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown argument: $arg" >&2; exit 2 ;;
  esac
done

bold() { printf '\033[1m%s\033[0m\n' "$1"; }
die() { printf '\033[31m%s\033[0m\n' "$1" >&2; exit 1; }

# port_in_use PORT — true when something on this host already listens there.
port_in_use() { (exec 3<>"/dev/tcp/127.0.0.1/$1") >/dev/null 2>&1; }

# free_port BASE — the first port at or above BASE that nothing answers on.
free_port() {
  local p=$1
  while port_in_use "$p"; do p=$((p + 1)); done
  printf '%s' "$p"
}

# ── Preflight ───────────────────────────────────────────────────────────────

command -v docker >/dev/null || die "docker is not installed."
docker compose version >/dev/null 2>&1 || die "the docker compose plugin is not installed."
command -v openssl >/dev/null || die "openssl is not installed (needed to generate secrets)."
command -v curl >/dev/null || DOWNLOAD=0

if [ -e "$ENV_FILE" ]; then
  [ "$FORCE" -eq 1 ] || die "$ENV_FILE already exists. Move it aside, or re-run with --force."
  ENV_BACKUP="$ENV_FILE.bak.$(date -u '+%Y%m%d%H%M%S')"
  cp -p "$ENV_FILE" "$ENV_BACKUP"
  echo "Backed the old $ENV_FILE up to $ENV_BACKUP"
fi

if [ "$DOWNLOAD" -eq 1 ]; then
  for f in "${COMPOSE_FILES[@]}"; do
    [ -e "$f" ] && continue
    echo "Fetching $f"
    curl -fsSL "$RAW_BASE/$f" -o "$f" || die "could not fetch $f from $RAW_BASE"
  done
fi
for f in "${COMPOSE_FILES[@]}"; do
  [ -e "$f" ] || die "$f is missing. Drop it next to this script, or re-run without --no-download."
done

# ── Prompts ─────────────────────────────────────────────────────────────────

# ask VAR "Question" "default"  — empty default means the answer is required.
ask() {
  local __var=$1 __question=$2 __default=${3:-} __reply=""
  while :; do
    if [ -n "$__default" ]; then
      read -r -p "$__question [$__default]: " __reply || die "aborted."
      __reply=${__reply:-$__default}
    else
      read -r -p "$__question: " __reply || die "aborted."
    fi
    [ -n "$__reply" ] && break
    echo "  This one is required."
  done
  printf -v "$__var" '%s' "$__reply"
}

echo
bold "Pulse installer"
echo "Secrets are generated for you. Press Enter to accept a default in [brackets]."
echo

ask PULSE_HOST "Public hostname (no scheme), e.g. pulse.example.com"
PULSE_HOST=${PULSE_HOST#http://}
PULSE_HOST=${PULSE_HOST#https://}
PULSE_HOST=${PULSE_HOST%%/*}
case "$PULSE_HOST" in
  *.*) : ;;
  *) die "\"$PULSE_HOST\" does not look like a hostname." ;;
esac

# The instance name is both the compose project name (container, volume and
# network prefix) and the traefik router name. Two Pulse stacks on one host must
# differ in both or they collide — same volumes, or one router silently winning
# over the other. Defaults to the first label of the hostname.
sanitize() { printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9_-' '-'; }
echo
ask INSTANCE "Instance name (compose project + traefik router)" "$(sanitize "${PULSE_HOST%%.*}")"
INSTANCE=$(sanitize "$INSTANCE")
case "$INSTANCE" in
  [a-z0-9]*) : ;;
  *) die "the instance name must start with a letter or a digit." ;;
esac

echo
echo "Traefik — the proxy already running on this server."
ask TRAEFIK_NETWORK "  Docker network Traefik reads" "traefik"
ask TRAEFIK_ENTRYPOINT "  Name of its https entrypoint" "websecure"
ask TRAEFIK_CERTRESOLVER "  Name of its certificate resolver" "letsencrypt"

if ! docker network inspect "$TRAEFIK_NETWORK" >/dev/null 2>&1; then
  echo
  echo "  Warning: no docker network named \"$TRAEFIK_NETWORK\" exists yet."
  echo "  \`docker network ls\` lists the names. The stack will not start until it matches."
fi

echo
echo "Mail — where alert mail is sent from. Leave the DSN at the default to disable it."
ask MAILER_DSN "  Mailer DSN" "null://null"
ask MAILER_FROM "  From address" "Pulse <no-reply@$PULSE_HOST>"

echo
echo "Database — runs in a container on this host, credentials never leave it."
ask POSTGRES_DB "  Database name" "pulse"
ask POSTGRES_USER "  Database user" "pulse"

# ── Host ports ──────────────────────────────────────────────────────────────

# Only local debugging binds these, so any free port will do — but a second
# instance must not reuse the first one's, hence the probe.
APP_PORT_N=$(free_port 3000)
MERCURE_PORT_N=$(free_port $((APP_PORT_N + 1)))
API_PORT_N=$(free_port 8080)

# ── Generated secrets ───────────────────────────────────────────────────────

# Hex only: the password is interpolated into a URL-shaped DATABASE_URL, where
# anything needing percent-encoding would break the DSN.
POSTGRES_PASSWORD=$(openssl rand -hex 24)
APP_SECRET=$(openssl rand -hex 16)
MERCURE_JWT_SECRET=$(openssl rand -hex 32)

# ── Write .env ──────────────────────────────────────────────────────────────

# Dots are escaped because CORS_ALLOW_ORIGIN is a regular expression.
CORS_HOST=${PULSE_HOST//./\\.}

(
  umask 077
  cat > "$ENV_FILE" <<ENV
# Pulse — written by install.sh on $(date -u '+%Y-%m-%d %H:%M UTC'). Contains
# secrets: keep it next to the compose files, never commit it.
#
#   docker compose up -d          # COMPOSE_FILE below wires both files in

# ── Compose ─────────────────────────────────────────────────────────────────
# Pins the project name, so volume and container names do not depend on what
# this directory happens to be called — and so a second instance on this host
# keeps its own containers, volumes and network.
COMPOSE_PROJECT_NAME=$INSTANCE
COMPOSE_FILE=compose.selfhost.yaml:compose.traefik.yaml

# ── Public identity ─────────────────────────────────────────────────────────
PULSE_HOST=$PULSE_HOST
APP_FRONTEND_URL=https://$PULSE_HOST
DEFAULT_URI=https://$PULSE_HOST
CORS_ALLOW_ORIGIN=^https://$CORS_HOST$

# ── Traefik (already running on this host) ──────────────────────────────────
# PULSE_ROUTER names the router and the service. It must be unique per stack:
# two containers labelling the same router name conflict inside Traefik.
PULSE_ROUTER=$INSTANCE
TRAEFIK_NETWORK=$TRAEFIK_NETWORK
TRAEFIK_ENTRYPOINT=$TRAEFIK_ENTRYPOINT
TRAEFIK_CERTRESOLVER=$TRAEFIK_CERTRESOLVER

# ── Secrets (generated — no need to ever read these) ────────────────────────
APP_SECRET=$APP_SECRET
MERCURE_JWT_SECRET=$MERCURE_JWT_SECRET
POSTGRES_PASSWORD=$POSTGRES_PASSWORD

# ── Database ────────────────────────────────────────────────────────────────
POSTGRES_DB=$POSTGRES_DB
POSTGRES_USER=$POSTGRES_USER

# ── Mail ────────────────────────────────────────────────────────────────────
MAILER_DSN=$MAILER_DSN
MAILER_FROM=$MAILER_FROM

# ── Host ports ──────────────────────────────────────────────────────────────
# Traefik reaches the containers over the docker network, so these published
# ports exist only for local debugging and stay bound to loopback. They were
# probed for free when this file was written — a sibling stack that happened to
# be stopped then looks free, so check these if \`up\` reports a bound port.
APP_PORT=127.0.0.1:$APP_PORT_N
API_PORT=127.0.0.1:$API_PORT_N
MERCURE_PORT=127.0.0.1:$MERCURE_PORT_N

# ── Optional ────────────────────────────────────────────────────────────────
# IMAGE_TAG=latest                # pin a release instead of tracking latest
# PULSE_RAW_RETENTION_DAYS=7      # days of raw check results (rollups are kept)
ENV
)

echo
bold "Wrote $ENV_FILE"
echo "  https://$PULSE_HOST · instance \"$INSTANCE\" · router on the \"$TRAEFIK_NETWORK\" network"
echo "  Loopback ports: app $APP_PORT_N · api $API_PORT_N · mercure $MERCURE_PORT_N"
[ "$MAILER_DSN" = "null://null" ] && echo "  Alert mail is disabled (MAILER_DSN=null://null)."

cat <<NEXT

Next, in this directory:

  1. Point $PULSE_HOST at this server (A record), if you have not already.
  2. docker compose up -d
  3. docker compose exec api bin/console app:user:create
  4. Open https://$PULSE_HOST and sign in.

There is no registration — step 3 is how the first operator account is made.
NEXT
