import { test, expect, Page } from '@playwright/test';

/**
 * Filament Admin Panel E2E Smoke Tests
 *
 * Minimal smoke test suite (3-5 tests) to verify critical admin functionality.
 * Uses pre-authenticated storageState for fast, reliable testing.
 *
 * Full coverage is handled by Pest PHP tests.
 */

test.setTimeout(60000);

const BASE_URL = '/beheer';

// Read credentials from environment variables (set by AIfactory)
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'admin@example.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'admin123';

/**
 * Helper: Wait for Livewire/page to finish processing
 */
async function waitForLivewire(page: Page): Promise<void> {
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(300);
}

/**
 * Helper: Collect console errors during page load
 * Use this to catch Livewire Entangle errors and other JS issues
 */
function setupConsoleErrorCapture(page: Page): string[] {
  const errors: string[] = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      const text = msg.text();
      // Filter out known non-critical errors
      if (!text.includes('favicon') && !text.includes('net::ERR')) {
        errors.push(text);
      }
    }
  });
  return errors;
}

// ============================================================================
// SMOKE TEST 1: Authentication flow works
// ============================================================================
test('smoke: login page renders and accepts credentials', async ({ page }) => {
  // Use fresh context without pre-auth
  await page.context().clearCookies();

  await page.goto(`${BASE_URL}/login`);
  await page.waitForLoadState('domcontentloaded');

  // Verify login form is present
  const emailInput = page.locator('input[type="email"]').first();
  await expect(emailInput).toBeVisible({ timeout: 15000 });
  await expect(page.locator('input[type="password"]').first()).toBeVisible();
  await expect(page.locator('button[type="submit"]').first()).toBeVisible();

  // Perform login with credentials from environment
  await emailInput.fill(ADMIN_EMAIL);
  await page.locator('input[type="password"]').first().fill(ADMIN_PASSWORD);
  await page.locator('button[type="submit"]').first().click();

  // Wait for redirect away from login
  await page.waitForFunction(
    () => !window.location.pathname.includes('/login'),
    { timeout: 30000 }
  );

  // Should be on admin panel
  expect(page.url()).toContain(BASE_URL);
  expect(page.url()).not.toContain('/login');
});

// ============================================================================
// SMOKE TEST 2: Dashboard loads for authenticated user
// ============================================================================
test('smoke: dashboard loads successfully', async ({ page }) => {
  await page.goto(BASE_URL);
  await waitForLivewire(page);

  // Dashboard should load without redirect to login
  expect(page.url()).toContain(BASE_URL);
  expect(page.url()).not.toContain('/login');

  // Page should have content (not error page)
  const bodyText = await page.evaluate(() => document.body.innerText);
  expect(bodyText).not.toContain('500');
  expect(bodyText).not.toContain('Server Error');
});

// ============================================================================
// SMOKE TEST: Welcome widget is not shown on dashboard
// ============================================================================
test('smoke: dashboard does not show AccountWidget welcome block', async ({ page }) => {
  await page.goto(BASE_URL);
  await waitForLivewire(page);

  // Dashboard should load successfully
  expect(page.url()).toContain(BASE_URL);
  expect(page.url()).not.toContain('/login');

  // The AccountWidget "Sign out" button should NOT be present
  // (logout is still available via the sidebar user menu)
  await expect(page.locator('text=Sign out').first()).not.toBeVisible({ timeout: 5000 });
});

// ============================================================================
// SMOKE TEST 3: Key resource pages are accessible
// ============================================================================
test('smoke: core resources are accessible', async ({ page }) => {
  const resources = ['users', 'orders', 'licenses', 'organizations'];

  for (const resource of resources) {
    // Use response from goto() to check HTTP status — bodyText kan onbedoeld
    // de substrings '403'/'404' bevatten (bv. credits=404 in een licenses tabel).
    const response = await page.goto(`${BASE_URL}/${resource}`);
    await waitForLivewire(page);

    // Should not be redirected or show error
    expect(page.url()).toContain(`/${resource}`);
    expect(response?.status() ?? 0).toBeLessThan(400);

    // Check for explicit error page indicators (Laravel/Filament 403/404 views).
    const bodyText = await page.evaluate(() => document.body.innerText);
    expect(bodyText).not.toMatch(/403\s+(Forbidden|Unauthorized)/i);
    expect(bodyText).not.toMatch(/404\s+(Not Found|Page Not Found)/i);
  }
});

// ============================================================================
// SMOKE TEST 4: CRUD form renders correctly
// ============================================================================
test('smoke: create form renders with fields', async ({ page }) => {
  await page.goto(`${BASE_URL}/announcements/create`);
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(1000);

  // Form should be visible (not logout form)
  const mainForm = page.locator('form:not([action*="logout"])').first();
  await expect(mainForm).toBeVisible({ timeout: 15000 });

  // Should have input fields
  const inputCount = await page.locator('form:not([action*="logout"]) input').count();
  expect(inputCount).toBeGreaterThan(0);
});

