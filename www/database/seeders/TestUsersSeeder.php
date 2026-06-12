<?php

namespace Database\Seeders;

use App\Models\CreditLedger;
use App\Models\License;
use App\Models\Order;
use App\Models\User;
use App\Models\UserLicense;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class TestUsersSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🧪 Creating test users with licenses...');

        // 1. Free User
        $freeUser = User::firstOrCreate(
            ['email' => 'free@example.com'],
            [
                'name' => 'Free User',
                'email' => 'free@example.com',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'credits' => 15, // Free tier gets 15 credits
                'country' => 'NL',
            ]
        );

        // Log initial credits for free user
        if ($freeUser->wasRecentlyCreated) {
            CreditLedger::create([
                'user_id' => $freeUser->id,
                'delta' => 15,
                'balance_after' => 15,
                'reason' => 'purchase',
                'meta' => json_encode([
                    'source' => 'registration',
                    'license_tier' => 'free',
                    'description' => 'Initial free tier credits on registration',
                    'seeded' => true,
                ]),
            ]);
        }

        $this->command->info('✅ Free user: free@example.com / password (15 credits)');

        // 2. Onetime User with onetime-3000-eur
        $onetimeUser = User::firstOrCreate(
            ['email' => 'onetime@example.com'],
            [
                'name' => 'Onetime User',
                'email' => 'onetime@example.com',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'credits' => 3000,
                'country' => 'NL',
            ]
        );

        $onetimeLicense = License::where('slug', 'onetime-3000-eur')->first();

        if ($onetimeLicense && $onetimeUser->wasRecentlyCreated) {
            // Create order
            $order = Order::create([
                'payer_type' => 'user',
                'payer_id' => $onetimeUser->id,
                'license_id' => $onetimeLicense->id,
                'type' => 'onetime',
                'net_amount' => $onetimeLicense->amount,
                'tax_amount' => round($onetimeLicense->amount * 0.21, 2),
                'gross_amount' => round($onetimeLicense->amount * 1.21, 2),
                'currency' => 'EUR',
                'payment_method' => 'mollie',
                'status' => 'paid',
                'mollie_payment_id' => 'tr_'.strtoupper(bin2hex(random_bytes(10))),
                'country' => 'NL',
                'vat_id' => null,
                'paid_at' => Carbon::now()->subDays(5),
                'meta' => json_encode(['vat_rate' => 21.0, 'seeded' => true]),
            ]);

            // Create user license
            $userLicense = UserLicense::create([
                'user_id' => $onetimeUser->id,
                'license_id' => $onetimeLicense->id,
                'status' => 'active',
                'source' => 'mollie',
                'external_ref' => $order->id,
                'starts_at' => Carbon::now()->subDays(5),
                'ends_at' => Carbon::now()->addDays($onetimeLicense->period - 5), // 365 days from purchase
                'is_current' => true,
            ]);

            // Log credit purchase
            CreditLedger::create([
                'user_id' => $onetimeUser->id,
                'delta' => 3000,
                'balance_after' => 3000,
                'reason' => 'purchase',
                'meta' => json_encode([
                    'license_id' => $onetimeLicense->id,
                    'license_slug' => $onetimeLicense->slug,
                    'order_id' => $order->id,
                    'user_license_id' => $userLicense->id,
                    'description' => 'Credits from license purchase: '.$onetimeLicense->name,
                    'seeded' => true,
                ]),
            ]);

            $this->command->info('✅ Onetime user: onetime@example.com / password (3000 credits, onetime-3000-eur license)');
        } else {
            $this->command->warn('⚠️  onetime-3000-eur license not found or user already exists');
        }

        // 3. Premium User with premium-3000-monthly-usd
        $premiumUser = User::firstOrCreate(
            ['email' => 'premium@example.com'],
            [
                'name' => 'Premium User',
                'email' => 'premium@example.com',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'credits' => 3000,
                'country' => 'US',
            ]
        );

        $premiumLicense = License::where('slug', 'premium-3000-monthly-usd')->first();

        if ($premiumLicense && $premiumUser->wasRecentlyCreated) {
            // Create order
            $order = Order::create([
                'payer_type' => 'user',
                'payer_id' => $premiumUser->id,
                'license_id' => $premiumLicense->id,
                'type' => 'subscription',
                'net_amount' => $premiumLicense->amount * 12, // Yearly billing
                'tax_amount' => 0, // US, no VAT
                'gross_amount' => $premiumLicense->amount * 12,
                'currency' => 'USD',
                'payment_method' => 'mollie',
                'status' => 'paid',
                'mollie_payment_id' => 'tr_'.strtoupper(bin2hex(random_bytes(10))),
                'country' => 'US',
                'vat_id' => null,
                'paid_at' => Carbon::now()->subDays(10),
                'meta' => json_encode(['vat_rate' => 0, 'billing_cycle' => 'yearly', 'seeded' => true]),
            ]);

            // Create user license (yearly subscription)
            $userLicense = UserLicense::create([
                'user_id' => $premiumUser->id,
                'license_id' => $premiumLicense->id,
                'status' => 'active',
                'source' => 'mollie',
                'external_ref' => $order->id,
                'starts_at' => Carbon::now()->subDays(10),
                'ends_at' => Carbon::now()->addYear()->subDays(10), // 1 year from purchase
                'is_current' => true,
            ]);

            // Log initial credit grant
            CreditLedger::create([
                'user_id' => $premiumUser->id,
                'delta' => 3000,
                'balance_after' => 3000,
                'reason' => 'purchase',
                'meta' => json_encode([
                    'license_id' => $premiumLicense->id,
                    'license_slug' => $premiumLicense->slug,
                    'order_id' => $order->id,
                    'user_license_id' => $userLicense->id,
                    'reset_interval' => 'monthly',
                    'description' => 'Initial credits from license purchase: '.$premiumLicense->name,
                    'seeded' => true,
                ]),
            ]);

            $this->command->info('✅ Premium user: premium@example.com / password (3000 credits, premium-3000-monthly-usd license)');
        } else {
            $this->command->warn('⚠️  premium-3000-monthly-usd license not found or user already exists');
        }

        $this->command->info('');
        $this->command->info('📋 Test Users Summary:');
        $this->command->info('  free@example.com     → Free tier (15 credits)');
        $this->command->info('  onetime@example.com  → Onetime 3000 EUR (3000 credits)');
        $this->command->info('  premium@example.com  → Premium 3000 USD (3000 credits, monthly reset)');
        $this->command->info('  All passwords: password');
    }
}
