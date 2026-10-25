# syntax=docker/dockerfile:1

# Base stage
FROM node:22-alpine AS base
WORKDIR /app
COPY package.json yarn.lock ./
RUN yarn install --frozen-lockfile

# Development stage
FROM base AS dev
COPY . .
CMD ["yarn", "dev"]

# Build stage
FROM base AS builder
COPY . .
# NEXT_SERVER_ACTIONS_ENCRYPTION_KEY pins the key Next.js uses to derive Server
# Action IDs. Without it the key is regenerated every build, so each redeploy
# invalidates the Server Action IDs held by already-open browser tabs ("Failed to
# find Server Action … from an older or newer deployment"). Pinning it keeps IDs
# stable across builds. It MUST be set at build time — it is baked into the bundle
# here, so a runtime env on the compose service would be too late. Injected as a
# BuildKit secret (sensitive, never persisted to a layer); absent → unset, which
# falls back to the default per-build key.
RUN --mount=type=secret,id=next_actions_key,env=NEXT_SERVER_ACTIONS_ENCRYPTION_KEY \
    yarn build

# Production stage
FROM node:22-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production
COPY --from=builder /app/public ./public
# node-owned so the server can write its runtime cache (.next/cache) while
# running unprivileged.
COPY --from=builder --chown=node:node /app/.next/standalone ./
COPY --from=builder --chown=node:node /app/.next/static ./.next/static

EXPOSE 3000
ENV PORT=3000
ENV HOSTNAME="0.0.0.0"

# The Sentry release every event carries, server and browser (the root layout hands
# it to the page) — pulse@X.Y.Z, the same name the API reports. publish.yml passes
# the tag's version; a local build reports pulse@dev.
ARG VERSION=dev
ENV SENTRY_RELEASE=pulse@${VERSION}

# Unprivileged, matching the hardened API image (which runs as www-data).
USER node

CMD ["node", "server.js"]
