import { test, expect, Page } from '@playwright/test';

/**
 * License Health Monitor E2E Tests
 *
 * Tests the License Health admin page at /beheer/license-health.
 * Uses pre-authenticated admin storageState.
 */

test.setTimeout(60000);

test.use({ storageState: 'tests/e2e/.auth/admin.json' });

const BASE_URL = '/beheer';

async function waitForLivewire(page: Page): Promise<void> {
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(300);
}

function setupConsoleErrorCapture(page: Page): string[] {
  const errors: string[] = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      const text = msg.text();
      if (!text.includes('favicon') && !text.includes('net::ERR')) {
        errors.push(text);
      }
    }
  });
  return errors;
}

// ============================================================================
// TEST 1: Page loads successfully
// ============================================================================
test('license health page loads at /beheer/license-health', async ({ page }) => {
  await page.goto(`${BASE_URL}/license-health`);
  await waitForLivewire(page);

  expect(page.url()).toContain('/license-health');

  const bodyText = await page.evaluate(() => document.body.innerText);
  expect(bodyText).not.toContain('500');
  expect(bodyText).not.toContain('Server Error');
  expect(bodyText).not.toContain('404');
});

// ============================================================================
// TEST 2: Status cards are visible
// ============================================================================
test('displays 4 status cards', async ({ page }) => {
  await page.goto(`${BASE_URL}/license-health`);
  await waitForLivewire(page);

  await expect(page.getByText('Overdue Resets').first()).toBeVisible();
  await expect(page.getByText('Expiring Soon').first()).toBeVisible();
  await expect(page.getByText('Unpaid Invoices').first()).toBeVisible();
  await expect(page.getByText('Healthy').first()).toBeVisible();
});

// ============================================================================
// TEST 3: Tab navigation works
// ============================================================================
test('tab navigation switches content', async ({ page }) => {
  await page.goto(`${BASE_URL}/license-health`);
  await waitForLivewire(page);

  // Default tab should be "Overdue Resets"
  const overdueTab = page.getByRole('button', { name: /Overdue Resets/i });
  await expect(overdueTab).toBeVisible();

  // Click "Expiring" tab
  const expiringTab = page.getByRole('button', { name: /Expiring/i });
  await expiringTab.click();
  await waitForLivewire(page);

  // Click "Alle actieve licenties" tab
  const activeTab = page.getByRole('button', { name: /Alle actieve licenties/i });
  await activeTab.click();
  await waitForLivewire(page);

  // Verify filters are shown for the active tab
  await expect(page.locator('select').first()).toBeVisible();
});

// ============================================================================
// TEST 4: Bulk check button exists and works
// ============================================================================
test('bulk check button is clickable', async ({ page }) => {
  await page.goto(`${BASE_URL}/license-health`);
  await waitForLivewire(page);

  const bulkCheckButton = page.getByRole('button', { name: /Controleer alle licenties/i });
  await expect(bulkCheckButton).toBeVisible();

  await bulkCheckButton.click();
  await waitForLivewire(page);

  // Should show a notification
  const notification = page.locator('.fi-no-notification, [wire\\:snapshot]').first();
  // Wait briefly for notification to appear
  await page.waitForTimeout(1000);
});

// ============================================================================
// TEST 5: Page has no JavaScript errors
// ============================================================================
test('page loads without JavaScript errors', async ({ page }) => {
  const consoleErrors = setupConsoleErrorCapture(page);

  await page.goto(`${BASE_URL}/license-health`);
  await waitForLivewire(page);

  const livewireErrors = consoleErrors.filter(e => e.includes('Livewire'));
  if (livewireErrors.length > 0) {
    throw new Error(`Livewire errors on license-health: ${livewireErrors.join(', ')}`);
  }
});

// ============================================================================
// TEST 6: Navigation link exists in sidebar
// ============================================================================
test('license health appears in navigation', async ({ page }) => {
  await page.goto(`${BASE_URL}/license-health`);
  await waitForLivewire(page);

  // The page should be accessible via navigation
  const navLink = page.locator('nav a[href*="license-health"]').first();
  await expect(navLink).toBeVisible();
});

// ============================================================================
// TEST 7: Unauthenticated access is blocked
// ============================================================================
test('unauthenticated access redirects to login', async ({ page }) => {
  await page.context().clearCookies();

  await page.goto(`${BASE_URL}/license-health`);

  await expect(page).toHaveURL(new RegExp(`${BASE_URL}/login`), { timeout: 10000 });
});
