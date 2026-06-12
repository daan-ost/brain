import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 1, // Always retry once for Livewire timing stability
  workers: 1,
  reporter: 'html',
  timeout: 30_000, // 30s per test
  globalTimeout: 600_000, // 10 min total — prevents infinite hangs during builds
  use: {
    // Use the production URL from .env for proper asset loading
    baseURL: 'https://basewebsite:8890',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    // Ignore HTTPS errors for self-signed certificates
    ignoreHTTPSErrors: true,
  },
  projects: [
    // Setup project: runs first to authenticate admin (Filament backend)
    {
      name: 'setup',
      testMatch: /auth\.setup\.ts/,
    },

    // Setup project: runs first to authenticate the regular test user
    {
      name: 'user-setup',
      testMatch: /user\.setup\.ts/,
    },

    // Admin tests: use pre-authenticated state
    {
      name: 'admin-tests',
      testMatch: /filament-(admin|crud|user-diagnostics)\.spec\.ts/,
      dependencies: ['setup'],
      use: {
        ...devices['Desktop Chrome'],
        storageState: 'tests/e2e/.auth/admin.json',
      },
    },

    // User-authenticated tests (profile, dashboard, messages, etc.)
    {
      name: 'user-tests',
      testMatch: /(dashboard-profile|support-messages|organization-management|checkout-flow|inbound-email-preferences|two-factor-authentication|demo-items|localization-settings|sender-email-settings)\.spec\.ts/,
      dependencies: ['user-setup'],
      use: {
        ...devices['Desktop Chrome'],
        storageState: 'tests/e2e/.auth/user.json',
      },
    },

    // Authentication flow tests (registration, login, etc.)
    {
      name: 'auth-tests',
      testMatch: /auth-flows\.spec\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },

    // Other tests (public pages, basewebsite directory)
    {
      name: 'chromium',
      testIgnore: [/filament-(admin|crud|user-diagnostics)\.spec\.ts/, /auth\.setup\.ts/, /user\.setup\.ts/, /auth-flows\.spec\.ts/, /dashboard-profile\.spec\.ts/, /support-messages\.spec\.ts/, /organization-management\.spec\.ts/, /checkout-flow\.spec\.ts/, /inbound-email-preferences\.spec\.ts/, /two-factor-authentication\.spec\.ts/, /demo-items\.spec\.ts/, /localization-settings\.spec\.ts/, /sender-email-settings\.spec\.ts/],
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
