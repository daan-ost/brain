# Testing

## Overview

The application uses Pest PHP for testing with 1261+ tests covering:
- Unit tests
- Feature tests
- Integration tests
- Filament admin tests

## Running Tests

```bash
# Run all tests
./vendor/bin/pest

# Run specific test file
./vendor/bin/pest tests/Unit/PaymentFulfillmentServiceTest.php

# Run tests with coverage
./vendor/bin/pest --coverage

# Run specific test group
./vendor/bin/pest --group=payments
```

## Test Structure

```
tests/
├── Unit/
│   ├── Services/
│   │   ├── LicenseRenewalServiceTest.php
│   │   ├── PaymentFulfillmentServiceTest.php
│   │   └── ...
│   └── Models/
├── Feature/
│   ├── Profile/
│   │   ├── ProfilePagesTest.php
│   │   ├── PlanControllerTest.php
│   │   └── ...
│   ├── Filament/
│   │   ├── LicenseResourceTest.php
│   │   └── ...
│   ├── Scenarios/
│   │   └── CheckoutPaymentLifecycleTest.php
│   └── ...
└── Pest.php              # Pest configuration
```

## Key Test Files

### Payment Flow Tests

`tests/Feature/Scenarios/CheckoutPaymentLifecycleTest.php`

Tests the complete checkout and payment lifecycle:
- Order creation
- Mollie payment processing
- Webhook handling
- License creation
- Credit allocation

### License Tests

`tests/Unit/Services/LicenseRenewalServiceTest.php`

Tests license renewal calculations:
- Next renewal date calculation
- Cancellation validation
- Mollie subscription cancellation

### Filament Admin Tests

`tests/Feature/Filament/LicenseResourceTest.php`

Tests admin panel functionality:
- Authorization (admin-only access)
- CRUD operations
- License tiers and billing cycles

## Test Helpers

### Pest Configuration

`tests/Pest.php` includes:
- `RefreshDatabase` trait for all tests
- Custom helper functions
- Test groups

### Custom Assertions

```php
// Assert license is active
assertUserLicenseIsActive($userLicense);

// Assert credit pool
assertOrganizationHasCreditPool($organization, 2000);

// Assert ledger entry
assertCreditLedgerEntryComplete($ledgerEntry);
```

## Database

Tests use a separate test database configured in `phpunit.xml`:

```xml
<env name="DB_DATABASE" value="testing"/>
```

Each test class uses `RefreshDatabase` to ensure a clean state.

## Mocking External Services

### Mollie API

Tests mock Mollie API responses:

```php
// Create test order with Mollie payment ID
$order = Order::factory()->create([
    'mollie_payment_id' => 'tr_test123',
    'mollie_customer_id' => 'cst_test456',
]);
```

### Postmark Email

Email sending is logged during tests (configured in `phpunit.xml`):

```xml
<env name="MAIL_MAILER" value="log"/>
```

## Code Coverage

Generate coverage report:

```bash
./vendor/bin/pest --coverage --min=80
```

Coverage requirements:
- Minimum 80% overall
- Focus on critical paths (payments, licenses)

## Continuous Integration

Example GitHub Actions workflow:

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'

      - name: Install dependencies
        run: composer install

      - name: Run tests
        run: ./vendor/bin/pest
```

## Writing Tests

### Unit Test Example

```php
<?php

use App\Services\LicensePriceChangeService;
use App\Models\License;

test('it schedules price change correctly', function () {
    $license = License::factory()->create(['amount' => 49.00]);
    $service = app(LicensePriceChangeService::class);

    $service->schedulePriceChange(
        license: $license,
        newAmount: 59.00,
        newCredits: null,
        effectiveFrom: now()->addMonth()
    );

    $license->refresh();

    expect($license->upcoming_amount)->toBe('59.00');
    expect($license->price_effective_from)->not->toBeNull();
});
```

### Feature Test Example

```php
<?php

use App\Models\User;
use App\Models\UserLicense;

test('user can cancel subscription', function () {
    $user = User::factory()->create();
    $license = UserLicense::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->post(route('profile.plans.cancel-renewal', $license->id))
        ->assertRedirect();

    expect($license->fresh()->ends_at)->not->toBeNull();
});
```

## Related Files

- `tests/Pest.php` - Pest configuration
- `phpunit.xml` - PHPUnit configuration
- `tests/TestCase.php` - Base test case
