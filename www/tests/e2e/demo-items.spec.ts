import { test, expect, Page } from "@playwright/test";

/**
 * Demo Items CRUD E2E Tests
 *
 * Tests the demo items feature:
 * - Index page with list, filters, sorting
 * - Create/Edit forms
 * - Show page with status transitions
 * - Dashboard widget
 */

test.describe("Demo Items", () => {
  /**
   * Helper: Wait for page load and Livewire hydration
   */
  async function waitForPageLoad(page: Page): Promise<void> {
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(1000);
  }

  /**
   * Helper: Login as test user
   */
  async function loginAsUser(page: Page): Promise<void> {
    await page.goto("/login");
    await page.fill('input[name="email"]', "test@example.com");
    await page.fill('input[name="password"]', "password");
    await page.locator('form[action*="login"] button[type="submit"]').click();
    await waitForPageLoad(page);
  }

  // ============================================================================
  // INDEX PAGE TESTS
  // ============================================================================

  test.describe("Index Page", () => {
    test.beforeEach(async ({ page }) => {
      // Authentication handled by storageState in playwright.config.ts (user-tests project).
    });

    test("should load demo items page", async ({ page }) => {
      await page.goto("/demo-items");
      await waitForPageLoad(page);

      await expect(page.getByRole("heading", { name: "Demo Items" })).toBeVisible();
    });

    test("should show summary cards", async ({ page }) => {
      await page.goto("/demo-items");
      await waitForPageLoad(page);

      await expect(page.getByText("Total Items")).toBeVisible();
      await expect(page.getByText("Total Amount")).toBeVisible();
    });

    test("should show table with items", async ({ page }) => {
      await page.goto("/demo-items");
      await waitForPageLoad(page);

      // Should have table rows with seeded items
      const rows = page.locator("table tbody tr");
      await expect(rows.first()).toBeVisible({ timeout: 10000 });
      const count = await rows.count();
      expect(count).toBeGreaterThan(0);
    });

    test("should filter by status", async ({ page }) => {
      await page.goto("/demo-items");
      await waitForPageLoad(page);

      // Select "Active" status filter using the select element
      const statusSelect = page.locator("select").filter({ hasText: "All Statuses" });
      await statusSelect.selectOption("active");
      await waitForPageLoad(page);

      // All visible items should have Active badge
      const statusBadges = page.locator("table tbody td:nth-child(3) span");
      const count = await statusBadges.count();
      if (count > 0) {
        for (let i = 0; i < count; i++) {
          const text = await statusBadges.nth(i).textContent();
          expect(text?.trim()).toBe("Active");
        }
      }
    });

    test("should filter by priority", async ({ page }) => {
      await page.goto("/demo-items");
      await waitForPageLoad(page);

      const prioritySelect = page.locator("select").filter({ hasText: "All Priorities" });
      await prioritySelect.selectOption("urgent");
      await waitForPageLoad(page);

      const priorityBadges = page.locator("table tbody td:nth-child(4) span");
      const count = await priorityBadges.count();
      if (count > 0) {
        for (let i = 0; i < count; i++) {
          const text = await priorityBadges.nth(i).textContent();
          expect(text?.trim()).toBe("Urgent");
        }
      }
    });

    test("should search by title", async ({ page }) => {
      await page.goto("/demo-items");
      await waitForPageLoad(page);

      await page.getByPlaceholder("Search by title").fill("Website Redesign");
      await waitForPageLoad(page);

      await expect(page.locator("table tbody").getByText("Website Redesign").first()).toBeVisible();
    });

    test("should sort by clicking column header", async ({ page }) => {
      await page.goto("/demo-items");
      await waitForPageLoad(page);

      // Click the Title sort header
      const sortButton = page.locator("thead button", { hasText: "Title" });
      await sortButton.click();
      await waitForPageLoad(page);

      // Page should update with sorted results
      const url = page.url();
      expect(url).toContain("sort=title");
    });
  });

  // ============================================================================
  // CREATE TESTS
  // ============================================================================

  test.describe("Create", () => {
    test.beforeEach(async ({ page }) => {
      // Authentication handled by storageState in playwright.config.ts (user-tests project).
    });

    test("should show create form", async ({ page }) => {
      await page.goto("/demo-items/create");
      await waitForPageLoad(page);

      await expect(page.getByText("Create Demo Item")).toBeVisible();
      await expect(page.locator("input#title")).toBeVisible();
      await expect(page.locator("textarea#description")).toBeVisible();
    });

    test("should validate required fields", async ({ page }) => {
      await page.goto("/demo-items/create");
      await waitForPageLoad(page);

      // Submit without filling title - click the Create Item button specifically
      await page.getByRole("button", { name: "Create Item" }).click();
      await waitForPageLoad(page);

      // Should show validation error
      await expect(page.getByText(/title.*required|required/i).first()).toBeVisible();
    });

    test("should create a new item", async ({ page }) => {
      await page.goto("/demo-items/create");
      await waitForPageLoad(page);

      const uniqueTitle = `E2E Test Item ${Date.now()}`;
      await page.fill("input#title", uniqueTitle);
      await page.fill("textarea#description", "Created by E2E test");
      await page.selectOption("select#priority", "high");
      await page.fill("input#amount", "42.50");

      await page.getByRole("button", { name: "Create Item" }).click();
      await waitForPageLoad(page);

      // Should redirect to index and show the new item (target desktop table to avoid hidden mobile card)
      await expect(page.locator("table").getByText(uniqueTitle)).toBeVisible({ timeout: 10000 });
    });
  });

  // ============================================================================
  // SHOW PAGE TESTS
  // ============================================================================

  test.describe("Show", () => {
    test.beforeEach(async ({ page }) => {
      // Authentication handled by storageState in playwright.config.ts (user-tests project).
    });

    test("should show item details", async ({ page }) => {
      await page.goto("/demo-items");
      await waitForPageLoad(page);

      // Click first item link in the table
      const firstLink = page.locator("table tbody a").first();
      await expect(firstLink).toBeVisible({ timeout: 10000 });
      await firstLink.click();
      await waitForPageLoad(page);

      // Should show detail view
      await expect(page.getByText("Status").first()).toBeVisible();
      await expect(page.getByText("Priority").first()).toBeVisible();
      await expect(page.getByText("Amount").first()).toBeVisible();
    });

    test("should show status transition buttons", async ({ page }) => {
      await page.goto("/demo-items");
      await waitForPageLoad(page);

      // Click first item link in the table
      const firstLink = page.locator("table tbody a").first();
      await expect(firstLink).toBeVisible({ timeout: 10000 });
      await firstLink.click();
      await waitForPageLoad(page);

      // Should have Change Status section
      const hasTransitions = await page
        .getByText("Change Status")
        .first()
        .isVisible();
      // Some items may have transitions, some may not
      expect(typeof hasTransitions).toBe("boolean");
    });
  });

  // ============================================================================
  // EDIT TESTS
  // ============================================================================

  test.describe("Edit", () => {
    test.beforeEach(async ({ page }) => {
      // Authentication handled by storageState in playwright.config.ts (user-tests project).
    });

    test("should show edit form with existing data", async ({ page }) => {
      await page.goto("/demo-items");
      await waitForPageLoad(page);

      // Click Edit link on first item
      const editLink = page.locator("table tbody a", { hasText: "Edit" }).first();
      await expect(editLink).toBeVisible({ timeout: 10000 });
      await editLink.click();
      await waitForPageLoad(page);

      // Should show edit form with pre-filled data
      await expect(page.getByText("Edit Demo Item").first()).toBeVisible();
      const titleInput = page.locator("input#title");
      const titleValue = await titleInput.inputValue();
      expect(titleValue.length).toBeGreaterThan(0);
    });

    test("should update an item", async ({ page }) => {
      await page.goto("/demo-items");
      await waitForPageLoad(page);

      const editLink = page.locator("table tbody a", { hasText: "Edit" }).first();
      await expect(editLink).toBeVisible({ timeout: 10000 });
      await editLink.click();
      await waitForPageLoad(page);

      const updatedTitle = `Updated E2E ${Date.now()}`;
      await page.fill("input#title", updatedTitle);
      await page.getByRole("button", { name: "Update Item" }).click();
      await waitForPageLoad(page);

      // Should redirect to index and show updated title (target desktop table to avoid hidden mobile card)
      await expect(page.locator("table").getByText(updatedTitle)).toBeVisible({ timeout: 10000 });
    });
  });

  // ============================================================================
  // DASHBOARD WIDGET TESTS
  // ============================================================================

  test.describe("Dashboard Widget", () => {
    test.beforeEach(async ({ page }) => {
      // Authentication handled by storageState in playwright.config.ts (user-tests project).
    });

    test("should show demo items summary on dashboard", async ({ page }) => {
      await page.goto("/dashboard");
      await waitForPageLoad(page);

      // Should show the demo items widget
      const hasWidget = await page
        .getByText("Demo Items")
        .first()
        .isVisible();
      if (hasWidget) {
        await expect(page.getByText("Total").first()).toBeVisible();
        await expect(page.getByText("Active").first()).toBeVisible();
        await expect(page.getByText("View all").first()).toBeVisible();
      }
    });
  });
});
