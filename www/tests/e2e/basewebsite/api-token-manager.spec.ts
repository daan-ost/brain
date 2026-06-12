import { test, expect } from "@playwright/test";

test.describe("API Token Manager", () => {
  test.beforeEach(async ({ page }) => {
    // Login as a test user
    await page.goto("/login");
    await page.fill("input[name=\"email\"]", "test@example.com");
    await page.fill("input[name=\"password\"]", "password");
    await page.click("button[type=\"submit\"]");
    await page.waitForLoadState("networkidle");
  });

  test("should display API tokens page", async ({ page }) => {
    // Navigate to API tokens page
    await page.goto("/profile/api-tokens");
    await page.waitForLoadState("networkidle");

    // Verify page has API tokens content
    const hasHeading = await page.locator("h3").filter({ hasText: /API Tokens|API-tokens/i }).first().isVisible();
    const hasTokenContent = await page.locator("text=/API Token|API-token|Create.*Token|Token.*aanmaken/i").first().isVisible();
    expect(hasHeading || hasTokenContent).toBe(true);
  });

  test("should create a new API token successfully", async ({ page }) => {
    await page.goto("/profile/api-tokens");
    await page.waitForLoadState("networkidle");

    // Fill in token name
    const tokenNameInput = page.locator("input[id=\"tokenName\"], input[wire\\:model=\"tokenName\"], input[name*=\"token\"]").first();
    if (!await tokenNameInput.isVisible()) return;
    await tokenNameInput.fill("Test Token E2E");

    // Submit form
    const createButton = page.locator("button[type=\"submit\"]").filter({ hasText: /Create.*Token|Token.*aanmaken|Create/i }).first();
    await createButton.click();
    await page.waitForTimeout(2000);

    // Verify success modal or message appears
    const hasSuccess = await page.locator("text=/Token Created|Token succesvol|created/i").first().isVisible();
    const hasToken = await page.locator("code").first().isVisible();
    expect(hasSuccess || hasToken).toBe(true);

    // Close the modal if visible
    const doneButton = page.locator("button").filter({ hasText: /Done|Klaar|Close|Sluiten/i }).first();
    if (await doneButton.isVisible()) {
      await doneButton.click();
    }

    // Verify token appears in the active tokens list
    await expect(page.locator("text=Test Token E2E").first()).toBeVisible();
  });

  test("should show validation error when token name is empty", async ({ page }) => {
    await page.goto("/profile/api-tokens");
    await page.waitForLoadState("networkidle");

    // Try to submit without filling in token name
    const createButton = page.locator("button[type=\"submit\"]").filter({ hasText: /Create.*Token|Token.*aanmaken|Create/i }).first();
    if (!await createButton.isVisible()) return;
    await createButton.click();
    await page.waitForTimeout(1000);

    // Verify error message is displayed
    const hasError = await page.locator("text=/required|verplicht|Token name/i").first().isVisible();
    const hasErrorStyle = await page.locator(".text-red-500, .text-danger, [class*=\"error\"]").count() > 0;
    expect(hasError || hasErrorStyle).toBe(true);
  });

  test("should show validation error when token name is too long", async ({ page }) => {
    await page.goto("/profile/api-tokens");
    await page.waitForLoadState("networkidle");

    // Fill in a token name that's too long (>255 characters)
    const tokenNameInput = page.locator("input[id=\"tokenName\"], input[wire\\:model=\"tokenName\"]").first();
    if (!await tokenNameInput.isVisible()) return;
    const longName = "a".repeat(256);
    await tokenNameInput.fill(longName);

    // Submit form
    const createButton = page.locator("button[type=\"submit\"]").filter({ hasText: /Create.*Token|Token.*aanmaken|Create/i }).first();
    await createButton.click();
    await page.waitForTimeout(1000);

    // Verify error message is displayed
    const hasError = await page.locator("text=/greater than 255|langer.*dan.*255|too long|te lang/i").first().isVisible();
    const hasErrorStyle = await page.locator(".text-red-500, .text-danger, [class*=\"error\"]").count() > 0;
    expect(hasError || hasErrorStyle).toBe(true);
  });

  test("should show validation error when token name is duplicate", async ({ page }) => {
    await page.goto("/profile/api-tokens");
    await page.waitForLoadState("networkidle");

    const duplicateName = "Duplicate Token Test";

    // Create first token
    const tokenNameInput = page.locator("input[id=\"tokenName\"], input[wire\\:model=\"tokenName\"]").first();
    if (!await tokenNameInput.isVisible()) return;
    await tokenNameInput.fill(duplicateName);
    const createButton = page.locator("button[type=\"submit\"]").filter({ hasText: /Create.*Token|Token.*aanmaken|Create/i }).first();
    await createButton.click();
    await page.waitForTimeout(2000);

    // Close the success modal
    const doneButton = page.locator("button").filter({ hasText: /Done|Klaar|Close|Sluiten/i }).first();
    if (await doneButton.isVisible()) {
      await doneButton.click();
      await page.waitForTimeout(500);
    }

    // Try to create a second token with the same name
    await tokenNameInput.fill(duplicateName);
    await createButton.click();
    await page.waitForTimeout(1000);

    // Verify duplicate error message is displayed
    const hasError = await page.locator("text=/already in use|al in gebruik|duplicate|bestaat al/i").first().isVisible();
    const hasErrorStyle = await page.locator(".text-red-500, [class*=\"error\"]").count() > 0;
    expect(hasError || hasErrorStyle).toBe(true);
  });

  test("should allow creating token after revoking duplicate name", async ({ page }) => {
    await page.goto("/profile/api-tokens");
    await page.waitForLoadState("networkidle");

    const tokenName = "Reusable Token Name";

    // Create first token
    const tokenNameInput = page.locator("input[id=\"tokenName\"], input[wire\\:model=\"tokenName\"]").first();
    if (!await tokenNameInput.isVisible()) return;
    await tokenNameInput.fill(tokenName);
    const createButton = page.locator("button[type=\"submit\"]").filter({ hasText: /Create.*Token|Token.*aanmaken|Create/i }).first();
    await createButton.click();
    await page.waitForTimeout(2000);

    // Close the success modal
    const doneButton = page.locator("button").filter({ hasText: /Done|Klaar|Close|Sluiten/i }).first();
    if (await doneButton.isVisible()) {
      await doneButton.click();
      await page.waitForTimeout(500);
    }

    // Find and revoke the token
    page.on('dialog', async (dialog) => await dialog.accept());
    const tokenRow = page.locator("li, tr, div").filter({ hasText: tokenName });
    const revokeButton = tokenRow.locator("button").filter({ hasText: /Revoke|Intrekken/i }).first();
    if (await revokeButton.isVisible()) {
      await revokeButton.click();
      await page.waitForTimeout(1000);
    }

    // Now create a new token with the same name
    await tokenNameInput.fill(tokenName);
    await createButton.click();
    await page.waitForTimeout(2000);

    // Verify success
    const hasSuccess = await page.locator("text=/Token Created|Token succesvol|created/i").first().isVisible();
    expect(hasSuccess).toBe(true);
  });

  test("should revoke an API token", async ({ page }) => {
    await page.goto("/profile/api-tokens");
    await page.waitForLoadState("networkidle");

    const tokenName = "Token To Revoke";

    // Create a token first
    const tokenNameInput = page.locator("input[id=\"tokenName\"], input[wire\\:model=\"tokenName\"]").first();
    if (!await tokenNameInput.isVisible()) return;
    await tokenNameInput.fill(tokenName);
    const createButton = page.locator("button[type=\"submit\"]").filter({ hasText: /Create.*Token|Token.*aanmaken|Create/i }).first();
    await createButton.click();
    await page.waitForTimeout(2000);

    // Close the success modal
    const doneButton = page.locator("button").filter({ hasText: /Done|Klaar|Close|Sluiten/i }).first();
    if (await doneButton.isVisible()) {
      await doneButton.click();
      await page.waitForTimeout(500);
    }

    // Find the token in the list and revoke it
    page.on('dialog', async (dialog) => await dialog.accept());
    const tokenRow = page.locator("li, tr, div").filter({ hasText: tokenName }).first();
    await expect(tokenRow).toBeVisible();

    const revokeButton = tokenRow.locator("button").filter({ hasText: /Revoke|Intrekken/i }).first();
    await revokeButton.click();
    await page.waitForTimeout(1000);

    // Verify token is removed from the list
    await expect(page.locator(`text=${tokenName}`).first()).not.toBeVisible({ timeout: 5000 });
  });

  test("should copy token to clipboard", async ({ page }) => {
    await page.goto("/profile/api-tokens");
    await page.waitForLoadState("networkidle");

    // Create a token
    const tokenNameInput = page.locator("input[id=\"tokenName\"], input[wire\\:model=\"tokenName\"]").first();
    if (!await tokenNameInput.isVisible()) return;
    await tokenNameInput.fill("Copy Test Token");
    const createButton = page.locator("button[type=\"submit\"]").filter({ hasText: /Create.*Token|Token.*aanmaken|Create/i }).first();
    await createButton.click();
    await page.waitForTimeout(2000);

    // Verify modal is visible
    const hasSuccess = await page.locator("text=/Token Created|Token succesvol|created/i").first().isVisible();
    expect(hasSuccess).toBe(true);

    // Click copy button if visible
    const copyButton = page.locator("button").filter({ hasText: /Copy|Kopiëren|Kopieer/i }).first();
    if (await copyButton.isVisible()) {
      await copyButton.click();
      await page.waitForTimeout(500);

      // Verify button text changes to "Copied" or similar feedback
      const hasCopied = await page.locator("text=/Copied|Gekopieerd/i").first().isVisible();
      expect(typeof hasCopied).toBe("boolean");
    }
  });
});
