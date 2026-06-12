# Scheduled Tasks (Cronjobs)

## Overview

The application uses several scheduled commands for maintenance and automated tasks.

## Commands

### Price Change Notifications

Processes scheduled price changes and sends notification emails.

```bash
php artisan license:process-price-changes --apply-effective
```

**Schedule**: Daily at 09:00

**Options**:
- `--dry-run` - Preview changes without making them
- `--apply-effective` - Apply price changes that have reached their effective date

**What it does**:
1. Checks for licenses with `price_effective_from <= today`
2. Updates Mollie subscription amounts for affected users
3. Applies the new price to the license
4. Sends notification emails to users within 30 days of renewal

**Example output**:
```
Processing price change notifications...

Checking for price changes to apply...
+----------------+---------------+------------+----------------+
| License        | Current Price | New Price  | Effective Date |
+----------------+---------------+------------+----------------+
| Premium Yearly | EUR 49.00     | EUR 59.00  | 2025-01-01     |
+----------------+---------------+------------+----------------+

Processing: Premium Yearly
  Updating Mollie subscriptions...
  Success: 45, Failed: 0, Skipped: 3
  Price change applied

Checking for notifications to send...
Found 12 user(s) and 3 organization(s) to notify.

User notifications:
+------------+------------------+----------------+---------------+------------+
| User       | Email            | License        | Current Price | New Price  |
+------------+------------------+----------------+---------------+------------+
| John Doe   | john@example.com | Premium Yearly | EUR 49.00     | EUR 59.00  |
+------------+------------------+----------------+---------------+------------+
Sent 12/12 user notifications

Done!
```

### Other Commands (examples)

```bash
# Credit reset for expired licenses
php artisan license:process-credit-resets

# Clean expired sessions
php artisan session:gc

# Process notification queue
php artisan queue:work --once
```

## Crontab Setup

Add to server crontab:

```cron
# Laravel scheduler (runs every minute)
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1

# Or run specific commands at set times:
0 9 * * * cd /path/to/project && php artisan license:process-price-changes --apply-effective >> /var/log/price-changes.log 2>&1
```

## Laravel Scheduler

If using Laravel's built-in scheduler, add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule): void
{
    // Process price changes daily at 09:00
    $schedule->command('license:process-price-changes --apply-effective')
        ->dailyAt('09:00')
        ->withoutOverlapping()
        ->onOneServer();
}
```

## Monitoring

Check command logs:

```bash
# View recent runs
tail -f storage/logs/laravel.log | grep "price-change"

# Check for errors
grep -i "error\|failed" storage/logs/laravel.log
```

## Related Files

- `app/Console/Commands/ProcessPriceChangeNotifications.php`
- `app/Console/Kernel.php` (scheduler configuration)
