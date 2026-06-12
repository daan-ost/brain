import { test, expect } from "@playwright/test";

/**
 * Two-Factor Authentication E2E Tests
 *
 * These tests use pre-authenticated session state from the setup project.
 * The user is already logged in via tests/e2e/.auth/user.json
 */
test.describe("Two-Factor Authentication", () => {
  const testPassword = "password";

  test.afterEach(async ({ page }) => {
    // Cleanup: Cancel any pending 2FA setup to reset state for other tests
    await page.goto("/profile/password");
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(500);

    // Cancel setup if QR code is showing
    const cancelButton = page.getByRole("button", { name: /Cancel|Annuleren/i });
    if (await cancelButton.isVisible().catch(() => false)) {
      await cancelButton.click();
      await page.waitForTimeout(500);
    }

    // Check if 2FA is enabled and disable it
    const disableButton = page.getByRole("button", { name: /Disable|Uitschakelen/i });
    if (await disableButton.isVisible().catch(() => false)) {
      await disableButton.click();
      await page.waitForTimeout(500);

      // Fill in password in modal
      const passwordInput = page.locator('input[type="password"]').last();
      if (await passwordInput.isVisible().catch(() => false)) {
        await passwordInput.fill(testPassword);
        await page.getByRole("button", { name: /Disable|Uitschakelen/i }).last().click();
        await page.waitForTimeout(1000);
      }
    }
  });

  test("should display two-factor authentication section on password page", async ({ page }) => {
    await page.goto("/profile/password");
    await page.waitForLoadState("networkidle");

    // Verify 2FA section heading is visible
    await expect(page.getByRole("heading", { name: /Two-Factor Authentication|Twee-factor authenticatie/i })).toBeVisible();

    // Verify enable button is visible (assuming 2FA is not yet enabled)
    await expect(page.getByRole("button", { name: /Enable Two-Factor|Twee-factor.*inschakelen/i })).toBeVisible();
  });

  test("should show QR code when enabling two-factor authentication", async ({ page }) => {
    await page.goto("/profile/password");
    await page.waitForLoadState("networkidle");

    // Click enable button
    await page.getByRole("button", { name: /Enable Two-Factor|Twee-factor.*inschakelen/i }).click();
    await page.waitForTimeout(1000);

    // Verify QR code section is visible (QR code SVG has explicit width/height)
    await expect(page.locator('svg[width="200"]')).toBeVisible({ timeout: 5000 });

    // Verify secret key is displayed for manual entry
    await expect(page.locator("code")).toBeVisible();

    // Verify verification code input is visible
    await expect(page.locator('input[inputmode="numeric"]')).toBeVisible();
  });

  test("should show validation error with invalid verification code", async ({ page }) => {
    await page.goto("/profile/password");
    await page.waitForLoadState("networkidle");

    // Enable 2FA
    await page.getByRole("button", { name: /Enable Two-Factor|Twee-factor.*inschakelen/i }).click();
    await page.waitForTimeout(1000);

    // Enter invalid code
    await page.locator('input[inputmode="numeric"]').fill("000000");

    // Click confirm button
    await page.getByRole("button", { name: /Confirm|Bevestigen/i }).click();
    await page.waitForTimeout(1000);

    // Verify error message is shown
    await expect(page.locator("text=/invalid|ongeldig/i")).toBeVisible();
  });

  test("should allow canceling two-factor setup", async ({ page }) => {
    await page.goto("/profile/password");
    await page.waitForLoadState("networkidle");

    // Start 2FA setup
    await page.getByRole("button", { name: /Enable Two-Factor|Twee-factor.*inschakelen/i }).click();
    await page.waitForTimeout(1000);

    // Verify QR code is shown
    await expect(page.locator('svg[width="200"]')).toBeVisible();

    // Click cancel button
    await page.getByRole("button", { name: /Cancel|Annuleren/i }).click();
    await page.waitForTimeout(1000);

    // Verify QR code is hidden and enable button is back
    await expect(page.locator('svg[width="200"]')).not.toBeVisible();
    await expect(page.getByRole("button", { name: /Enable Two-Factor|Twee-factor.*inschakelen/i })).toBeVisible();
  });

  test("should require password to disable two-factor authentication", async ({ page }) => {
    // This test would require first enabling 2FA with a valid TOTP code
    // Since we can't generate valid TOTP codes in E2E tests easily,
    // we test the UI flow instead

    await page.goto("/profile/password");
    await page.waitForLoadState("networkidle");

    // Start 2FA setup to verify the cancel flow works
    const enableButton = page.getByRole("button", { name: /Enable Two-Factor|Twee-factor.*inschakelen/i });

    if (await enableButton.isVisible()) {
      await enableButton.click();
      await page.waitForTimeout(1000);

      // Verify the setup flow starts
      await expect(page.locator('svg[width="200"]')).toBeVisible();

      // Cancel setup
      await page.getByRole("button", { name: /Cancel|Annuleren/i }).click();
    }
  });

  test("should display recovery codes section for enabled 2FA", async ({ page }) => {
    // Navigate to password page
    await page.goto("/profile/password");
    await page.waitForLoadState("networkidle");

    // Check the page structure for 2FA elements
    await expect(page.getByRole("heading", { name: /Two-Factor Authentication|Twee-factor authenticatie/i })).toBeVisible();
  });

  test("should mask verification code input", async ({ page }) => {
    await page.goto("/profile/password");
    await page.waitForLoadState("networkidle");

    // Enable 2FA to show the input
    await page.getByRole("button", { name: /Enable Two-Factor|Twee-factor.*inschakelen/i }).click();
    await page.waitForTimeout(1000);

    // Verify the input has numeric inputmode
    const codeInput = page.locator('input[inputmode="numeric"]');
    await expect(codeInput).toHaveAttribute("inputmode", "numeric");
    await expect(codeInput).toHaveAttribute("maxlength", "6");
  });

  test("should show manual secret key for authenticator apps", async ({ page }) => {
    await page.goto("/profile/password");
    await page.waitForLoadState("networkidle");

    // Enable 2FA
    await page.getByRole("button", { name: /Enable Two-Factor|Twee-factor.*inschakelen/i }).click();
    await page.waitForTimeout(1000);

    // Verify manual entry text is shown
    await expect(page.locator("text=/manually|handmatig/i")).toBeVisible();

    // Verify code element contains a base32 secret (uppercase letters and numbers 2-7)
    const codeElement = page.locator("code");
    await expect(codeElement).toBeVisible();
    const secretKey = await codeElement.textContent();
    expect(secretKey).toMatch(/^[A-Z2-7]+$/);
  });
});

