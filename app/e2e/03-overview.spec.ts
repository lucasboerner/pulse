import { test, expect, chromium, type Browser } from "@playwright/test";

import { APP_URL, STORAGE_STATE } from "./constants";
import { apiToken, seedMonitor } from "./helpers/api";
import { upTarget } from "./helpers/targets";

// Case 3 — Overview render.
//
// The four stat blocks render with their real labels — Systems Up, Uptime 30d, Avg
// Response, Open Incidents (the ticket's old Monitors/Up/Down/Paused row no longer
// exists; see the redesign note) — and the "All Systems" list renders the monitors.
//
// The assertions run in a JavaScript-disabled context, which proves the page is correct
// from server-delivered markup alone, before any client JavaScript hydrates it.

let browser: Browser;
let monitorName: string;

test.beforeAll(async ({ request }) => {
  const token = await apiToken(request);
  monitorName = `E2E Overview ${Date.now()}`;
  await seedMonitor(request, token, { name: monitorName, url: upTarget() });
  browser = await chromium.launch();
});

test.afterAll(async () => {
  await browser.close();
});

test("the four stat blocks and the monitor list render in server markup", async () => {
  // A fresh context with the operator session but no client JavaScript.
  const context = await browser.newContext({
    baseURL: APP_URL,
    storageState: STORAGE_STATE,
    javaScriptEnabled: false,
  });
  const page = await context.newPage();
  try {
    await page.goto("/");

    for (const label of ["Systems Up", "Uptime 30d", "Avg Response", "Open Incidents"]) {
      await expect(page.getByText(label, { exact: true })).toBeVisible();
    }

    await expect(page.getByText("All Systems")).toBeVisible();
    await expect(page.getByText(monitorName)).toBeVisible();
  } finally {
    await context.close();
  }
});
