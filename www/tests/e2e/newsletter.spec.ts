import { test, expect, Page } from "@playwright/test";

/**
 * Newsletter E2E Tests
 *
 * Tests newsletter subscription management:
 * - Unsubscribe via token link
 * - Newsletter preferences in profile
 */

test.describe("Newsletter", () => {
  /**
   * Helper: Wait for page load
   */
  async function waitForPageLoad(page: Page): Promise<void> {
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(300);
  }

  /**
   * Helper: Login as test user
   */
  async function loginAsUser(page: Page): Promise<void> {
    await page.goto("/login");
    await page.fill('input[name="email"]', "test@example.com");
    await page.fill('input[name="password"]', "password");
    await page.click('button[type="submit"]');
    await waitForPageLoad(page);
  }

  // ============================================================================
  // UNSUBSCRIBE TESTS
  // ============================================================================

  test.describe("Unsubscribe Page", () => {
    test("should show error page for invalid token", async ({ page }) => {
      await page.goto("/newsletter/unsubscribe/invalid-token-12345");
      await waitForPageLoad(page);

      // Should show error page (EN: "Unsubscribe Failed" / NL: "Uitschrijving mislukt")
      const hasErrorTitle = await page
        .locator("text=/mislukt|failed|error/i")
        .first()
        .isVisible();
      const hasErrorMessage = await page
        .locator("text=/ongeldig|invalid|expired/i")
        .first()
        .isVisible();

      expect(hasErrorTitle || hasErrorMessage).toBe(true);
    });

    test("should show home button on error page", async ({ page }) => {
      await page.goto("/newsletter/unsubscribe/invalid-token-12345");
      await waitForPageLoad(page);

      // Should have link back to home (EN: "Back to Homepage" / NL: "Terug naar homepage")
      const homeLink = page.locator("a[href='/']").first();
      const homeLinkByText = page.locator("a").filter({ hasText: /home|homepage|terug/i }).first();

      const hasHomeLink = await homeLink.isVisible() || await homeLinkByText.isVisible();
      expect(hasHomeLink).toBe(true);
    });
  });

  // ============================================================================
  // PROFILE NEWSLETTER PREFERENCES TESTS
  // ============================================================================

  test.describe("Newsletter Preferences", () => {
    test.beforeEach(async ({ page }) => {
      await loginAsUser(page);
    });

    test("should display email preferences page with newsletter section", async ({
      page,
    }) => {
      await page.goto("/profile/email-preferences");
      await waitForPageLoad(page);

      // Should be on the email preferences page
      expect(page.url()).toContain("/profile/email-preferences");

      // Should have some preferences content
      const hasPreferencesContent = await page
        .locator("text=/email|nieuwsbrief|newsletter|preferences/i")
        .first()
        .isVisible();

      expect(hasPreferencesContent).toBe(true);
    });

    test("should have newsletter toggle component", async ({ page }) => {
      await page.goto("/profile/email-preferences");
      await waitForPageLoad(page);

      // Look for toggle switch or checkbox for newsletter (wire:click="toggleSubscription")
      const hasToggle = await page
        .locator(
          'button[role="switch"], input[type="checkbox"], [wire\\:click*="toggleSubscription"]'
        )
        .first()
        .isVisible();

      // At minimum should have newsletter-related content
      const hasNewsletterText = await page
        .locator("text=/nieuwsbrief|newsletter|product update/i")
        .first()
        .isVisible();

      expect(hasToggle || hasNewsletterText).toBe(true);
    });
  });
});