// ============================================================================
// SMOKE TEST 5: Unauthenticated access is blocked
// ============================================================================
test('smoke: unauthenticated access redirects to login', async ({ page }) => {
  // Clear auth state
  await page.context().clearCookies();

  await page.goto(`${BASE_URL}/users`);

  // Should redirect to login
  await expect(page).toHaveURL(new RegExp(`${BASE_URL}/login`), { timeout: 10000 });
});

// ============================================================================
// SMOKE TEST 6: Admin pages load without JavaScript errors
// ============================================================================
test('smoke: admin pages have no Livewire/JS errors', async ({ page }) => {
  const consoleErrors = setupConsoleErrorCapture(page);

  // Test pages that use complex Livewire forms
  const pagesToTest = [
    '/manual-license-grant',
    '/failed-jobs',
  ];

  for (const pagePath of pagesToTest) {
    consoleErrors.length = 0; // Reset errors for each page

    await page.goto(`${BASE_URL}${pagePath}`);
    await waitForLivewire(page);

    // Check for Livewire Entangle errors specifically
    const livewireErrors = consoleErrors.filter(e => e.includes('Livewire'));
    if (livewireErrors.length > 0) {
      throw new Error(`Livewire errors on ${pagePath}: ${livewireErrors.join(', ')}`);
    }

    // Page should render without critical errors
    const bodyText = await page.evaluate(() => document.body.innerText);
    expect(bodyText).not.toContain('500');
    expect(bodyText).not.toContain('Server Error');
  }
});

// ============================================================================
// DASHBOARD DATE SELECTOR TESTS
// ============================================================================

test('dashboard: date selector is visible with mode tabs', async ({ page }) => {
  await page.goto(BASE_URL);
  await waitForLivewire(page);

  // Date selector should be present on the dashboard
  const dateSelectorButtons = page.locator('button').filter({ hasText: /Dag|Week|Maand|Jaar|Custom/ });
  await expect(dateSelectorButtons.first()).toBeVisible({ timeout: 10000 });

  // All 5 mode tabs should be present
  await expect(page.locator('button', { hasText: 'Dag' })).toBeVisible();
  await expect(page.locator('button', { hasText: 'Week' })).toBeVisible();
  await expect(page.locator('button', { hasText: 'Maand' })).toBeVisible();
  await expect(page.locator('button', { hasText: 'Jaar' })).toBeVisible();
  await expect(page.locator('button', { hasText: 'Custom' })).toBeVisible();
});

test('dashboard: date selector has navigation arrows', async ({ page }) => {
  await page.goto(BASE_URL);
  await waitForLivewire(page);

  // Should have previous and next navigation buttons (chevron icons)
  const prevButton = page.locator('button[title="Vorige"]');
  const nextButton = page.locator('button[title="Volgende"]');

  await expect(prevButton).toBeVisible({ timeout: 10000 });
  await expect(nextButton).toBeVisible();
});

test('dashboard: switching to Custom mode shows date inputs', async ({ page }) => {
  await page.goto(BASE_URL);
  await waitForLivewire(page);

  // Click the Custom tab
  await page.locator('button', { hasText: 'Custom' }).click();
  await waitForLivewire(page);

  // Should show two date input fields
  await expect(page.locator('input[type="date"]').first()).toBeVisible({ timeout: 10000 });
  const dateInputCount = await page.locator('input[type="date"]').count();
  expect(dateInputCount).toBe(2);

  // Navigation arrows should not be visible in custom mode
  await expect(page.locator('button[title="Vorige"]')).not.toBeVisible();
  await expect(page.locator('button[title="Volgende"]')).not.toBeVisible();
});

test('dashboard: switching modes updates the period label', async ({ page }) => {
  await page.goto(BASE_URL);
  await waitForLivewire(page);

  // Click Jaar mode
  await page.locator('button', { hasText: 'Jaar' }).click();
  await waitForLivewire(page);

  // Should show a year label (e.g., "2026")
  const yearLabel = page.locator('text=2026');
  await expect(yearLabel.first()).toBeVisible({ timeout: 10000 });

  // Switch to Dag mode
  await page.locator('button', { hasText: 'Dag' }).click();
  await waitForLivewire(page);

  // Period label should update (no longer just showing "2026" alone)
  // It should contain a full date format
  const periodLabel = page.locator('span.text-center');
  await expect(periodLabel).toBeVisible({ timeout: 10000 });
});

test('dashboard: no JS errors with date selector', async ({ page }) => {
  const consoleErrors = setupConsoleErrorCapture(page);

  await page.goto(BASE_URL);
  await waitForLivewire(page);

  // Click through different modes
  for (const mode of ['Dag', 'Week', 'Maand', 'Jaar', 'Custom']) {
    await page.locator('button', { hasText: mode }).click();
    await waitForLivewire(page);
  }

  // Check for Livewire errors
  const livewireErrors = consoleErrors.filter(e => e.includes('Livewire'));
  if (livewireErrors.length > 0) {
    throw new Error(`Livewire errors during date selector interaction: ${livewireErrors.join(', ')}`);
  }
});
