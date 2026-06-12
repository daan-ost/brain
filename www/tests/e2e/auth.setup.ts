import { test as setup, expect } from '@playwright/test';

// NB: deze credentials moeten overeenkomen met PlaywrightTestSeeder (admin user).
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'admin@example.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'admin123';
const ADMIN_AUTH_FILE = 'tests/e2e/.auth/admin.json';

/**
 * Global setup: Login as admin once and save the session state.
 * All tests using 'adminAuth' will reuse this session.
 */
setup('authenticate as admin', async ({ page }) => {
  // Go to login page
  await page.goto('/beheer/login');

  // Wait for Livewire to hydrate
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(2000);

  // Find and fill email input
  const emailInput = page.locator('input[type="email"]').first();
  await emailInput.waitFor({ state: 'visible', timeout: 30000 });
  await emailInput.fill(ADMIN_EMAIL);

  // Fill password
  await page.locator('input[type="password"]').first().fill(ADMIN_PASSWORD);

  // Click login button
  await page.locator('button[type="submit"]').first().click();

  // Wait for successful redirect (not on login page anymore)
  await page.waitForFunction(
    () => !window.location.pathname.includes('/login'),
    { timeout: 60000 }
  );

  // Wait for page to load (avoid networkidle — Livewire polling prevents it)
  await page.waitForLoadState('domcontentloaded');

  // Verify we're logged in by checking we're on the admin panel
  await expect(page).toHaveURL(/\/beheer/);

  // Save the authenticated state
  await page.context().storageState({ path: ADMIN_AUTH_FILE });
});
