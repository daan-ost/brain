<?php

namespace App\Console\Commands;

use App\Models\License;
use Illuminate\Console\Command;
use Stripe\Price;
use Stripe\Product;
use Stripe\Stripe;

class StripeSyncPricesCommand extends Command
{
    protected $signature = 'stripe:sync-prices
                            {--dry-run : Show what would happen without making API calls}
                            {--license= : Sync only a specific license by ID or slug}';

    protected $description = 'Sync Stripe Products and Prices for licenses with payment_provider=stripe';

    private bool $dryRun = false;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        if ($this->dryRun) {
            $this->info('[DRY-RUN] No Stripe API calls will be made.');
        }

        if (! $this->dryRun) {
            $key = config('services.stripe.secret_key');
            if (empty($key)) {
                $this->error('STRIPE_SECRET_KEY is not configured.');

                return self::FAILURE;
            }

            Stripe::setApiKey($key);
            Stripe::setApiVersion(config('services.stripe.api_version', '2025-04-30.basil'));
        }

        $query = License::where('payment_provider', 'stripe')
            ->where('active', true);

        if ($licenseFilter = $this->option('license')) {
            $query->where(function ($q) use ($licenseFilter) {
                $q->where('id', $licenseFilter)->orWhere('slug', $licenseFilter);
            });
        }

        $licenses = $query->get();

        if ($licenses->isEmpty()) {
            $this->warn('No active licenses with payment_provider=stripe found.');

            return self::SUCCESS;
        }

        $this->info("Syncing {$licenses->count()} license(s)...");
        $synced = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($licenses as $license) {
            try {
                $changed = $this->syncLicense($license);
                $changed ? $synced++ : $skipped++;
            } catch (\Throwable $e) {
                $this->error("  ✗ [{$license->slug}] {$e->getMessage()}");
                $errors++;
            }
        }

        $this->newLine();
        $this->info("Done. Synced: {$synced} | Skipped (already up-to-date): {$skipped} | Errors: {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    public function syncLicense(License $license): bool
    {
        $this->line("Processing [{$license->slug}] {$license->name} (€{$license->amount} / {$license->billing_cycle})");

        // Step 1: ensure Product exists
        $productId = $this->ensureProduct($license);

        // Step 2: check if current Price is still correct
        if ($license->stripe_price_id && ! $this->dryRun) {
            $price = Price::retrieve($license->stripe_price_id);
            $expectedCents = (int) round((float) $license->amount * 100);
            $expectedInterval = $this->billingCycleToInterval($license->billing_cycle);

            $priceMatches = $price->unit_amount === $expectedCents
                && strtolower($price->currency) === strtolower($license->currency)
                && $price->recurring?->interval === $expectedInterval['interval']
                && ($price->recurring?->interval_count ?? 1) === ($expectedInterval['interval_count'] ?? 1)
                && $price->active;

            if ($priceMatches) {
                $this->line("  → Price unchanged, skipping.");

                return false;
            }

            // Archive old price before creating new one
            $this->line("  → Price changed, archiving old Price {$license->stripe_price_id}");
            if (! $this->dryRun) {
                Price::update($license->stripe_price_id, ['active' => false]);
            }
        }

        if ($this->dryRun) {
            $interval = $this->billingCycleToInterval($license->billing_cycle);
            $this->line("  [DRY-RUN] Would create Price: {$license->amount} {$license->currency} / {$interval['interval']}");

            return true;
        }

        // Step 3: create new Price
        $interval = $this->billingCycleToInterval($license->billing_cycle);
        $priceParams = [
            'product' => $productId,
            'unit_amount' => (int) round((float) $license->amount * 100),
            'currency' => strtolower($license->currency),
            'recurring' => $interval,
            'metadata' => [
                'license_id' => (string) $license->id,
                'license_slug' => $license->slug,
            ],
        ];

        $price = Price::create($priceParams);

        $license->update(['stripe_price_id' => $price->id]);

        $this->line("  ✓ Created Price {$price->id}");

        return true;
    }

    private function ensureProduct(License $license): string
    {
        if ($license->stripe_product_id) {
            if (! $this->dryRun) {
                // Update product name in case it changed
                Product::update($license->stripe_product_id, [
                    'name' => $license->name,
                    'metadata' => ['license_id' => (string) $license->id],
                ]);
            }

            return $license->stripe_product_id;
        }

        if ($this->dryRun) {
            $this->line('  [DRY-RUN] Would create Product: '.$license->name);

            return 'prod_dryrun';
        }

        $product = Product::create([
            'name' => $license->name,
            'metadata' => [
                'license_id' => (string) $license->id,
                'license_slug' => $license->slug,
            ],
        ]);

        $license->update(['stripe_product_id' => $product->id]);

        $this->line("  ✓ Created Product {$product->id}");

        return $product->id;
    }

    private function billingCycleToInterval(string $cycle): array
    {
        return match ($cycle) {
            'monthly' => ['interval' => 'month', 'interval_count' => 1],
            'yearly' => ['interval' => 'year', 'interval_count' => 1],
            '6month' => ['interval' => 'month', 'interval_count' => 6],
            default => ['interval' => 'month', 'interval_count' => 1],
        };
    }
}
