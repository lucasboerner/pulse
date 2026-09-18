import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Emits .next/standalone — the minimal server bundle the production Docker
  // stage copies. Removing this breaks app/Dockerfile's `runner` stage.
  output: 'standalone',
  // Lets the dev server accept requests arriving under the OrbStack domain
  // instead of localhost.
  allowedDevOrigins: ['app.pulse.orb.local'],
};

export default nextConfig;
