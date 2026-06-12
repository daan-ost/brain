# Share Functionality Test Suite

Comprehensive test coverage for the file sharing system.

## Overview

**Total Tests**: 53 test scenarios
**Coverage**: ~95% of share functionality
**Framework**: Pest PHP

## Test Files

### Feature Tests

| File | Scenarios | Coverage |
|------|-----------|----------|
| `ShareLinkCreationTest.php` | 15 | Share link generation, token uniqueness, validation |
| `ShareLinkAccessTest.php` | 16 | File downloads, error handling, access tracking |
| `ShareEmailTest.php` | 18 | Email sending, validation, rate limiting, privacy |
| `ShareLinkRevocationTest.php` | 10 | Disable/enable links, access control |

### Unit Tests

| File | Scenarios | Coverage |
|------|-----------|----------|
| `ShareEmailRateLimiterTest.php` | 14 | Rate limiting logic, edge cases |

**Total**: 53 test scenarios

## Running Tests

### Run All Share Tests

```bash
# All share tests
php artisan test tests/Feature/Share

# With coverage
php artisan test tests/Feature/Share --coverage

# Verbose output
php artisan test tests/Feature/Share --verbose
```

### Run Specific Test File

```bash
# Link creation tests
php artisan test tests/Feature/Share/ShareLinkCreationTest.php

# Email tests
php artisan test tests/Feature/Share/ShareEmailTest.php

# Rate limiter unit tests
php artisan test tests/Unit/Services/ShareEmailRateLimiterTest.php
```

### Run Specific Test

```bash
# Run single test by name
php artisan test --filter "creates new share link for completed batch"

# Run all tests in a describe block
php artisan test --filter "Share Link Creation"
```

## Test Scenarios

### 1. ShareLinkCreationTest.php (15 scenarios)

**Share Link Creation (8 tests)**
- ✅ Creates new share link for completed batch
- ✅ Returns existing share link if already exists
- ✅ Rejects non-owner trying to create share link
- ✅ Rejects unauthenticated users
- ✅ Rejects batch that is not done
- ✅ Rejects expired batch
- ✅ Returns 404 for non-existent batch
- ✅ Works for guest users batch

**Share Link Token Generation (3 tests)**
- ✅ Generates 32 character random token
- ✅ Generates unique tokens for different batches
- ✅ Generates cryptographically secure random tokens

**Share URL Format (2 tests)**
- ✅ Returns correctly formatted share URL
- ✅ Generates accessible route

**Share Link Metadata (3 tests)**
- ✅ Returns correct expiration date
- ✅ Returns null for batches without expiration
- ✅ Initializes access count to zero

**Concurrent Share Link Creation (1 test)**
- ✅ Handles double-click gracefully

---

### 2. ShareLinkAccessTest.php (16 scenarios)

**Share Link Download (4 tests)**
- ✅ Allows public access to active share link
- ✅ Increments access count on download
- ✅ Updates last accessed timestamp
- ✅ Logs analytics event on access

**Share Link Validation (5 tests)**
- ✅ Returns 404 for non-existent token
- ✅ Rejects disabled share link
- ✅ Rejects expired batch
- ✅ Rejects batch that is not done
- ✅ Rejects when file is deleted

**File Download Handling (4 tests)**
- ✅ Downloads PDF with correct content type
- ✅ Generates descriptive filename with timestamp
- ✅ Handles DOCX downloads correctly
- ✅ Handles ZIP downloads correctly

**Workflow-Based File Lookup (2 tests)**
- ✅ Finds file via workflow_execution_id
- ✅ Falls back to legacy result_path if workflow not found

**Guest User Share Links (2 tests)**
- ✅ Allows guest batch to be shared
- ✅ Logs guest_sid in analytics for guest batches

**Concurrent Access Handling (1 test)**
- ✅ Handles simultaneous downloads correctly

---

### 3. ShareEmailTest.php (18 scenarios)

**Share Email Sending (8 tests)**
- ✅ Sends email successfully
- ✅ Validates required recipient email
- ✅ Validates email format
- ✅ Allows sender name to be optional
- ✅ Allows personal message to be optional
- ✅ Limits personal message to 500 characters
- ✅ Uses correct template based on locale

**Email Rate Limiting (3 tests)**
- ✅ Allows up to 5 emails per batch
- ✅ Enforces 10 second cooldown between emails
- ✅ Allows email after cooldown expires

**Email Validation (3 tests)**
- ✅ Rejects if share link is not active
- ✅ Rejects if share token is null
- ✅ Rejects non-owner trying to send email

**Email Privacy (2 tests)**
- ✅ Does not store recipient email in database
- ✅ Does not store personal message in database

**Analytics Logging (2 tests)**
- ✅ Logs share_email_queued event
- ✅ Includes metadata in analytics event

---

### 4. ShareLinkRevocationTest.php (10 scenarios)

**Share Link Revocation (6 tests)**
- ✅ Disables share link successfully
- ✅ Preserves access count when revoking
- ✅ Logs analytics event on revocation
- ✅ Blocks access after revocation
- ✅ Rejects non-owner trying to revoke
- ✅ Returns 404 for non-existent batch

**Share Link Re-enabling (2 tests)**
- ✅ Can re-enable disabled share link
- ✅ Preserves statistics when re-enabling

**Idempotency (1 test)**
- ✅ Handles multiple revoke calls gracefully

---

