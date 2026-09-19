import { defineConfig, devices } from "@playwright/test";

import { APP_URL, STORAGE_STATE } from "./e2e/constants";

// This suite drives an ALREADY-RUNNING stack (docker compose up -d), so there is no
// `webServer` here — an orchestration outside Playwright owns the app's lifecycle. The
// base URL is overridable so the same specs run against the local stack and CI.
export default defineConfig({
  testDir: "./e2e",

  // The Mercure topic (pulse://monitors) is a single global channel and the Postgres
  // database is shared across the whole stack, so two specs at once would see each
  // other's live updates and each other's monitors. Serial is correct here, not a
  // limitation to route around.
  fullyParallel: false,
  workers: 1,

  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,

  // Routes are warmed in global setup, but keep the per-test and navigation budgets
  // generous — a cold CI machine compiling a `next dev` route on first hit is slow, and
  // the live-flip assertion waits on a real worker check.
  timeout: 60_000,
  expect: { timeout: 15_000 },

  globalSetup: "./e2e/global-setup.ts",

  // A CI failure must be diagnosable without a rerun: trace and screenshot on first retry,
  // plus the GitHub annotations reporter and an HTML report to upload as an artifact.
  reporter: process.env.CI
    ? [["github"], ["html", { open: "never" }]]
    : [["list"], ["html", { open: "never" }]],

  use: {
    baseURL: APP_URL,
    trace: "on-first-retry",
    screenshot: "only-on-failure",
    navigationTimeout: 30_000,
    actionTimeout: 15_000,
    // The signed-in operator, minted once in global setup. The auth-gate and login specs
    // start signed-out; they override this per file.
    storageState: STORAGE_STATE,
  },

  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
});