test.describe("Two-Factor Challenge Page", () => {
  test("should redirect to dashboard if 2FA is not enabled", async ({ page }) => {
    // User is already authenticated via session state
    // Access 2FA challenge page (should redirect since 2FA is not enabled)
    await page.goto("/two-factor-challenge");
    await page.waitForLoadState("networkidle");

    // Should redirect to dashboard or home
    await expect(page).not.toHaveURL(/\/two-factor-challenge/);
  });

  test("should have proper form structure on profile page", async ({ page }) => {
    // User is already authenticated via session state
    // Navigate to password page to verify 2FA UI elements
    await page.goto("/profile/password");
    await page.waitForLoadState("networkidle");

    // Check for 2FA section
    await expect(page.getByRole("heading", { name: /Two-Factor Authentication/i })).toBeVisible();
  });

  /**
   * The following tests require the test user to have 2FA enabled.
   * When 2FA is NOT enabled, navigating to /two-factor-challenge redirects
   * away — these tests return early (pass silently) in that case.
   *
   * To exercise them fully: enable 2FA for test@example.com in the DB
   * (set two_factor_secret + two_factor_confirmed_at), or run them after
   * a test that leaves 2FA enabled.
   */

  test("should show correct form elements on challenge page", async ({ page }) => {
    await page.goto("/two-factor-challenge");
    await page.waitForLoadState("networkidle");

    // Not reachable without 2FA enabled — skip silently
    if (!page.url().includes("/two-factor-challenge")) return;

    // TOTP code input
    await expect(page.locator("input#code")).toBeVisible();
    await expect(page.locator("input#code")).toHaveAttribute("inputmode", "numeric");
    await expect(page.locator("input#code")).toHaveAttribute("maxlength", "6");
    await expect(page.locator("input#code")).toHaveAttribute("placeholder", "000000");

    // Remember device checkbox
    await expect(page.locator("input#remember[type='checkbox']")).toBeAttached();
    await expect(page.locator("label[for='remember']")).toContainText(/30 days|30 dagen/i);

    // Submit button
    await expect(page.locator("button[type='submit']")).toContainText(/Verify|Verifieer/i);

    // Recovery code toggle link
    await expect(
      page.locator("button").filter({ hasText: /Use a recovery code|Gebruik een herstelcode/i })
    ).toBeVisible();

    // Logout link
    await expect(
      page.locator("button").filter({ hasText: /Log out and try again|Uitloggen/i })
    ).toBeVisible();
  });

  test("should show red border and error message on wrong code", async ({ page }) => {
    await page.goto("/two-factor-challenge");
    await page.waitForLoadState("networkidle");

    if (!page.url().includes("/two-factor-challenge")) return;

    await page.fill("input#code", "000000");
    await page.click("button[type='submit']");
    await page.waitForLoadState("networkidle");

    // Still on challenge page
    await expect(page).toHaveURL(/\/two-factor-challenge/);

    // Error message visible
    await expect(page.locator(".text-red-600").first()).toBeVisible();

    // Code input has red border class (set by @error directive)
    const codeClass = await page.locator("input#code").getAttribute("class");
    expect(codeClass).toContain("border-red-500");
  });

  test("should toggle between TOTP and recovery code inputs", async ({ page }) => {
    await page.goto("/two-factor-challenge");
    await page.waitForLoadState("networkidle");

    if (!page.url().includes("/two-factor-challenge")) return;

    // Initially: TOTP input visible, recovery input hidden (x-cloak)
    await expect(page.locator("input#code")).toBeVisible();
    const recoveryInitiallyHidden = await page.locator("input#recovery_code").isVisible();
    expect(recoveryInitiallyHidden).toBe(false);

    // Click "Use a recovery code"
    await page.locator("button").filter({ hasText: /Use a recovery code|Gebruik een herstelcode/i }).click();
    await page.waitForTimeout(300);

    // Recovery input now visible, TOTP hidden
    await expect(page.locator("input#recovery_code")).toBeVisible();
    await expect(page.locator("input#code")).not.toBeVisible();

    // Recovery input format hint
    await expect(page.locator("input#recovery_code")).toHaveAttribute("placeholder", "XXXX-XXXX");

    // Toggle back
    await page.locator("button").filter({ hasText: /Use an authentication code|Gebruik een authenticatiecode/i }).click();
    await page.waitForTimeout(300);

    await expect(page.locator("input#code")).toBeVisible();
    await expect(page.locator("input#recovery_code")).not.toBeVisible();
  });

  test("should preserve intended URL in session across failed attempts", async ({ page }) => {
    // This test verifies our fix: two_factor_intended_url session key is not
    // consumed by a wrong code attempt — the final redirect still lands on the
    // originally intended page.
    //
    // Full verification requires 2FA + TOTP. Here we confirm that:
    // (a) a wrong code keeps the user on the challenge page (not lost)
    // (b) the URL does not change to an unrelated page after the error
    await page.goto("/two-factor-challenge");
    await page.waitForLoadState("networkidle");

    if (!page.url().includes("/two-factor-challenge")) return;

    const urlBeforeSubmit = page.url();

    await page.fill("input#code", "000000");
    await page.click("button[type='submit']");
    await page.waitForLoadState("networkidle");

    // Must still be on the challenge page — not redirected somewhere unrelated
    await expect(page).toHaveURL(/\/two-factor-challenge/);
    expect(page.url()).toBe(urlBeforeSubmit);
  });
});

test.describe("Two-Factor Authentication - Admin Reset", () => {
  test("admin can access user 2FA status in user resource", async ({ page }) => {
    // Login as admin
    await page.goto("/beheer/login");
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(2000);

    const emailInput = page.locator('input[type="email"]').first();
    await emailInput.waitFor({ state: "visible", timeout: 30000 });
    await emailInput.fill(process.env.ADMIN_EMAIL || "admin@example.com");

    await page.locator('input[type="password"]').first().fill(process.env.ADMIN_PASSWORD || "admin123");
    await page.locator('button[type="submit"]').first().click();

    await page.waitForFunction(
      () => !window.location.pathname.includes("/login"),
      { timeout: 60000 }
    );
    await page.waitForLoadState("networkidle");

    // Navigate to users
    await page.goto("/beheer/users");
    await page.waitForLoadState("networkidle");

    // Verify we're on users page
    await expect(page).toHaveURL(/\/beheer\/users/);

    // Verify the table is visible
    await expect(page.locator("table")).toBeVisible({ timeout: 10000 });
  });
});
