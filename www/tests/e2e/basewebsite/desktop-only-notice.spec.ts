import { test, expect, Page } from "@playwright/test";

/**
 * Desktop Only Notice E2E Tests
 *
 * Tests that the desktop-only-notice component correctly shows
 * a mobile message on small screens and hides it on larger screens.
 *
 * These tests target the BT Builder and Safety Calibration partials
 * which use the x-desktop-only-notice component.
 */

const MOBILE_VIEWPORT = { width: 375, height: 812 }; // iPhone X
const TABLET_VIEWPORT = { width: 768, height: 1024 }; // iPad
const DESKTOP_VIEWPORT = { width: 1280, height: 720 };

test.describe("Desktop Only Notice - Graceful Degradation", () => {
  async function waitForPageLoad(page: Page): Promise<void> {
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(500);
  }

  test.describe("BT Builder", () => {
    // These tests require the missions route to exist.
    // Once the route is added, update the URL below.
    const BT_BUILDER_URL = "/missions";

    test("should show mobile notice on small screens", async ({ page }) => {
      test.skip(true, "Missions route not yet implemented");

      await page.setViewportSize(MOBILE_VIEWPORT);
      await page.goto(BT_BUILDER_URL);
      await waitForPageLoad(page);

      // Mobile notice should be visible
      const mobileNotice = page.locator('[role="status"]').filter({
        hasText: "Mission Builder",
      });
      await expect(mobileNotice).toBeVisible();
      await expect(mobileNotice).toContainText(
        "The visual builder requires a larger screen"
      );
      await expect(mobileNotice).toContainText(
        "You can still view the mission JSON below"
      );

      // Desktop builder content should be hidden
      const desktopContent = page.locator(".hidden.sm\\:block").first();
      await expect(desktopContent).not.toBeVisible();
    });

    test("should show builder on tablet and larger screens", async ({
      page,
    }) => {
      test.skip(true, "Missions route not yet implemented");

      await page.setViewportSize(TABLET_VIEWPORT);
      await page.goto(BT_BUILDER_URL);
      await waitForPageLoad(page);

      // Mobile notice should be hidden on tablet
      const mobileNotice = page.locator(".sm\\:hidden").filter({
        hasText: "Mission Builder",
      });
      await expect(mobileNotice).not.toBeVisible();

      // Desktop builder should be visible
      const desktopContent = page.locator(".hidden.sm\\:block").first();
      await expect(desktopContent).toBeVisible();
    });

    test("should toggle visibility when resizing viewport", async ({
      page,
    }) => {
      test.skip(true, "Missions route not yet implemented");

      await page.goto(BT_BUILDER_URL);
      await waitForPageLoad(page);

      // Start on desktop - builder visible, notice hidden
      await page.setViewportSize(DESKTOP_VIEWPORT);
      const mobileNotice = page.locator('[role="status"]').filter({
        hasText: "Mission Builder",
      });
      const desktopContent = page.locator(".hidden.sm\\:block").first();

      await expect(mobileNotice).not.toBeVisible();
      await expect(desktopContent).toBeVisible();

      // Resize to mobile - notice visible, builder hidden
      await page.setViewportSize(MOBILE_VIEWPORT);
      await expect(mobileNotice).toBeVisible();
      await expect(desktopContent).not.toBeVisible();
    });
  });

  test.describe("Safety Calibration", () => {
    const SAFETY_CALIBRATION_URL = "/missions/calibration";

    test("should show mobile notice on small screens", async ({ page }) => {
      test.skip(true, "Safety calibration route not yet implemented");

      await page.setViewportSize(MOBILE_VIEWPORT);
      await page.goto(SAFETY_CALIBRATION_URL);
      await waitForPageLoad(page);

      const mobileNotice = page.locator('[role="status"]').filter({
        hasText: "Safety Calibration",
      });
      await expect(mobileNotice).toBeVisible();
      await expect(mobileNotice).toContainText(
        "Depth calibration requires a larger screen"
      );
    });

    test("should show calibration UI on desktop", async ({ page }) => {
      test.skip(true, "Safety calibration route not yet implemented");

      await page.setViewportSize(DESKTOP_VIEWPORT);
      await page.goto(SAFETY_CALIBRATION_URL);
      await waitForPageLoad(page);

      const mobileNotice = page.locator(".sm\\:hidden").filter({
        hasText: "Safety Calibration",
      });
      await expect(mobileNotice).not.toBeVisible();
    });
  });
});
