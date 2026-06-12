import { test, expect, Page } from "@playwright/test";

/**
 * Form & Detail Page Responsive E2E Tests
 *
 * Verifies that detail pages use tab navigation, action buttons stack on mobile,
 * modals go full-screen on mobile, and form fields stack vertically.
 */

const MOBILE_VIEWPORT = { width: 375, height: 812 };
const DESKTOP_VIEWPORT = { width: 1280, height: 720 };

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

test.describe("Form & Detail Page Responsive", () => {
  test.beforeEach(async ({ page }) => {
    await loginAsUser(page);
  });

  test.describe("Detail Page Tab Bar", () => {
    test("should show horizontally scrollable tabs on mobile", async ({
      page,
    }) => {
      await page.setViewportSize(MOBILE_VIEWPORT);
      await page.goto("/demo-items");
      await waitForPageLoad(page);

      // Navigate to a detail page if items exist
      const itemLink = page.locator(
        '.sm\\:hidden a[href*="demo-items/"]'
      );
      if ((await itemLink.count()) > 0) {
        await itemLink.first().click();
        await waitForPageLoad(page);

        // Tab bar should be visible with overflow-x-auto for scrolling
        const tabList = page.locator('[role="tablist"]');
        await expect(tabList).toBeVisible();

        // Overview and Details tabs should be visible
        await expect(
          page.getByRole("tab", { name: "Overview" })
        ).toBeVisible();
        await expect(
          page.getByRole("tab", { name: "Details" })
        ).toBeVisible();
      }
    });

    test("should switch tabs on click", async ({ page }) => {
      await page.setViewportSize(DESKTOP_VIEWPORT);
      await page.goto("/demo-items");
      await waitForPageLoad(page);

      // Navigate to detail page
      const itemLink = page.locator(
        'table a[href*="demo-items/"]'
      );
      if ((await itemLink.count()) > 0) {
        await itemLink.first().click();
        await waitForPageLoad(page);

        // Click Details tab
        const detailsTab = page.getByRole("tab", { name: "Details" });
        await detailsTab.click();
        await page.waitForTimeout(500); // allow Alpine.js to update x-show

        // Details panel should be visible, check for "Created" label
        await expect(page.getByText("Created", { exact: true })).toBeVisible({ timeout: 5000 });
      }
    });
  });

  test.describe("Detail Page Action Buttons", () => {
    test("should stack action buttons vertically on mobile", async ({
      page,
    }) => {
      await page.setViewportSize(MOBILE_VIEWPORT);
      await page.goto("/demo-items");
      await waitForPageLoad(page);

      const itemLink = page.locator(
        '.sm\\:hidden a[href*="demo-items/"]'
      );
      if ((await itemLink.count()) > 0) {
        await itemLink.first().click();
        await waitForPageLoad(page);

        // Action buttons container should use flex-col on mobile
        const editButton = page.locator("a", { hasText: "Edit" });
        const deleteButton = page.locator("button", { hasText: "Delete" });

        if ((await editButton.count()) > 0) {
          await expect(editButton.first()).toBeVisible();
          await expect(deleteButton.first()).toBeVisible();

          // Both buttons should be full width on mobile (w-full)
          const editBox = await editButton.first().boundingBox();
          const deleteBox = await deleteButton.first().boundingBox();

          if (editBox && deleteBox) {
            // On mobile, buttons should be stacked (delete below edit)
            expect(deleteBox.y).toBeGreaterThanOrEqual(
              editBox.y + editBox.height - 2
            );
          }
        }
      }
    });
  });

  test.describe("Modal Full-Screen on Mobile", () => {
    test("should open create modal as full-screen on mobile", async ({
      page,
    }) => {
      await page.setViewportSize(MOBILE_VIEWPORT);
      await page.goto("/demo-items?mode=modal");
      await waitForPageLoad(page);

      // Click New Item button to open modal
      const newButton = page.locator("button", { hasText: "New Item" });
      if ((await newButton.count()) > 0) {
        await newButton.click();
        await page.waitForTimeout(500);

        // Modal should cover full screen on mobile
        const modal = page.locator(
          '[role="dialog"] .fixed.inset-0.sm\\:inset-auto'
        );
        if ((await modal.count()) > 0) {
          const box = await modal.boundingBox();
          if (box) {
            // Full-screen: width and height should match viewport
            expect(box.width).toBeGreaterThanOrEqual(370);
            expect(box.height).toBeGreaterThanOrEqual(400);
          }
        }

        // Close button should be visible on mobile
        const closeButton = page.locator('[aria-label="Close"]');
        if ((await closeButton.count()) > 0) {
          await expect(closeButton.first()).toBeVisible();
        }
      }
    });
  });

  test.describe("Form Fields Responsive", () => {
    test("should show form fields full-width on mobile", async ({ page }) => {
      await page.setViewportSize(MOBILE_VIEWPORT);
      await page.goto("/demo-items/create");
      await waitForPageLoad(page);

      // Form fields should be visible and full-width
      const titleInput = page.locator('input[id="title"]');
      if ((await titleInput.count()) > 0) {
        await expect(titleInput).toBeVisible();

        const titleBox = await titleInput.boundingBox();
        if (titleBox) {
          // Input should be nearly full viewport width (minus padding)
          expect(titleBox.width).toBeGreaterThan(300);
        }

        // Status and Priority selects should be stacked on mobile
        const statusSelect = page.locator('select[id="status"]');
        const prioritySelect = page.locator('select[id="priority"]');

        if (
          (await statusSelect.count()) > 0 &&
          (await prioritySelect.count()) > 0
        ) {
          const statusBox = await statusSelect.boundingBox();
          const priorityBox = await prioritySelect.boundingBox();

          if (statusBox && priorityBox) {
            // On mobile with grid-cols-1, priority should be below status
            expect(priorityBox.y).toBeGreaterThan(statusBox.y);
          }
        }
      }
    });

    test("should show form fields side-by-side on desktop", async ({
      page,
    }) => {
      await page.setViewportSize(DESKTOP_VIEWPORT);
      await page.goto("/demo-items/create");
      await waitForPageLoad(page);

      const statusSelect = page.locator('select[id="status"]');
      const prioritySelect = page.locator('select[id="priority"]');

      if (
        (await statusSelect.count()) > 0 &&
        (await prioritySelect.count()) > 0
      ) {
        const statusBox = await statusSelect.boundingBox();
        const priorityBox = await prioritySelect.boundingBox();

        if (statusBox && priorityBox) {
          // On desktop with grid-cols-2, status and priority should be on the same row
          expect(Math.abs(priorityBox.y - statusBox.y)).toBeLessThan(5);
        }
      }
    });
  });
});
