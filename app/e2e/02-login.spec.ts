import { test, expect } from "@playwright/test";

import { OPERATOR } from "./constants";

// Case 2 — Login.
//
// Wrong credentials surface one generic message; correct credentials land on the
// overview; and a signed-in visit to /login bounces straight back to the overview.

// These manage their own session: start signed-out.
test.use({ storageState: { cookies: [], origins: [] } });

async function signIn(page: import("@playwright/test").Page, username: string, password: string): Promise<void> {
  await page.goto("/login");
  await page.getByLabel("Username").fill(username);
  await page.getByLabel("Password").fill(password);
  await page.getByRole("button", { name: "Sign in" }).click();
}

test("wrong credentials show Invalid credentials.", async ({ page }) => {
  await signIn(page, OPERATOR.username, "not-the-password");

  await expect(page.getByText("Invalid credentials.")).toBeVisible();
  await expect(page).toHaveURL(/\/login$/);
});

test("correct credentials land on the overview", async ({ page }) => {
  await signIn(page, OPERATOR.username, OPERATOR.password);

  await expect(page).toHaveURL(/\/$/);
  await expect(page.getByRole("heading", { name: "Overview" })).toBeVisible();
});

test("a visit to /login while signed in bounces to /", async ({ page }) => {
  await signIn(page, OPERATOR.username, OPERATOR.password);
  await expect(page.getByRole("heading", { name: "Overview" })).toBeVisible();

  await page.goto("/login");

  await expect(page).toHaveURL(/\/$/);
  await expect(page.getByRole("heading", { name: "Overview" })).toBeVisible();
});
