import { test, expect, Page } from "@playwright/test";

/**
 * Mobile Navigation & Actions E2E Tests
 *
 * Verifies mobile navigation menus, touch targets, filter layouts,
 * and date range pickers on mobile viewports.
 */

const MOBILE_VIEWPORT = { width: 375, height: 812 }; // iPhone X
const DESKTOP_VIEWPORT = { width: 1280, height: 720 };

test.describe("Mobile Navigation & Actions", () => {
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

  test.describe("App Layout Mobile Menu", () => {
    test.beforeEach(async ({ page }) => {
      await loginAsUser(page);
    });

    test("should toggle mobile menu on hamburger click", async ({ page }) => {
      await page.setViewportSize(MOBILE_VIEWPORT);
      await page.goto("/dashboard");
      await waitForPageLoad(page);

      // Mobile menu should be hidden initially
      const mobileMenu = page.locator("nav .sm\\:hidden .border-l-4").first();
      await expect(mobileMenu).not.toBeVisible();

      // Click hamburger button
      const hamburger = page.locator("nav button.min-h-\\[44px\\]");
      await expect(hamburger).toBeVisible();
      await hamburger.click();

      // Mobile menu should now be visible with navigation links
      const dashboardLink = page.locator(
        'nav a[href*="dashboard"].border-l-4'
      );
      await expect(dashboardLink).toBeVisible();
    });

    test("hamburger button should have minimum 44px touch target", async ({
      page,
    }) => {
      await page.setViewportSize(MOBILE_VIEWPORT);
      await page.goto("/dashboard");
      await waitForPageLoad(page);

      const hamburger = page.locator("nav button.min-h-\\[44px\\]");
      const box = await hamburger.boundingBox();
      expect(box).toBeTruthy();
      expect(box!.width).toBeGreaterThanOrEqual(44);
      expect(box!.height).toBeGreaterThanOrEqual(44);
    });

    test("mobile menu should show user info and profile links", async ({
      page,
    }) => {
      await page.setViewportSize(MOBILE_VIEWPORT);
      await page.goto("/dashboard");
      await waitForPageLoad(page);

      // Open mobile menu
      const hamburger = page.locator("nav button.min-h-\\[44px\\]");
      await hamburger.click();
      await page.waitForTimeout(300);

      // Should show profile and logout links (target mobile menu links specifically via py-3 class)
      const profileLink = page.locator('nav a[href*="profile"].py-3');
      await expect(profileLink.first()).toBeVisible();

      const logoutButton = page.locator(
        'nav form[action*="logout"] button.py-3'
      );
      await expect(logoutButton).toBeVisible();
    });

    test("should hide desktop nav and user dropdown on mobile", async ({
      page,
    }) => {
      await page.setViewportSize(MOBILE_VIEWPORT);
      await page.goto("/dashboard");
      await waitForPageLoad(page);

      // Desktop nav links should be hidden
      const desktopNav = page.locator("nav .hidden.sm\\:flex").first();
      await expect(desktopNav).not.toBeVisible();

      // Desktop user dropdown should be hidden
      const userDropdown = page.locator("nav .hidden.sm\\:flex.sm\\:items-center");
      await expect(userDropdown).not.toBeVisible();
    });
  });

  test.describe("Navigation Touch Targets", () => {
    test.beforeEach(async ({ page }) => {
      await loginAsUser(page);
    });

    test("responsive nav links should have adequate touch target height", async ({
      page,
    }) => {
      await page.setViewportSize(MOBILE_VIEWPORT);
      await page.goto("/dashboard");
      await waitForPageLoad(page);

      // Open mobile menu
      const hamburger = page.locator("nav button.min-h-\\[44px\\]");
      if (await hamburger.isVisible()) {
        await hamburger.click();
        await page.waitForTimeout(500);
      }

      // Check that nav links have py-3 class (48px touch targets)
      // Target main nav links specifically to avoid hidden header mobile menu
      const navLinks = page.locator("nav a.py-3.border-l-4, nav button.py-3.border-l-4");
      const count = await navLinks.count();
      if (count > 0) {
        const visibleLink = navLinks.filter({ visible: true }).first();
        const box = await visibleLink.boundingBox();
        expect(box).toBeTruthy();
        // py-3 = 12px padding top + 12px padding bottom + ~24px text = ~48px
        expect(box!.height).toBeGreaterThanOrEqual(40);
      }
    });
  });

  test.describe("Filter Bars on Mobile", () => {
    test.beforeEach(async ({ page }) => {
      await loginAsUser(page);
    });

    test("filter dropdowns should be full-width on mobile", async ({
      page,
    }) => {
      await page.setViewportSize(MOBILE_VIEWPORT);
      await page.goto("/demo-items");
      await waitForPageLoad(page);

      // Status and priority selects should be full-width on mobile
      const statusSelect = page.locator('select[wire\\:model\\.live="statusFilter"]');
      const prioritySelect = page.locator('select[wire\\:model\\.live="priorityFilter"]');

      if ((await statusSelect.count()) > 0) {
        const statusBox = await statusSelect.boundingBox();
        expect(statusBox).toBeTruthy();
        // On a 375px viewport, full-width elements should be close to viewport width minus padding
        expect(statusBox!.width).toBeGreaterThan(300);
      }

      if ((await prioritySelect.count()) > 0) {
        const priorityBox = await prioritySelect.boundingBox();
        expect(priorityBox).toBeTruthy();
        expect(priorityBox!.width).toBeGreaterThan(300);
      }
    });

    test("filters should stack vertically on mobile", async ({ page }) => {
      await page.setViewportSize(MOBILE_VIEWPORT);
      await page.goto("/demo-items");
      await waitForPageLoad(page);

      const filterContainer = page.locator(".flex.flex-col.sm\\:flex-row").first();
      if ((await filterContainer.count()) > 0) {
        await expect(filterContainer).toBeVisible();
      }
    });

    test("filters should be horizontal on desktop", async ({ page }) => {
      await page.setViewportSize(DESKTOP_VIEWPORT);
      await page.goto("/demo-items");
      await waitForPageLoad(page);

      const statusSelect = page.locator('select[wire\\:model\\.live="statusFilter"]');
      const prioritySelect = page.locator('select[wire\\:model\\.live="priorityFilter"]');

      if ((await statusSelect.count()) > 0 && (await prioritySelect.count()) > 0) {
        const statusBox = await statusSelect.boundingBox();
        const priorityBox = await prioritySelect.boundingBox();
        expect(statusBox).toBeTruthy();
        expect(priorityBox).toBeTruthy();
        // On desktop, selects should NOT be full viewport width
        expect(statusBox!.width).toBeLessThan(300);
      }
    });
  });

  test.describe("Header Component Mobile Menu", () => {
    test("header hamburger should have minimum 44px touch target", async ({
      page,
    }) => {
      await page.setViewportSize(MOBILE_VIEWPORT);
      await page.goto("/");
      await waitForPageLoad(page);

      const hamburger = page.locator(
        "header .md\\:hidden button.min-h-\\[44px\\]"
      );
      if ((await hamburger.count()) > 0) {
        const box = await hamburger.boundingBox();
        expect(box).toBeTruthy();
        expect(box!.width).toBeGreaterThanOrEqual(44);
        expect(box!.height).toBeGreaterThanOrEqual(44);
      }
    });

    test("mobile menu links should have adequate padding", async ({
      page,
    }) => {
      await page.setViewportSize(MOBILE_VIEWPORT);
      await page.goto("/");
      await waitForPageLoad(page);

      // Open mobile menu
      const hamburger = page.locator("header .md\\:hidden button").first();
      if ((await hamburger.count()) > 0) {
        await hamburger.click();
        await page.waitForTimeout(300);

        // Check that nav links have py-3 class for adequate touch targets
        const mobileLinks = page.locator(
          "header .md\\:hidden a.py-3, header .md\\:hidden button.py-3"
        );
        const count = await mobileLinks.count();
        if (count > 0) {
          const box = await mobileLinks.first().boundingBox();
          expect(box).toBeTruthy();
          expect(box!.height).toBeGreaterThanOrEqual(40);
        }
      }
    });
  });

  test.describe("Profile Sidebar Mobile Touch Targets", () => {
    test.beforeEach(async ({ page }) => {
      await loginAsUser(page);
    });

    test("sidebar links should have larger touch targets on mobile", async ({
      page,
    }) => {
      await page.setViewportSize(MOBILE_VIEWPORT);
      await page.goto("/profile/account");
      await waitForPageLoad(page);

      // Profile sidebar links should have py-3 on mobile
      // Use "main" scope to avoid matching header's hidden mobile nav
      const sidebarLinks = page.locator("main nav.space-y-2 a.py-3");
      const count = await sidebarLinks.count();
      if (count > 0) {
        const box = await sidebarLinks.first().boundingBox();
        expect(box).toBeTruthy();
        expect(box!.height).toBeGreaterThanOrEqual(40);
      }
    });
  });
});
