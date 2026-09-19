import { test, expect, type Page } from "@playwright/test";

import { startService, stopService } from "./helpers/docker";
import { createMonitorViaUi, monitorRow, toast } from "./helpers/ui";
import { upTarget } from "./helpers/targets";

// Case 8 — Dead-hub resilience.
//
// With the Mercure hub unreachable, every page still loads and every action still works:
// no error overlay, no toast storm, no unhandled rejection. The proxy answers 502 when
// the hub is down (and 503 when it is unconfigured); the client must treat both as "no
// live updates", never as an error to surface.
//
// This runs last (file 09) so it cannot poison the live-flip case, and it restarts the
// hub afterwards so the next run — and the rest of the stack — is unaffected.

test.describe.configure({ mode: "serial" });

test.beforeAll(() => {
  stopService("mercure");
});

test.afterAll(() => {
  startService("mercure");
});

// The retrying EventSource against the dead /mercure proxy logs benign network errors to
// the console; those are expected. What must NOT happen is an uncaught exception (an
// unhandled rejection) — collect those and assert none.
function trackPageErrors(page: Page): string[] {
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  return errors;
}

async function expectHealthyPage(page: Page): Promise<void> {
  // No runtime error overlay, and no error toast storm.
  await expect(page.locator("[data-nextjs-dialog-overlay]")).toHaveCount(0);
  await expect(page.locator('[data-sonner-toast][data-type="error"]')).toHaveCount(0);
}

test("the overview and the monitors table still load with the hub down", async ({ page }) => {
  const errors = trackPageErrors(page);

  await page.goto("/");
  await expect(page.getByRole("heading", { name: "Overview" })).toBeVisible();
  await expectHealthyPage(page);

  await page.getByRole("link", { name: "Monitors", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Monitors" })).toBeVisible();
  await expectHealthyPage(page);

  expect(errors, `unhandled errors with the hub down: ${errors.join(", ")}`).toEqual([]);
});

test("creating and pausing a monitor still works with the hub down", async ({ page }) => {
  const errors = trackPageErrors(page);

  await page.goto("/monitors");
  const name = `E2E DeadHub ${Date.now()}`;
  await createMonitorViaUi(page, { name, url: upTarget() });
  await expect(toast(page, "Monitor created")).toBeVisible();

  const row = monitorRow(page, name);
  await expect(row).toBeVisible();
  await row.getByRole("switch").click();
  await expect(toast(page, `${name} paused.`)).toBeVisible();

  await expectHealthyPage(page);
  expect(errors, `unhandled errors with the hub down: ${errors.join(", ")}`).toEqual([]);
});
