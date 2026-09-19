import { test, expect, type Request } from "@playwright/test";

import { createMonitorViaUi, monitorRow, statusBadge } from "./helpers/ui";
import { upTarget } from "./helpers/targets";

// Case 4 — Live flip. The headline test.
//
// Create a monitor, then watch its row flip from Pending to Up within seconds, in place,
// driven by a Mercure push patched into the client store — with no full-page reload and
// no server re-fetch of the route.
//
// Two things make this delicate and are handled deliberately:
//
//   - The Pending window is sub-second (the first check runs almost immediately), so the
//     test does not require catching Pending — the flip and the absence of a reload are
//     the load-bearing claims.
//
//   - "No reload" is proven POSITIVELY, and against BOTH regressions this case exists to
//     catch. A window sentinel dies on a location.reload(). A router.refresh() keeps the
//     window but re-fetches the route, so we also assert no GET to /monitors happens
//     during the flip. An assertion that merely checked the row's text changed would pass
//     under either regression.

const PAGE_PATH = "/monitors";

// A GET to the page route that is a real navigation/refresh — not a link prefetch. This
// is what a router.refresh() (RSC GET) or a location.reload() (document GET) would emit,
// and what a correct in-place patch never does.
function isRouteRefetch(request: Request): boolean {
  if (request.method() !== "GET") return false;
  let path: string;
  try {
    path = new URL(request.url()).pathname;
  } catch {
    return false;
  }
  if (path !== PAGE_PATH) return false;
  // Next prefetches links in the viewport; those are not a refresh.
  return request.headers()["next-router-prefetch"] !== "1";
}

test("a created monitor flips to Up in place, with no reload", async ({ page }) => {
  await page.goto(PAGE_PATH);

  const name = `E2E Flip ${Date.now()}`;
  await createMonitorViaUi(page, { name, url: upTarget() });

  const row = monitorRow(page, name);
  await expect(row).toBeVisible();

  const badge = statusBadge(row);
  // If we happen to catch it, the first state is Pending or already Up — either proves the
  // row rendered before the flip. We do not fail if the sub-second Pending state is missed.
  await expect(badge).toHaveText(/Pending|Up/);

  // Everything below is the "no reload" proof, set up strictly BEFORE the flip. The create
  // above already did its server-action revalidation, so nothing here is that write.
  await page.evaluate(() => {
    (window as Window & { __pulseSentinel?: string }).__pulseSentinel = "alive";
  });

  const routeRefetches: string[] = [];
  const onRequest = (request: Request) => {
    if (isRouteRefetch(request)) routeRefetches.push(`${request.method()} ${request.url()}`);
  };
  page.on("request", onRequest);

  // The flip itself: the row's badge becomes Up, patched in from the Mercure stream.
  await expect(badge).toHaveText("Up", { timeout: 20_000 });

  page.off("request", onRequest);

  const sentinelSurvived = await page.evaluate(
    () => (window as Window & { __pulseSentinel?: string }).__pulseSentinel,
  );
  expect(sentinelSurvived, "the page reloaded during the flip (window sentinel was lost)").toBe("alive");
  expect(
    routeRefetches,
    `the route was re-fetched during the flip (router.refresh/reload?): ${routeRefetches.join(", ")}`,
  ).toEqual([]);
});
