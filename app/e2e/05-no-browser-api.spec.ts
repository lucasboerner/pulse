import { test, expect } from "@playwright/test";

import { apiToken, seedMonitor } from "./helpers/api";
import { upTarget } from "./helpers/targets";

// Case 5 — No browser API calls. The highest-value test in the suite.
//
// Reads happen in Server Components and writes in Server Actions; the JWT lives in an
// httpOnly cookie no client code touches. So across the overview and the monitors table,
// with interactions, the browser must never issue a request whose path starts with /api/.
// This is the test that catches somebody quietly adding a client-side fetch and leaking
// the token path.
//
// Allowed browser traffic: the same-origin /mercure stream, Server Action POSTs to the
// page's own path, and Next.js's own /_next/* assets — none of which start with /api/.

test("no request to /api/* is made from the browser across / and /monitors", async ({ page, request }) => {
  const token = await apiToken(request);
  await seedMonitor(request, token, { name: `E2E NoApi ${Date.now()}`, url: upTarget() });

  const apiCalls: string[] = [];
  page.on("request", (req) => {
    let path: string;
    try {
      path = new URL(req.url()).pathname;
    } catch {
      return;
    }
    if (path.startsWith("/api/")) apiCalls.push(`${req.method()} ${path}`);
  });

  // Overview, with an interaction that mounts a client component (the New Monitor dialog).
  await page.goto("/");
  await expect(page.getByRole("heading", { name: "Overview" })).toBeVisible();
  await page.getByRole("button", { name: "New Monitor" }).first().click();
  await expect(page.getByRole("dialog").getByRole("heading", { name: "New Monitor" })).toBeVisible();
  await page.getByRole("dialog").getByLabel("Name").fill("scratch");
  await page.keyboard.press("Escape");

  // Client navigation to the table, then client-only interactions over its rows.
  await page.getByRole("link", { name: "Monitors", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Monitors" })).toBeVisible();
  await page.getByPlaceholder("Search name or host").fill("e2e");
  await page.getByRole("button", { name: "Edit", exact: true }).first().click();
  await expect(page.getByRole("dialog").getByRole("heading", { name: "Edit Monitor" })).toBeVisible();
  await page.keyboard.press("Escape");

  expect(apiCalls, `browser hit the API directly: ${apiCalls.join(", ")}`).toEqual([]);
});
