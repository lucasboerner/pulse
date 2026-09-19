import { chromium } from "@playwright/test";
import { mkdirSync } from "node:fs";
import { dirname } from "node:path";

import { APP_URL, OPERATOR, STORAGE_STATE } from "./constants";
import { createOperator, resetDatabase } from "./helpers/docker";

// The one decision, written down and held to by every spec:
//
//   Reset the database to empty before the run, then create the single test operator.
//   The shipped demonstration fleet (AppStory / AppFixtures) is NOT assumed — it is not
//   loaded on boot, but a developer may have loaded it and a previous run leaves its own
//   monitors behind, and an empty database renders a different overview (the empty state)
//   than a populated one. So the run starts from empty and every spec seeds the monitors
//   it needs with unique URLs, depending on nothing another spec or a previous run left.
//   This is what lets the whole suite pass twice in a row with no cleanup in between.
//
// After resetting, this logs in once through the real UI (so the app mints its httpOnly
// session cookie exactly as it would for an operator) and saves the storage state for the
// authenticated specs to reuse. That same login also warms /login, / and /monitors, whose
// first compile under `next dev` is slow enough to blow past Playwright's default timeout.

async function globalSetup(): Promise<void> {
  resetDatabase();
  createOperator(OPERATOR.username, OPERATOR.email, OPERATOR.password);

  mkdirSync(dirname(STORAGE_STATE), { recursive: true });

  const browser = await chromium.launch();
  try {
    const page = await browser.newPage({ baseURL: APP_URL });

    await page.goto("/login");
    await page.getByLabel("Username").fill(OPERATOR.username);
    await page.getByLabel("Password").fill(OPERATOR.password);
    await page.getByRole("button", { name: "Sign in" }).click();

    // Correct credentials land on the overview — wait for it, which also warms the route.
    await page.getByRole("heading", { name: "Overview" }).waitFor({ timeout: 60_000 });

    // Warm the monitors route too, while signed in.
    await page.goto("/monitors");
    await page.getByRole("heading", { name: "Monitors" }).waitFor({ timeout: 60_000 });

    await page.context().storageState({ path: STORAGE_STATE });
  } finally {
    await browser.close();
  }
}

export default globalSetup;
