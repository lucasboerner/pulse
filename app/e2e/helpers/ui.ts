import { type Locator, type Page, expect } from "@playwright/test";

// Small UI flows shared by the specs, written as plain typed functions taking `page`.
// Each one ends on an assertion that the action landed, so a failure is reported where
// it happened rather than several steps downstream.

interface CreateOptions {
  name: string;
  url: string;
  /** An interval option label, e.g. "Every 15s". Left off, the form default (60s) stands. */
  intervalLabel?: string;
  /** A raw timeout value in ms, typed verbatim (so an invalid one can be tested). */
  timeoutMs?: string;
}

/** Open the New Monitor dialog, fill it and submit. Does not assert the result — the
 *  caller decides whether it expects success (dialog closes) or a validation error. */
export async function fillNewMonitor(page: Page, options: CreateOptions): Promise<void> {
  await page.getByRole("button", { name: "New Monitor" }).first().click();
  const dialog = page.getByRole("dialog");
  await expect(dialog.getByRole("heading", { name: "New Monitor" })).toBeVisible();

  await dialog.getByLabel("Name").fill(options.name);
  await dialog.getByLabel("Host / URL").fill(options.url);

  if (options.intervalLabel) {
    // The interval is a Radix Select whose trigger shows the current "Every …" value.
    await dialog.getByRole("combobox").filter({ hasText: /Every/ }).click();
    await page.getByRole("option", { name: options.intervalLabel, exact: true }).click();
  }

  if (options.timeoutMs !== undefined) {
    await dialog.getByLabel("Timeout (ms)").fill(options.timeoutMs);
  }

  await dialog.getByRole("button", { name: "Create Monitor" }).click();
}

/** Create a monitor through the dialog and wait for it to close (success path). */
export async function createMonitorViaUi(page: Page, options: CreateOptions): Promise<void> {
  await fillNewMonitor(page, options);
  await expect(page.getByRole("dialog")).toBeHidden();
}

/** The table row for a monitor, found by its (unique) name. The row is the one element
 *  carrying the `group` class in the monitors table. */
export function monitorRow(page: Page, name: string): Locator {
  return page.locator("div.group", { hasText: name }).first();
}

/** A monitor's on-screen status, read from its badge inside the given row/list scope. */
export function statusBadge(scope: Locator): Locator {
  return scope.locator('[data-slot="badge"]');
}

/** A Sonner toast carrying the given text. */
export function toast(page: Page, text: string): Locator {
  return page.locator("[data-sonner-toast]").filter({ hasText: text });
}
