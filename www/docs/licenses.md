# License System

## Overview

The license system supports multiple tiers and billing cycles for both individual users and organizations.

## License Tiers

| Tier | Description | Billing |
|------|-------------|---------|
| `free` | Free tier with limited credits | N/A |
| `onetime` | One-time purchase | One-time |
| `premium` | Recurring subscription | Monthly/Yearly |
| `enterprise` | Custom enterprise plans | Invoice |
| `test` | Testing purposes | N/A |

## Database Structure

### `licenses` table
Master table defining available license types.

| Field | Description |
|-------|-------------|
| `name` | Display name |
| `slug` | Unique identifier |
| `tier` | License tier (free/onetime/premium/enterprise) |
| `amount` | Price |
| `upcoming_amount` | Scheduled new price (nullable) |
| `currency` | EUR/USD |
| `billing_cycle` | monthly/yearly/one_time |
| `credits` | Number of credits included |
| `upcoming_credits` | Scheduled new credits (nullable) |
| `credit_reset_interval` | When credits reset |
| `price_effective_from` | Date when upcoming price takes effect |
| `period` | Validity period in days (for one-time) |

### `user_licenses` table
Links users to their licenses.

| Field | Description |
|-------|-------------|
| `user_id` | User reference |
| `license_id` | License reference |
| `price_at_purchase` | Price when purchased |
| `currency_at_purchase` | Currency when purchased |
| `status` | active/inactive/canceled/expired/trial |
| `starts_at` | License start date |
| `ends_at` | License end date (null for recurring) |
| `last_credit_reset_at` | Last credit reset timestamp |
| `price_change_notified_at` | When price change notification was sent |
| `mollie_subscription_id` | Mollie subscription ID |
| `mollie_customer_id` | Mollie customer ID |
| `source` | How license was created (mollie/admin/etc) |
| `external_ref` | External reference (payment ID) |

### `organization_licenses` table
Same structure as `user_licenses` but for organizations, plus:

| Field | Description |
|-------|-------------|
| `billing_method` | online/invoice |
| `payment_status` | paid/unpaid (for invoices) |
| `invoice_number` | Generated invoice number |
| `invoice_due_date` | Invoice due date |

## License Lifecycle

### Premium Subscription Flow

```
1. User purchases license
   └── Order created (status: initiated)

2. Mollie payment completed
   └── Webhook received
   └── Order status → paid
   └── UserLicense created (status: active, ends_at: null)
   └── Mollie subscription created
   └── Credits added to user

3. Monthly/Yearly renewal
   └── Mollie charges subscription
   └── Webhook received
   └── Credits reset
   └── Renewal order created

4. User cancels
   └── /profile/plans → Cancel Renewal
   └── Mollie subscription canceled
   └── ends_at set to next renewal date
   └── License active until ends_at
```

### Cancel Subscription

Users can cancel their subscription at `/profile/plans`:

1. Click "Cancel Renewal" button
2. Confirm cancellation
3. Mollie subscription is canceled
4. License remains active until next renewal date
5. After `ends_at`, license expires

**Code location**: `app/Services/LicenseRenewalService.php::cancelRenewal()`

## Price Changes

### Scheduling a Price Change

In Filament admin (`/beheer/licenses`):

1. Edit a premium license
2. Click "Schedule Price Change"
3. Set new price, credits, and effective date
4. Confirm

### What Happens

| Scenario | Action |
|----------|--------|
| Renewal > 30 days away | Email sent 30 days before renewal |
| Renewal 7-30 days away | Email sent immediately |
| Renewal < 7 days away | User keeps OLD price, notified at next renewal |

### Cronjob Command

```bash
# Daily at 09:00
php artisan license:process-price-changes --apply-effective

# Options
--dry-run           # Preview without making changes
--apply-effective   # Apply price changes that reached effective date
```

### Process

1. Check for licenses where `price_effective_from <= today`
2. Update Mollie subscription amounts
3. Apply new price to license
4. Send notification emails to users within 30 days of renewal

## Services

### LicensePriceChangeService

Main service for price change management.

```php
// Schedule a price change
$service->schedulePriceChange($license, $newAmount, $newCredits, $effectiveFrom);

// Get impact analysis
$impact = $service->getPriceChangeImpact($license);
// Returns: total_user_licenses, renewals_within_7_days, renewals_within_30_days, etc.

// Cancel scheduled change
$service->cancelScheduledPriceChange($license);
```

### LicenseRenewalService

Handles renewal dates and cancellation.

```php
// Get next renewal date
$date = $service->getNextRenewalDate($license->starts_at, 'yearly');

// Cancel renewal
$result = $service->cancelRenewal($license, 'user');
```

### PaymentFulfillmentService

Handles order fulfillment after payment.

```php
// Fulfill paid order (creates license, adds credits, creates subscription)
$service->fulfillOrder($order);
```

## Credit System

Credits are managed per-user and per-organization:

- **User Credits**: `users.credits` column
- **Organization Credits**: `organization_credit_pool` table
- **Ledger**: `credit_ledger` and `organization_credit_ledger` tables

### Credit Reset

When a subscription renews:
1. Credits are reset based on `credit_reset_interval`
2. Old credits are expired (LIFO)
3. New credits are added
4. `last_credit_reset_at` is updated

## Related Files

- `app/Models/License.php`
- `app/Models/UserLicense.php`
- `app/Models/OrganizationLicense.php`
- `app/Services/LicensePriceChangeService.php`
- `app/Services/LicenseRenewalService.php`
- `app/Services/PaymentFulfillmentService.php`
- `app/Services/LicenseCreditResetService.php`
- `app/Filament/Resources/LicenseResource.php`
- `app/Console/Commands/ProcessPriceChangeNotifications.php`
