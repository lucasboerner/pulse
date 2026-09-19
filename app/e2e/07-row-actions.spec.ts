import { test, expect } from "@playwright/test";

import { apiToken, seedMonitor } from "./helpers/api";
import { monitorRow, toast } from "./helpers/ui";
import { upTarget } from "./helpers/targets";

// Case 7 — Row actions on /monitors: edit through the dialog, pause/resume through the
// row switch (optimistic), delete through the confirm dialog. The toast copy is part of
// the contract, so it is asserted.

test("edit a monitor's name, url and interval through the dialog", async ({ page, request }) => {
  const token = await apiToken(request);
  const original = `E2E Edit ${Date.now()}`;
  await seedMonitor(request, token, { name: original, url: upTarget() });

  await page.goto("/monitors");
  const row = monitorRow(page, original);
  await expect(row).toBeVisible();
  // exact: true — the Delete button's aria-label ("Delete <name>") can contain "Edit".
  await row.getByRole("button", { name: "Edit", exact: true }).click();

  const dialog = page.getByRole("dialog");
  await expect(dialog.getByRole("heading", { name: "Edit Monitor" })).toBeVisible();

  const renamed = `${original} renamed`;
  await dialog.getByLabel("Name").fill(renamed);
  await dialog.getByLabel("Host / URL").fill(upTarget());
  await dialog.getByRole("combobox").filter({ hasText: /Every/ }).click();
  await page.getByRole("option", { name: "Every 30s", exact: true }).click();
  await dialog.getByRole("button", { name: "Save Changes" }).click();

  await expect(page.getByRole("dialog")).toBeHidden();
  await expect(toast(page, `${renamed} updated.`)).toBeVisible();
  await expect(monitorRow(page, renamed)).toBeVisible();
});

test("pause and resume a monitor through the row switch", async ({ page, request }) => {
  const token = await apiToken(request);
  const name = `E2E Pause ${Date.now()}`;
  await seedMonitor(request, token, { name, url: upTarget() });

  await page.goto("/monitors");
  const row = monitorRow(page, name);
  const toggle = row.getByRole("switch");
  await expect(toggle).toHaveAttribute("aria-label", "Pause monitoring");

  await toggle.click();
  // Optimistic: the control reflects the paused state, and the toast confirms the write.
  await expect(row.getByRole("switch")).toHaveAttribute("aria-label", "Resume monitoring");
  await expect(toast(page, `${name} paused.`)).toBeVisible();

  await row.getByRole("switch").click();
  await expect(row.getByRole("switch")).toHaveAttribute("aria-label", "Pause monitoring");
  await expect(toast(page, `${name} resumed.`)).toBeVisible();
});

test("delete a monitor through the confirm dialog", async ({ page, request }) => {
  const token = await apiToken(request);
  const name = `E2E Delete ${Date.now()}`;
  await seedMonitor(request, token, { name, url: upTarget() });

  await page.goto("/monitors");
  const row = monitorRow(page, name);
  await expect(row).toBeVisible();
  await row.getByRole("button", { name: `Delete ${name}` }).click();

  const dialog = page.getByRole("dialog");
  await expect(dialog.getByRole("heading", { name: "Delete Monitor" })).toBeVisible();
  await dialog.getByRole("button", { name: "Delete", exact: true }).click();

  await expect(toast(page, `${name} deleted. History kept.`)).toBeVisible();
  await expect(monitorRow(page, name)).toHaveCount(0);
});
