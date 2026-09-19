import { test, expect } from "@playwright/test";

import { apiToken, seedMonitor } from "./helpers/api";
import { monitorRow, statusBadge } from "./helpers/ui";
import { upTarget } from "./helpers/targets";

// Case 9 — Design invariants that are actually invariant.
//
// The PULSE wordmark is present; the sidebar nav reads Overview and Monitors; and a
// monitor's status is conveyed by a dot or badge — never by filling the row or card with
// a status colour, which is a genuine product regression this case exists to catch.
//
// The ticket's radius assertion is DROPPED: the redesign set --radius: 8px with a full
// scale (rounded-lg on the stat row, rounded-sm on the nav), so "radius 0" is dead. Being
// sparing with design assertions, nothing here fails on a restyle that keeps these rules.

test("the wordmark and the sidebar navigation are present", async ({ page, request }) => {
  const token = await apiToken(request);
  await seedMonitor(request, token, { name: `E2E Design ${Date.now()}`, url: upTarget() });

  await page.goto("/monitors");

  await expect(page.getByText("PULSE")).toBeVisible();
  // exact: true — monitor detail links carry the monitor's name, which can contain
  // "Overview"/"Monitors"; the nav links are exactly those words.
  await expect(page.getByRole("link", { name: "Overview", exact: true })).toBeVisible();
  await expect(page.getByRole("link", { name: "Monitors", exact: true })).toBeVisible();
});

test("a monitor's status is a badge, not a filled row", async ({ page, request }) => {
  const token = await apiToken(request);
  const name = `E2E Badge ${Date.now()}`;
  await seedMonitor(request, token, { name, url: upTarget() });

  await page.goto("/monitors");
  const row = monitorRow(page, name);
  await expect(row).toBeVisible();

  // Status is shown in the badge.
  await expect(statusBadge(row)).toBeVisible();

  // The row element itself carries no status-colour fill — a redesign that filled the row
  // with the status hue instead of using the badge would add exactly such a class here.
  const rowClass = (await row.getAttribute("class")) ?? "";
  expect(rowClass).not.toMatch(/bg-(success|destructive|warning)/);
});

test("the overview conveys status with a dot", async ({ page, request }) => {
  const token = await apiToken(request);
  const name = `E2E Dot ${Date.now()}`;
  await seedMonitor(request, token, { name, url: upTarget() });

  await page.goto("/");
  const item = page.locator("li", { hasText: name }).first();
  await expect(item).toBeVisible();
  // The one round thing in the interface: the status dot.
  await expect(item.locator("span.rounded-full").first()).toBeVisible();
});
