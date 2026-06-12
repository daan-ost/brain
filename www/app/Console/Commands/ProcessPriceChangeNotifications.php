<?php

namespace App\Console\Commands;

use App\Models\License;
use App\Services\LicensePriceChangeService;
use Illuminate\Console\Command;

class ProcessPriceChangeNotifications extends Command
{
    protected $signature = 'license:process-price-changes
                            {--dry-run : Show what would be done without making changes}
                            {--apply-effective : Also apply price changes that have reached their effective date}';

    protected $description = 'Process price change notifications and apply effective price changes';

    public function handle(LicensePriceChangeService $service): int
    {
        $dryRun = $this->option('dry-run');
        $applyEffective = $this->option('apply-effective');

        $this->info('Processing price change notifications...');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Step 1: Apply price changes that have reached their effective date
        if ($applyEffective) {
            $this->applyEffectivePriceChanges($service, $dryRun);
        }

        // Step 2: Send notifications to users whose renewal is within 30 days
        $this->sendNotifications($service, $dryRun);

        $this->info('Done!');

        return Command::SUCCESS;
    }

    private function applyEffectivePriceChanges(LicensePriceChangeService $service, bool $dryRun): void
    {
        $this->info('');
        $this->info('Checking for price changes to apply...');

        $licensesWithEffectiveChanges = License::whereNotNull('upcoming_amount')
            ->whereNotNull('price_effective_from')
            ->where('price_effective_from', '<=', now())
            ->get();

        if ($licensesWithEffectiveChanges->isEmpty()) {
            $this->info('No price changes ready to apply.');

            return;
        }

        $this->table(
            ['License', 'Current Price', 'New Price', 'Effective Date'],
            $licensesWithEffectiveChanges->map(fn ($l) => [
                $l->name,
                $l->currency.' '.number_format($l->amount, 2),
                $l->currency.' '.number_format($l->upcoming_amount, 2),
                $l->price_effective_from->format('Y-m-d'),
            ])
        );

        foreach ($licensesWithEffectiveChanges as $license) {
            $this->info("Processing: {$license->name}");

            if (! $dryRun) {
                // First update all Mollie subscriptions
                $this->info('  Updating Mollie subscriptions...');
                $results = $service->updateMollieSubscriptions($license);
                $this->info("  Success: {$results['success']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}");

                if (! empty($results['errors'])) {
                    foreach ($results['errors'] as $error) {
                        $this->error("  Error ({$error['license_type']} {$error['license_id']}): {$error['error']}");
                    }
                }

                // Then apply the price change
                $service->applyScheduledPriceChange($license);
                $this->info('  Price change applied');
            } else {
                $this->info('  [DRY RUN] Would update Mollie subscriptions and apply price change');
            }
        }
    }

    private function sendNotifications(LicensePriceChangeService $service, bool $dryRun): void
    {
        $this->info('');
        $this->info('Checking for notifications to send...');

        $licenses = $service->getLicensesNeedingNotification();

        $userCount = $licenses['user_licenses']->count();
        $orgCount = $licenses['org_licenses']->count();

        if ($userCount === 0 && $orgCount === 0) {
            $this->info('No notifications to send.');

            return;
        }

        $this->info("Found {$userCount} user(s) and {$orgCount} organization(s) to notify.");

        // Send user notifications
        if ($userCount > 0) {
            $this->info('');
            $this->info('User notifications:');

            $this->table(
                ['User', 'Email', 'License', 'Current Price', 'New Price'],
                $licenses['user_licenses']->map(fn ($ul) => [
                    $ul->user?->name ?? 'Unknown',
                    $ul->user?->email ?? 'N/A',
                    $ul->license?->name ?? 'Unknown',
                    ($ul->license?->currency ?? 'EUR').' '.number_format($ul->price_at_purchase ?? $ul->license?->amount ?? 0, 2),
                    ($ul->license?->currency ?? 'EUR').' '.number_format($ul->license?->upcoming_amount ?? 0, 2),
                ])
            );

            if (! $dryRun) {
                $bar = $this->output->createProgressBar($userCount);
                $bar->start();

                $sent = 0;
                foreach ($licenses['user_licenses'] as $userLicense) {
                    if ($service->sendUserNotification($userLicense)) {
                        $sent++;
                    }
                    $bar->advance();
                }

                $bar->finish();
                $this->info('');
                $this->info("Sent {$sent}/{$userCount} user notifications");
            }
        }

        // Send organization notifications
        if ($orgCount > 0) {
            $this->info('');
            $this->info('Organization notifications:');

            $this->table(
                ['Organization', 'License', 'Current Price', 'New Price'],
                $licenses['org_licenses']->map(fn ($ol) => [
                    $ol->organization?->name ?? 'Unknown',
                    $ol->license?->name ?? 'Unknown',
                    ($ol->license?->currency ?? 'EUR').' '.number_format($ol->price_at_purchase ?? $ol->license?->amount ?? 0, 2),
                    ($ol->license?->currency ?? 'EUR').' '.number_format($ol->license?->upcoming_amount ?? 0, 2),
                ])
            );

            if (! $dryRun) {
                $bar = $this->output->createProgressBar($orgCount);
                $bar->start();

                $sent = 0;
                foreach ($licenses['org_licenses'] as $orgLicense) {
                    if ($service->sendOrganizationNotification($orgLicense)) {
                        $sent++;
                    }
                    $bar->advance();
                }

                $bar->finish();
                $this->info('');
                $this->info("Sent {$sent}/{$orgCount} organization notifications");
            }
        }
    }
}