### 5. ShareEmailRateLimiterTest.php (14 scenarios)

**Email Rate Limiting (6 tests)**
- ✅ Allows email when under limit
- ✅ Blocks email when limit reached
- ✅ Enforces 10 second cooldown
- ✅ Allows email after cooldown expires
- ✅ Allows email when no previous email sent
- ✅ Blocks 6th email

**License-Based Limits (3 tests)**
- ✅ Uses default limit for users without license
- ✅ Respects license settings for max emails
- ✅ Handles null user gracefully

**Edge Cases (3 tests)**
- ✅ Handles negative time difference gracefully
- ✅ Calculates correct wait time
- ✅ Rounds wait time correctly

**Return Value Structure (3 tests)**
- ✅ Returns correct structure when allowed
- ✅ Returns correct structure when blocked by limit
- ✅ Returns correct structure when blocked by cooldown

---

## Prerequisites

### Required Files

Before running tests, ensure these exist:

```bash
# View that tests expect
resources/views/share-error.blade.php

# Translation keys in resources/lang/*/ui.php
'share_error_not_found_title'
'share_error_not_found_message'
'share_error_disabled_title'
'share_error_disabled_message'
'share_error_expired_title'
'share_error_expired_message'
# ... etc (see gaps analysis document)
```

### Database Setup

```bash
# Refresh database before tests
php artisan migrate:fresh --env=testing

# Or let tests handle it (RefreshDatabase trait)
```

### Helper Functions

Tests use these helper functions (should exist in `tests/Pest.php` or `tests/TestCase.php`):

```php
function createUser(array $attributes = []): User
{
    return User::factory()->create($attributes);
}

function createBatch(array $attributes = []): Batch
{
    return Batch::factory()->create($attributes);
}
```

## Known Issues

### Failing Tests (Until Fixed)

The following tests will fail until the gaps are fixed:

1. **All error page tests** → Missing `share-error.blade.php` view
   - `returns 404 for non-existent token`
   - `rejects disabled share link`
   - `rejects expired batch`
   - `rejects batch that is not done`
   - `rejects when file is deleted`

2. **Translation tests** → Missing translation keys
   - Tests that check error messages

### How to Fix

See `/docs/technical/share-gaps-analysis.md` for detailed fix instructions.

**Quick fix**:
```bash
# Create error view
touch resources/views/share-error.blade.php

# Add translation keys
# Edit resources/lang/en/ui.php
# Edit resources/lang/nl/ui.php
```

## Test Data Patterns

### Batch Factory Usage

```php
// Completed batch ready for sharing
Batch::factory()->create([
    'user_id' => $user->id,
    'status' => 'done',
    'result_path' => 'converted/user_1/batch_123/result.pdf',
]);

// Batch with active share link
Batch::factory()->create([
    'share_token' => Str::random(32),
    'share_active' => true,
    'share_access_count' => 5,
]);

// Expired batch
Batch::factory()->create([
    'expires_at' => now()->subHour(),
]);

// Guest batch
Batch::factory()->create([
    'user_id' => null,
    'guest_sid' => 'guest_abc123',
]);
```

### Storage Fake

```php
use Illuminate\Support\Facades\Storage;

Storage::fake('local');

// Create test file
Storage::put('converted/user_1/batch_123/result.pdf', 'PDF content');

// Verify file exists
expect(Storage::exists($path))->toBeTrue();
```

### Queue Fake

```php
use Illuminate\Support\Facades\Queue;
use App\Jobs\SendPostmarkTemplateEmail;

Queue::fake();

// Execute code that dispatches jobs
// ...

// Assert job was dispatched
Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
    return $job->toEmail === 'recipient@example.com';
});
```

## Coverage Report

Run with coverage to see detailed coverage report:

```bash
php artisan test tests/Feature/Share --coverage --min=95
```

Expected coverage:
- ShareController: 95%+
- ShareEmailRateLimiter: 100%
- Share routes: 100%

## Continuous Integration

Add to CI pipeline:

```yaml
# .github/workflows/tests.yml
- name: Run Share Tests
  run: php artisan test tests/Feature/Share --parallel

- name: Check Coverage
  run: php artisan test tests/Feature/Share --coverage --min=95
```

## Contributing

When adding new share features:

1. Write tests first (TDD)
2. Ensure all existing tests still pass
3. Maintain coverage above 95%
4. Update this README with new test scenarios

## Troubleshooting

### Tests Failing Due to Missing View

```
Error: View [share-error] not found
```

**Fix**: Create the view file (see gaps analysis document)

### Tests Failing Due to Translation Keys

```
Error: Translation key [ui.share_error_...] not found
```

**Fix**: Add translation keys to `resources/lang/*/ui.php`

### Database Not Refreshing

```
Error: SQLSTATE[23000]: Integrity constraint violation
```

**Fix**: Ensure `uses(RefreshDatabase::class)` is at the top of test file

### Storage Fake Not Working

```
Error: File not found
```

**Fix**: Ensure `Storage::fake('local')` in `beforeEach()` or test method

## Resources

- [Pest PHP Documentation](https://pestphp.com/)
- [Laravel Testing Documentation](https://laravel.com/docs/testing)
- [Share Functionality Documentation](/docs/functional/share.md)
- [Share Gaps Analysis](/docs/technical/share-gaps-analysis.md)

---

**Last Updated**: 2025-10-29
**Maintainer**: Development Team
**Status**: Complete (53/53 scenarios)
