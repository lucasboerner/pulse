import { test, expect } from "@playwright/test";

import { apiToken, seedMonitor } from "./helpers/api";
import { fillNewMonitor } from "./helpers/ui";
import { upTarget } from "./helpers/targets";

// Case 6 — Validation errors.
//
// Two paths deserve coverage, and only the server one proves the JSON:API error mapping
// in mapJsonApiErrors:
//
//   - Server (422 → mapped onto a field): a duplicate URL and a timeout larger than the
//     interval both pass the client Zod schema and are rejected by the API, whose
//     source.pointer is mapped back onto the field.
//   - Client (Zod, no network): a blank required field is caught before any request.
//
// The ticket also lists "an interval below 15". That value cannot be produced through the
// UI — the interval control is a Select offering only values >= 15s — so it is guarded
// client-side structurally; the last test pins that guard rather than faking a submission.

test("a duplicate URL is rejected under the URL field (server 422, mapped)", async ({ page, request }) => {
  const token = await apiToken(request);
  const url = upTarget();
  await seedMonitor(request, token, { name: `E2E Dup ${Date.now()}`, url });

  await page.goto("/monitors");
  await fillNewMonitor(page, { name: `E2E Dup Retry ${Date.now()}`, url });

  const dialog = page.getByRole("dialog");
  await expect(dialog.getByText("A monitor with this URL and type already exists.")).toBeVisible();
  // The dialog stays open on a validation error.
  await expect(dialog.getByRole("heading", { name: "New Monitor" })).toBeVisible();
});

test("a timeout larger than the interval is rejected under the timeout field (server 422, mapped)", async ({ page }) => {
  await page.goto("/monitors");
  // 15s interval → max timeout 15000ms; 20000 passes Zod (a positive integer) but the API
  // rejects it, and the message must land under the Timeout field.
  await fillNewMonitor(page, {
    name: `E2E Timeout ${Date.now()}`,
    url: upTarget(),
    intervalLabel: "Every 15s",
    timeoutMs: "20000",
  });

  const dialog = page.getByRole("dialog");
  await expect(dialog.getByText("The timeout must not exceed the interval", { exact: false })).toBeVisible();
});

test("a blank name is rejected client-side by the Zod schema", async ({ page }) => {
  await page.goto("/monitors");
  // Leave Name empty; give a valid URL so only the name fails.
  await fillNewMonitor(page, { name: "", url: upTarget() });

  const dialog = page.getByRole("dialog");
  await expect(dialog.getByText("Name is required.")).toBeVisible();
});

test("the interval control offers no value below 15s", async ({ page }) => {
  await page.goto("/monitors");
  await page.getByRole("button", { name: "New Monitor" }).first().click();
  const dialog = page.getByRole("dialog");
  await expect(dialog.getByRole("heading", { name: "New Monitor" })).toBeVisible();

  await dialog.getByRole("combobox").filter({ hasText: /Every/ }).click();
  const options = await page.getByRole("option").allInnerTexts();
  // Every offered interval is 15s or longer — a sub-15 interval is unreachable through the UI.
  expect(options).toContain("Every 15s");
  expect(options).not.toContain("Every 10s");
  expect(options).not.toContain("Every 5s");
});
