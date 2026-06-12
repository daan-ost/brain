# Admin Panel Testing Guide

This document explains how to run and maintain the admin panel test suite.

## Overview

The admin panel (Filament) is tested using two complementary approaches:

| Layer | Tool | Purpose | Tests |
|-------|------|---------|-------|
| Unit/Feature | Pest PHP | Authorization, policies, resource logic | ~210 tests |
| E2E | Playwright | Smoke tests for critical UI flows | 5 tests |

## Running Tests Locally

### Pest PHP Tests

```bash
# Run all tests
./vendor/bin/pest

# Run only admin/Filament tests
./vendor/bin/pest tests/Feature/Filament/ tests/Feature/Admin/ tests/Feature/Policies/

# Run specific test file
./vendor/bin/pest tests/Feature/Admin/AdminPanelAccessTest.php
```

### Playwright E2E Tests

```bash
# Run all E2E tests
npx playwright test

# Run with UI mode (for debugging)
npx playwright test --ui

# Run specific test file
npx playwright test tests/e2e/filament-admin.spec.ts

# View last test report
npx playwright show-report
```

## Test Database Setup

The test suite uses a separate MySQL database (`pdfengine_test`). Before running tests for the first time or after schema changes:

```bash
# Refresh test database with latest migrations
php artisan migrate:fresh --env=testing
```

This is configured in `phpunit.xml`:
- `DB_DATABASE=pdfengine_test`
- `DB_HOST=127.0.0.1`
- `DB_PORT=8889`

## CI Expectations

### Pest Tests
- **Expected result**: 100% pass, 0 skipped
- **Duration**: ~90 seconds
- **Gate**: All tests must pass for PR merge

### Playwright Tests
- **Expected result**: 5 smoke tests pass
- **Retries**: 1 (configured for Livewire timing tolerance)
- **Duration**: ~30 seconds

## Architecture

### Pest Test Structure

```
tests/
├── Feature/
│   ├── Admin/
│   │   ├── AdminPanelAccessTest.php    # Route access control
│   │   └── *AdminTest.php              # Admin-specific functionality
│   ├── Filament/
│   │   └── *ResourceTest.php           # Filament resource tests
│   └── Policies/
│       └── *PolicyTest.php             # Authorization policies
└── Unit/
    └── Services/
        └── *Test.php                   # Service unit tests
```

### Playwright Test Structure

```
tests/e2e/
├── auth.setup.ts              # Global auth setup (storageState)
└── filament-admin.spec.ts     # 5 smoke tests
```

## Authentication

### Pest Tests
Tests use `actingAs()` with the `admin` guard:

```php
$admin = User::factory()->create(['is_admin' => true]);
$this->actingAs($admin, 'admin')->get('/beheer/users');
```

### Playwright Tests
Uses Playwright's `storageState` pattern:

1. `auth.setup.ts` logs in once and saves session to `tests/e2e/.auth/admin.json`
2. All subsequent tests reuse this session
3. Tests that need fresh context call `page.context().clearCookies()`

Test credentials (from seeder):
- Email: `admin@basewebsite.test`
- Password: `password`

## PHP intl Extension

Some Filament components (Number::format()) require the PHP `intl` extension for full rendering. The test suite is designed to work without it:

- **With intl**: Full page rendering works
- **Without intl**: Tests verify authorization only (not-302, not-403)

To install intl on macOS:
```bash
brew install php@8.2
# or for MAMP: enable in php.ini
```

## Smoke Tests (Playwright)

The 5 smoke tests cover critical paths:

1. **Login flow** - Form renders, credentials work, redirects to dashboard
2. **Dashboard access** - Authenticated users see dashboard
3. **Resource access** - Users, Orders, Licenses, Organizations are reachable
4. **CRUD form** - Create forms render with fields
5. **Auth protection** - Unauthenticated access redirects to login

## Troubleshooting

### "Unknown column" errors
Run migrations on test database:
```bash
php artisan migrate:fresh --env=testing
```

### Playwright timeout on login
1. Ensure dev server is running at `https://basewebsite:8890`
2. Check that admin user is seeded: `php artisan db:seed`
3. Increase timeout in `auth.setup.ts` if needed

### Tests pass individually but fail in suite
Usually a test isolation issue. Check for:
- Missing `RefreshDatabase` trait
- Shared state between tests
- Factory callbacks with side effects

## Adding New Tests

### New Pest Test
```php
// tests/Feature/Admin/NewFeatureTest.php
describe('New Feature', function () {
    it('works for admin users', function () {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin, 'admin')
            ->get('/beheer/new-feature')
            ->assertOk();
    });
});
```

### New Playwright Smoke Test
Only add if testing a critical user-facing flow not covered by Pest. Keep the suite at 3-5 tests maximum.
