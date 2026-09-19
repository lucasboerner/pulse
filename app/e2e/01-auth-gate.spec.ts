import { test, expect } from "@playwright/test";

// Case 1 — Auth gate.
//
// The middleware gates every non-public path on the session cookie. An unauthenticated
// visit bounces to /login without the browser ever touching the API (the redirect is
// decided server-side), and a deep link is preserved in a ?next= parameter so an alert
// mail's link survives the sign-in bounce.

// These run signed-out: drop the operator session the project sets by default.
test.use({ storageState: { cookies: [], origins: [] } });

test("an unauthenticated visit to / lands on /login without any browser API call", async ({ page }) => {
  const apiCalls: string[] = [];
  page.on("request", (request) => {
    const path = new URL(request.url()).pathname;
    if (path.startsWith("/api/")) apiCalls.push(`${request.method()} ${path}`);
  });

  await page.goto("/");

  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByRole("button", { name: "Sign in" })).toBeVisible();
  expect(apiCalls, `browser called the API on the way to /login: ${apiCalls.join(", ")}`).toEqual([]);
});

test("an unauthenticated visit to /monitors lands on /login", async ({ page }) => {
  const apiCalls: string[] = [];
  page.on("request", (request) => {
    const path = new URL(request.url()).pathname;
    if (path.startsWith("/api/")) apiCalls.push(`${request.method()} ${path}`);
  });

  await page.goto("/monitors");

  await expect(page).toHaveURL(/\/login\?next=%2Fmonitors$/);
  expect(apiCalls, `browser called the API on the way to /login: ${apiCalls.join(", ")}`).toEqual([]);
});

test("a deep link to /monitors/<id> is preserved in ?next=", async ({ page }) => {
  const id = "0193aaaa-bbbb-cccc-dddd-eeeeeeeeeeee";
  await page.goto(`/monitors/${id}`);

  // The middleware URL-encodes the requested path into the next parameter.
  await expect(page).toHaveURL(new RegExp(`/login\\?next=%2Fmonitors%2F${id}$`));
});
