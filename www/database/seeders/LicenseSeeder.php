<?php

namespace Database\Seeders;

use App\Models\License;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class LicenseSeeder extends Seeder
{
    /**
     * Seed the licenses table with all available license tiers and options
     */
    public function run(): void
    {
        $this->command->info('🎫 Seeding licenses...');

        // Default JSON restrictions for different tiers
        $freeRestrictions = [
            'upload_limits' => [
                'global' => [
                    'max_files' => 2,
                    'max_total_size' => 20971520, // 20MB
                    'max_pages' => 50,
                    'max_file_size' => 10485760, // 10MB
                ],
            ],
            'feature_restrictions' => [
                'workflow_builder' => false,
                'email_support' => false,
                'api_access' => false,
                'custom_branding' => false,
                'priority_queue' => false,
                'watermark_removal' => false,
                'advanced_ocr' => false,
                'team_collaboration' => false,
            ],
        ];

        $onetimeRestrictions = [
            'upload_limits' => [
                'global' => [
                    'max_files' => 5,
                    'max_total_size' => 104857600, // 100MB
                    'max_pages' => 200,
                    'max_file_size' => 20971520, // 20MB
                ],
            ],
            'feature_restrictions' => [
                'workflow_builder' => false,
                'email_support' => true,
                'api_access' => false,
                'custom_branding' => false,
                'priority_queue' => false,
                'watermark_removal' => true,
                'advanced_ocr' => false,
                'team_collaboration' => true,
            ],
        ];

        $premiumRestrictions = [
            'upload_limits' => [
                'global' => [
                    'max_files' => 10,
                    'max_total_size' => 524288000, // 500MB
                    'max_pages' => 1000,
                    'max_file_size' => 104857600, // 100MB
                ],
            ],
            'feature_restrictions' => [
                'workflow_builder' => true,
                'email_support' => true,
                'api_access' => true,
                'custom_branding' => true,
                'priority_queue' => true,
                'watermark_removal' => true,
                'advanced_ocr' => true,
                'team_collaboration' => true,
            ],
        ];

        $licenses = [
            // ===== FREE TIER =====
            [
                'slug' => 'free-15',
                'name' => 'Free',
                'tier' => 'free',
                'amount' => 0,
                'currency' => 'EUR',
                'billing_cycle' => null,
                'credits' => 15,
                'credit_reset_interval' => 'daily',
                'period' => null,
                'json_restrictions' => $freeRestrictions,
                'ordering' => 100,
                'active' => true,
            ],
            [
                'slug' => 'free-usd',
                'name' => 'Free',
                'tier' => 'free',
                'amount' => 0,
                'currency' => 'USD',
                'billing_cycle' => null,
                'credits' => 15,
                'credit_reset_interval' => 'daily',
                'period' => null,
                'json_restrictions' => $freeRestrictions,
                'ordering' => 100,
                'active' => true,
            ],

            // ===== ONE-TIME CREDITS (EUR) =====
            [
                'slug' => 'onetime-200-eur',
                'name' => '200 Credits',
                'tier' => 'onetime',
                'amount' => 4.13,
                'currency' => 'EUR',
                'billing_cycle' => 'one_time',
                'credits' => 200,
                'credit_reset_interval' => 'none',
                'period' => 90, // 3 months
                'json_restrictions' => $onetimeRestrictions,
                'ordering' => 200,
                'active' => true,
            ],
            [
                'slug' => 'onetime-800-eur',
                'name' => '800 Credits',
                'tier' => 'onetime',
                'amount' => 12.40,
                'currency' => 'EUR',
                'billing_cycle' => 'one_time',
                'credits' => 800,
                'credit_reset_interval' => 'none',
                'period' => 180, // 6 months
                'json_restrictions' => $onetimeRestrictions,
                'ordering' => 201,
                'active' => true,
            ],
            [
                'slug' => 'onetime-3000-eur',
                'name' => '3,000 Credits',
                'tier' => 'onetime',
                'amount' => 28.93,
                'currency' => 'EUR',
                'billing_cycle' => 'one_time',
                'credits' => 3000,
                'credit_reset_interval' => 'none',
                'period' => 365, // 1 year
                'json_restrictions' => $onetimeRestrictions,
                'ordering' => 202,
                'active' => true,
            ],
            [
                'slug' => 'onetime-10000-eur',
                'name' => '10,000 Credits',
                'tier' => 'onetime',
                'amount' => 74.38,
                'currency' => 'EUR',
                'billing_cycle' => 'one_time',
                'credits' => 10000,
                'credit_reset_interval' => 'none',
                'period' => 730, // 2 years
                'json_restrictions' => $onetimeRestrictions,
                'ordering' => 203,
                'active' => true,
            ],

            // ===== ONE-TIME CREDITS (USD) =====
            [
                'slug' => 'onetime-200-usd',
                'name' => '200 Credits',
                'tier' => 'onetime',
                'amount' => 5.00,
                'currency' => 'USD',
                'billing_cycle' => 'one_time',
                'credits' => 200,
                'credit_reset_interval' => 'none',
                'period' => 90,
                'json_restrictions' => $onetimeRestrictions,
                'ordering' => 200,
                'active' => true,
            ],
            [
                'slug' => 'onetime-800-usd',
                'name' => '800 Credits',
                'tier' => 'onetime',
                'amount' => 15.00,
                'currency' => 'USD',
                'billing_cycle' => 'one_time',
                'credits' => 800,
                'credit_reset_interval' => 'none',
                'period' => 180,
                'json_restrictions' => $onetimeRestrictions,
                'ordering' => 201,
                'active' => true,
            ],
            [
                'slug' => 'onetime-3000-usd',
                'name' => '3,000 Credits',
                'tier' => 'onetime',
                'amount' => 35.00,
                'currency' => 'USD',
                'billing_cycle' => 'one_time',
                'credits' => 3000,
                'credit_reset_interval' => 'none',
                'period' => 365,
                'json_restrictions' => $onetimeRestrictions,
                'ordering' => 202,
                'active' => true,
            ],
            [
                'slug' => 'onetime-10000-usd',
                'name' => '10,000 Credits',
                'tier' => 'onetime',
                'amount' => 90.00,
                'currency' => 'USD',
                'billing_cycle' => 'one_time',
                'credits' => 10000,
                'credit_reset_interval' => 'none',
                'period' => 730,
                'json_restrictions' => $onetimeRestrictions,
                'ordering' => 203,
                'active' => true,
            ],

            // ===== PREMIUM TIER (EUR) =====
            [
                'slug' => 'premium-200-monthly-eur',
                'name' => 'Premium 200',
                'tier' => 'premium',
                'amount' => 3.31,
                'currency' => 'EUR',
                'billing_cycle' => 'yearly',
                'credits' => 200,
                'credit_reset_interval' => 'monthly',
                'period' => 30,
                'json_restrictions' => $premiumRestrictions,
                'ordering' => 300,
                'active' => true,
            ],
            [
                'slug' => 'premium-800-monthly-eur',
                'name' => 'Premium 800',
                'tier' => 'premium',
                'amount' => 9.92,
                'currency' => 'EUR',
                'billing_cycle' => 'yearly',
                'credits' => 800,
                'credit_reset_interval' => 'monthly',
                'period' => 30,
                'json_restrictions' => $premiumRestrictions,
                'ordering' => 301,
                'active' => true,
            ],
            [
                'slug' => 'premium-3000-monthly-eur',
                'name' => 'Premium 3,000',
                'tier' => 'premium',
                'amount' => 20.66,
                'currency' => 'EUR',
                'billing_cycle' => 'yearly',
                'credits' => 3000,
                'credit_reset_interval' => 'monthly',
                'period' => 30,
                'json_restrictions' => $premiumRestrictions,
                'ordering' => 302,
                'active' => true,
            ],
            [
                'slug' => 'premium-10000-monthly-eur',
                'name' => 'Premium 10,000',
                'tier' => 'premium',
                'amount' => 57.85,
                'currency' => 'EUR',
                'billing_cycle' => 'yearly',
                'credits' => 10000,
                'credit_reset_interval' => 'monthly',
                'period' => 30,
                'json_restrictions' => $premiumRestrictions,
                'ordering' => 303,
                'active' => true,
            ],
            [
                'slug' => 'premium-101-monthly-eur',
                'name' => 'Premium 101',
                'tier' => 'premium',
                'amount' => 100.00,
                'currency' => 'EUR',
                'billing_cycle' => 'yearly',
                'credits' => 101,
                'credit_reset_interval' => 'monthly',
                'period' => 30,
                'json_restrictions' => $premiumRestrictions,
                'ordering' => 304,
                'active' => true,
            ],

            // ===== PREMIUM TIER (USD) =====
            [
                'slug' => 'premium-200-monthly-usd',
                'name' => 'Premium 200',
                'tier' => 'premium',
                'amount' => 4.00,
                'currency' => 'USD',
                'billing_cycle' => 'yearly',
                'credits' => 200,
                'credit_reset_interval' => 'monthly',
                'period' => 30,
                'json_restrictions' => $premiumRestrictions,
                'ordering' => 300,
                'active' => true,
            ],
            [
                'slug' => 'premium-800-monthly-usd',
                'name' => 'Premium 800',
                'tier' => 'premium',
                'amount' => 12.00,
                'currency' => 'USD',
                'billing_cycle' => 'yearly',
                'credits' => 800,
                'credit_reset_interval' => 'monthly',
                'period' => 30,
                'json_restrictions' => $premiumRestrictions,
                'ordering' => 301,
                'active' => true,
            ],
            [
                'slug' => 'premium-3000-monthly-usd',
                'name' => 'Premium 3,000',
                'tier' => 'premium',
                'amount' => 25.00,
                'currency' => 'USD',
                'billing_cycle' => 'yearly',
                'credits' => 3000,
                'credit_reset_interval' => 'monthly',
                'period' => 30,
                'json_restrictions' => $premiumRestrictions,
                'ordering' => 302,
                'active' => true,
            ],
            [
                'slug' => 'premium-10000-monthly-usd',
                'name' => 'Premium 10,000',
                'tier' => 'premium',
                'amount' => 70.00,
                'currency' => 'USD',
                'billing_cycle' => 'yearly',
                'credits' => 10000,
                'credit_reset_interval' => 'monthly',
                'period' => 30,
                'json_restrictions' => $premiumRestrictions,
                'ordering' => 303,
                'active' => true,
            ],
            [
                'slug' => 'premium-101-monthly-usd',
                'name' => 'Premium 101',
                'tier' => 'premium',
                'amount' => 120.00,
                'currency' => 'USD',
                'billing_cycle' => 'yearly',
                'credits' => 101,
                'credit_reset_interval' => 'monthly',
                'period' => 30,
                'json_restrictions' => $premiumRestrictions,
                'ordering' => 304,
                'active' => true,
            ],
        ];

        foreach ($licenses as $licenseData) {
            License::updateOrCreate(
                ['slug' => $licenseData['slug']],
                array_merge($licenseData, [
                    'valid_from' => Carbon::today(),
                    'valid_until' => null,
                ])
            );
        }

        $this->command->info('✅ '.count($licenses).' licenses seeded successfully');
    }
}
