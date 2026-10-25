import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Emits .next/standalone — the minimal server bundle the production Docker
  // stage copies. Removing this breaks app/Dockerfile's `runner` stage.
  output: 'standalone',
  // Lets the dev server accept requests arriving under the OrbStack domain
  // instead of localhost.
  allowedDevOrigins: ['app.pulse.orb.local'],
  // Source maps for Sentry. Turbopack already writes the server's; this adds the
  // browser's, and debug IDs tie every shipped file to its map without matching
  // URLs. app/Dockerfile exports the maps in its `sourcemaps` stage for
  // publish.yml to upload, and keeps every .map out of the runner image.
  productionBrowserSourceMaps: true,
  turbopack: {
    debugIds: true,
  },
  // No image optimiser: Pulse renders no raster images, and its engine, sharp, is
  // a native module. Keeping it out of the standalone output leaves that output
  // plain JavaScript, which app/Dockerfile relies on to build it once for every
  // target architecture.
  images: {
    unoptimized: true,
  },
  outputFileTracingExcludes: {
    '*': ['node_modules/sharp/**', 'node_modules/@img/**'],
  },
};

export default nextConfig;
