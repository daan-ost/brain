import { test as setup, expect } from '@playwright/test';

const USER_EMAIL = process.env.PLAYWRIGHT_USER_EMAIL || 'test@example.com';
const USER_PASSWORD = process.env.PLAYWRIGHT_USER_PASSWORD || 'password';
const USER_AUTH_FILE = 'tests/e2e/.auth/user.json';

/**
 * Global setup: Log in as the regular test user once and save the session state.
 * All tests in the 'user-tests' project reuse this session via storageState.
 *
 * Symmetrisch met auth.setup.ts (admin). Gebruikt de seeded user uit
 * PlaywrightTestSeeder: test@example.com / password.
 */
setup('authenticate as user', async ({ page }) => {
  // Go to user login page
  await page.goto('/login');

  // Wait for the page to be ready (avoid networkidle — Livewire polling)
  await page.waitForLoadState('domcontentloaded');

  // Fill credentials
  const emailInput = page.locator('input[name="email"]').first();
  await emailInput.waitFor({ state: 'visible', timeout: 30000 });
  await emailInput.fill(USER_EMAIL);
  await page.locator('input[name="password"]').first().fill(USER_PASSWORD);

  // Submit
  await page.locator('button[type="submit"]').first().click();

  // Wait until we leave the login page
  await page.waitForFunction(
    () => !window.location.pathname.includes('/login'),
    { timeout: 60000 }
  );

  await page.waitForLoadState('domcontentloaded');

  // Sanity check: not on /login anymore. The exact landing route may differ
  // per project (dashboard vs profile) so we don't assert a specific URL.
  await expect(page).not.toHaveURL(/\/login/);

  // Persist the authenticated state for user-tests project
  await page.context().storageState({ path: USER_AUTH_FILE });
});
