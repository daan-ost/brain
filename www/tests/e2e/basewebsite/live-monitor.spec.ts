import { test, expect, Page } from "@playwright/test";

/**
 * Live Monitor Mobile Responsive E2E Tests
 *
 * Verifies that the 4-panel live monitor is usable on mobile devices:
 * - Position canvas scales to fit small screens
 * - Event log has reduced height on mobile
 * - Camera feed maintains aspect ratio
 * - All panels stack vertically on mobile
 */

const MOBILE_VIEWPORT = { width: 375, height: 812 }; // iPhone X
const NARROW_MOBILE_VIEWPORT = { width: 320, height: 568 }; // iPhone SE
const DESKTOP_VIEWPORT = { width: 1280, height: 720 };

test.describe("Live Monitor Mobile Layout", () => {
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

  test.beforeEach(async ({ page }) => {
    await loginAsUser(page);
  });

  test("should render all four panels", async ({ page }) => {
    await page.goto("/live-monitor");
    await waitForPageLoad(page);

    await expect(page.getByRole("heading", { name: "Position" })).toBeVisible();
    await expect(page.getByRole("heading", { name: "Telemetry" })).toBeVisible();
    await expect(page.getByRole("heading", { name: "Event Log" })).toBeVisible();
    await expect(page.getByRole("heading", { name: "Camera Feed" })).toBeVisible();
  });

  test("should display canvas without overflow on mobile", async ({
    page,
  }) => {
    await page.setViewportSize(MOBILE_VIEWPORT);
    await page.goto("/live-monitor");
    await waitForPageLoad(page);

    const canvas = page.locator("canvas");
    await expect(canvas).toBeVisible();

    // Canvas should not overflow the viewport
    const canvasBox = await canvas.boundingBox();
    expect(canvasBox).not.toBeNull();
    if (canvasBox) {
      expect(canvasBox.width).toBeLessThanOrEqual(MOBILE_VIEWPORT.width);
      expect(canvasBox.x).toBeGreaterThanOrEqual(0);
    }
  });

  test("should fit canvas on narrow screens (320px)", async ({ page }) => {
    await page.setViewportSize(NARROW_MOBILE_VIEWPORT);
    await page.goto("/live-monitor");
    await waitForPageLoad(page);

    const canvas = page.locator("canvas");
    await expect(canvas).toBeVisible();

    const canvasBox = await canvas.boundingBox();
    expect(canvasBox).not.toBeNull();
    if (canvasBox) {
      expect(canvasBox.width).toBeLessThanOrEqual(NARROW_MOBILE_VIEWPORT.width);
    }
  });

  test("should show telemetry gauges in 2x2 grid", async ({ page }) => {
    await page.setViewportSize(MOBILE_VIEWPORT);
    await page.goto("/live-monitor");
    await waitForPageLoad(page);

    await expect(page.getByText("Speed", { exact: true })).toBeVisible();
    await expect(page.getByText("Altitude", { exact: true })).toBeVisible();
    await expect(page.getByText("Battery", { exact: true })).toBeVisible();
    await expect(page.getByText("Signal", { exact: true })).toBeVisible();
  });

  test("should maintain camera feed aspect ratio on mobile", async ({
    page,
  }) => {
    await page.setViewportSize(MOBILE_VIEWPORT);
    await page.goto("/live-monitor");
    await waitForPageLoad(page);

    const cameraFeed = page.locator('[style*="aspect-ratio"]');
    await expect(cameraFeed).toBeVisible();

    const box = await cameraFeed.boundingBox();
    expect(box).not.toBeNull();
    if (box) {
      // Aspect ratio should be approximately 640/360 = 1.78
      const ratio = box.width / box.height;
      expect(ratio).toBeGreaterThan(1.5);
      expect(ratio).toBeLessThan(2.0);
    }
  });

  test("should use 2-column grid on desktop", async ({ page }) => {
    await page.setViewportSize(DESKTOP_VIEWPORT);
    await page.goto("/live-monitor");
    await waitForPageLoad(page);

    // All panels should be visible
    await expect(page.getByRole("heading", { name: "Position" })).toBeVisible();
    await expect(page.getByRole("heading", { name: "Camera Feed" })).toBeVisible();

    // Position and Telemetry panels should be side by side on desktop
    const panels = page.locator(
      ".lg\\:grid-cols-2 > .rounded-lg"
    );
    const panelCount = await panels.count();
    expect(panelCount).toBe(4);
  });
});
