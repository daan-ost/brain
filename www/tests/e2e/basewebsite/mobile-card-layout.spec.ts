import { test, expect, Page } from "@playwright/test";

/**
 * Mobile Card Layout E2E Tests
 *
 * Verifies that data tables convert to card layouts on mobile viewports
 * and that desktop tables remain visible on larger screens.
 */

const MOBILE_VIEWPORT = { width: 375, height: 812 }; // iPhone X
const DESKTOP_VIEWPORT = { width: 1280, height: 720 };

test.describe("Mobile Card Layouts", () => {
  async function waitForPageLoad(page: Page): Promise<void> {
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(1000);
  }

  async function loginAsUser(page: Page): Promise<void> {
    await page.goto("/login");
    await page.fill('input[name="email"]', "test@example.com");
    await page.fill('input[name="password"]', "password");
    await page.locator('form[action*="login"] button[type="submit"]').click();
    await waitForPageLoad(page);
  }

  test.describe("Demo Items Index", () => {
    test.beforeEach(async ({ page }) => {
      await loginAsUser(page);
    });

    test("should show card layout on mobile and hide table", async ({
      page,
    }) => {
      await page.setViewportSize(MOBILE_VIEWPORT);
      await page.goto("/demo-items");
      await waitForPageLoad(page);

      // Mobile cards should be visible (sm:hidden = visible below 640px)
      const mobileCards = page.locator(".sm\\:hidden.space-y-3");
      await expect(mobileCards).toBeVisible();

      // Desktop table should be hidden (hidden sm:block = hidden below 640px)
      const desktopTable = page.locator(".hidden.sm\\:block table");
      await expect(desktopTable).not.toBeVisible();
    });

    test("should show table on desktop and hide cards", async ({ page }) => {
      await page.setViewportSize(DESKTOP_VIEWPORT);
      await page.goto("/demo-items");
      await waitForPageLoad(page);

      // Desktop table should be visible
      const desktopTable = page.locator(".hidden.sm\\:block table");
      await expect(desktopTable).toBeVisible();

      // Mobile cards should be hidden
      const mobileCards = page.locator(".sm\\:hidden.space-y-3");
      await expect(mobileCards).not.toBeVisible();
    });
  });

  test.describe("Organization Users", () => {
    test.beforeEach(async ({ page }) => {
      await loginAsUser(page);
    });

    test("should show card layout on mobile for members list", async ({
      page,
    }) => {
      await page.setViewportSize(MOBILE_VIEWPORT);
      await page.goto("/profile/organization/users");
      await waitForPageLoad(page);

      // Check that mobile cards are present if there are members
      const mobileCards = page.locator(
        ".sm\\:hidden.space-y-3 .rounded-xl"
      );
      const desktopTable = page.locator(
        ".hidden.sm\\:block table"
      );

      // Either mobile cards are shown or no members exist
      const hasMobileCards = await mobileCards.count();
      if (hasMobileCards > 0) {
        await expect(mobileCards.first()).toBeVisible();
        await expect(desktopTable.first()).not.toBeVisible();
      }
    });
  });

  test.describe("Inbound Email Preferences", () => {
    test.beforeEach(async ({ page }) => {
      await loginAsUser(page);
    });

    test("should render page without errors on mobile", async ({ page }) => {
      await page.setViewportSize(MOBILE_VIEWPORT);
      await page.goto("/profile/inbound-email");
      await waitForPageLoad(page);

      // Page should load without errors
      await expect(
        page.getByRole("heading").first()
      ).toBeVisible();
    });
  });
});
