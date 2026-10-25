# syntax=docker/dockerfile:1

# Base stage — on the build host's own platform. Only dev and builder use it; the
# runner below is the one stage built per target architecture.
FROM --platform=$BUILDPLATFORM node:22-alpine AS base
WORKDIR /app
COPY package.json yarn.lock ./
RUN yarn install --frozen-lockfile

# Development stage
FROM base AS dev
COPY . .
CMD ["yarn", "dev"]

# Build stage — runs once, whatever the target platforms. publish.yml builds
# linux/amd64 and linux/arm64, and two `next build` runs never emit the same chunks
# (chunk names, build ID, Server Action IDs differ), so source maps uploaded from
# one could not resolve the other's stack traces. Built once, both images ship the
# identical bundle the `sourcemaps` stage below exports. That holds only while the
# standalone output is plain JavaScript — a native module would be the build
# host's — so the build fails if one appears (next.config.ts keeps sharp out).
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
RUN if find .next/standalone -name '*.node' | grep .; then \
      echo 'Native modules (above) in the standalone output: built for the build host, they break the other architecture.' >&2; \
      exit 1; \
    fi

# Source maps for Sentry — browser and server bundles with their maps, and the
# build ID that proves which build they belong to. publish.yml exports this stage
# (`--output type=local`) and uploads it once per release; no image carries it.
FROM scratch AS sourcemaps
COPY --from=builder /app/.next/BUILD_ID /BUILD_ID
COPY --from=builder /app/.next/static /_next/static
COPY --from=builder /app/.next/server /_next/server

# Production stage
FROM node:22-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production
COPY --from=builder /app/public ./public
# node-owned so the server can write its runtime cache (.next/cache) while
# running unprivileged. No source maps: they reach Sentry through the stage above.
COPY --from=builder --chown=node:node --exclude=**/*.map /app/.next/standalone ./
COPY --from=builder --chown=node:node --exclude=**/*.map /app/.next/static ./.next/static

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
