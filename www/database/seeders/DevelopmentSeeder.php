<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DevelopmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder creates:
     * - Admin user with admin role
     * - EUR and USD licenses (free, test, onetime, premium tiers)
     * - Test users (simple + complete with purchase history)
     */
    public function run(): void
    {
        $this->command->info('🌱 Seeding development database...');

        // 1. Admin User Setup
        $this->seedAdminUser();

        // 2. License Seeds
        $this->seedLicenses();

        // 3. Test Users
        $this->seedTestUsers();

        // 4. Complete Test User with Purchase History
        $this->seedCompleteTestUser();

        $this->command->newLine();
        $this->command->info('✅ Development database seeded successfully!');
        $this->command->newLine();
        $this->command->line('Admin Login:');
        $this->command->line('  URL: http://localhost:8000/beheer/login');
        $this->command->line('  Email: admin@example.com');
        $this->command->line('  Password: admin123');
    }

    private function seedAdminUser(): void
    {
        $this->command->info('  → Creating admin user...');

        // Create admin role
        $adminRole = \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);

        // Create or update admin user with properly hashed password
        $admin = \App\Models\User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => bcrypt('admin123'),
                'email_verified_at' => now(),
                'credits' => 1000,
                'preferred_language' => 'en',
            ]
        );

        // Assign admin role if not already assigned
        if (! $admin->hasRole('admin')) {
            $admin->assignRole('admin');
        }
    }

    private function seedLicenses(): void
    {
        $this->command->info('  → Creating licenses (EUR + USD)...');

        // EUR - FREE TIER
        DB::table('licenses')->insertOrIgnore([
            'slug' => 'free-tier-eur',
            'name' => 'Free Tier (EUR)',
            'tier' => 'free',
            'amount' => 0.00,
            'currency' => 'EUR',
            'billing_cycle' => 'one_time',
            'credits' => 15,
            'credit_reset_interval' => 'monthly',
            'period' => null,
            'ordering' => 1,
            'active' => 1,
            'json_restrictions' => json_encode([
                'upload_limits' => [
                    'global' => [
                        'max_files' => 2,
                        'max_total_size' => 15728640,
                        'max_pages' => 50,
                        'max_file_size' => 10485760,
                    ],
                    'per_conversion' => [
                        'pdfs-to-pdf' => [
                            'max_files' => 2,
                            'max_total_size' => 104857600,
                            'max_file_size' => 52428800,
                        ],
                        'ocr-pdf' => [
                            'max_files' => 1,
                            'max_total_size' => 104857600,
                            'max_file_size' => 104857600,
                        ],
                    ],
                ],
                'feature_restrictions' => [
                    'workflow_builder' => true,
                    'email_support' => true,
                    'api_access' => false,
                    'custom_branding' => false,
                    'priority_queue' => false,
                    'watermark_removal' => false,
                    'advanced_ocr' => false,
                    'team_collaboration' => false,
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // EUR - TEST TIER
        DB::table('licenses')->insertOrIgnore([
            'slug' => 'test-tier-eur',
            'name' => 'Test Tier (EUR)',
            'tier' => 'test',
            'amount' => 10.00,
            'currency' => 'EUR',
            'billing_cycle' => 'one_time',
            'credits' => 500,
            'credit_reset_interval' => 'none',
            'period' => null,
            'ordering' => 5,
            'active' => 1,
            'json_restrictions' => json_encode([
                'upload_limits' => [
                    'global' => [
                        'max_files' => 1,
                        'max_total_size' => 1048576,
                        'max_pages' => 10,
                        'max_file_size' => 1048576,
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
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // EUR - ONE-TIME CREDIT PACKS
        $eurOnetimePacks = [
            ['slug' => 'onetime-200-eur', 'name' => '200 Credits Pack (EUR)', 'amount' => 4.13, 'credits' => 200, 'ordering' => 10],
            ['slug' => 'onetime-800-eur', 'name' => '800 Credits Pack (EUR)', 'amount' => 11.57, 'credits' => 800, 'ordering' => 11],
            ['slug' => 'onetime-3000-eur', 'name' => '3000 Credits Pack (EUR)', 'amount' => 25.62, 'credits' => 3000, 'ordering' => 12],
            ['slug' => 'onetime-10000-eur', 'name' => '10000 Credits Pack (EUR)', 'amount' => 71.90, 'credits' => 10000, 'ordering' => 13],
        ];

        foreach ($eurOnetimePacks as $pack) {
            DB::table('licenses')->insertOrIgnore([
                'slug' => $pack['slug'],
                'name' => $pack['name'],
                'tier' => 'onetime',
                'amount' => $pack['amount'],
                'currency' => 'EUR',
                'billing_cycle' => 'one_time',
                'credits' => $pack['credits'],
                'credit_reset_interval' => 'none',
                'period' => null,
                'ordering' => $pack['ordering'],
                'active' => 1,
                'json_restrictions' => json_encode([
                    'upload_limits' => [
                        'global' => [
                            'max_files' => 10,
                            'max_total_size' => 104857600,
                            'max_pages' => 500,
                            'max_file_size' => 52428800,
                        ],
                    ],
                    'feature_restrictions' => [
                        'workflow_builder' => true,
                        'email_support' => true,
                        'api_access' => true,
                        'custom_branding' => true,
                        'priority_queue' => false,
                        'watermark_removal' => true,
                        'advanced_ocr' => true,
                        'team_collaboration' => false,
                    ],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // EUR - PREMIUM YEARLY SUBSCRIPTIONS
        $eurPremiumPacks = [
            ['slug' => 'premium-800-yearly-eur', 'name' => 'Premium 800/month (EUR)', 'amount' => 9.92, 'credits' => 800, 'ordering' => 20],
            ['slug' => 'premium-3000-yearly-eur', 'name' => 'Premium 3000/month (EUR)', 'amount' => 21.49, 'credits' => 3000, 'ordering' => 21],
            ['slug' => 'premium-10000-yearly-eur', 'name' => 'Premium 10000/month (EUR)', 'amount' => 59.50, 'credits' => 10000, 'ordering' => 22],
        ];

        foreach ($eurPremiumPacks as $pack) {
            DB::table('licenses')->insertOrIgnore([
                'slug' => $pack['slug'],
                'name' => $pack['name'],
                'tier' => 'premium',
                'amount' => $pack['amount'],
                'currency' => 'EUR',
                'billing_cycle' => 'yearly',
                'credits' => $pack['credits'],
                'credit_reset_interval' => 'monthly',
                'period' => 365,
                'ordering' => $pack['ordering'],
                'active' => 1,
                'json_restrictions' => json_encode([
                    'upload_limits' => [
                        'global' => [
                            'max_files' => 100,
                            'max_total_size' => 524288000,
                            'max_pages' => 1000,
                            'max_file_size' => 104857600,
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
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // USD - FREE TIER
        DB::table('licenses')->insertOrIgnore([
            'slug' => 'free-tier-usd',
            'name' => 'Free Tier (USD)',
            'tier' => 'free',
            'amount' => 0.00,
            'currency' => 'USD',
            'billing_cycle' => 'one_time',
            'credits' => 15,
            'credit_reset_interval' => 'monthly',
            'period' => null,
            'ordering' => 2,
            'active' => 1,
            'json_restrictions' => json_encode([
                'upload_limits' => [
                    'global' => [
                        'max_files' => 2,
                        'max_total_size' => 15728640,
                        'max_pages' => 50,
                        'max_file_size' => 10485760,
                    ],
                    'per_conversion' => [
                        'pdfs-to-pdf' => [
                            'max_files' => 2,
                            'max_total_size' => 104857600,
                            'max_file_size' => 52428800,
                        ],
                        'ocr-pdf' => [
                            'max_files' => 1,
                            'max_total_size' => 104857600,
                            'max_file_size' => 104857600,
                        ],
                    ],
                ],
                'feature_restrictions' => [
                    'workflow_builder' => true,
                    'email_support' => true,
                    'api_access' => false,
                    'custom_branding' => false,
                    'priority_queue' => false,
                    'watermark_removal' => false,
                    'advanced_ocr' => false,
                    'team_collaboration' => false,
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // USD - TEST TIER
        DB::table('licenses')->insertOrIgnore([
            'slug' => 'test-tier-usd',
            'name' => 'Test Tier (USD)',
            'tier' => 'test',
            'amount' => 10.00,
            'currency' => 'USD',
            'billing_cycle' => 'one_time',
            'credits' => 500,
            'credit_reset_interval' => 'none',
            'period' => null,
            'ordering' => 6,
            'active' => 1,
            'json_restrictions' => json_encode([
                'upload_limits' => [
                    'global' => [
                        'max_files' => 1,
                        'max_total_size' => 1048576,
                        'max_pages' => 10,
                        'max_file_size' => 1048576,
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
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // USD - ONE-TIME CREDIT PACKS
        $usdOnetimePacks = [
            ['slug' => 'onetime-200-usd', 'name' => '200 Credits Pack (USD)', 'amount' => 4.88, 'credits' => 200, 'ordering' => 14],
            ['slug' => 'onetime-800-usd', 'name' => '800 Credits Pack (USD)', 'amount' => 13.65, 'credits' => 800, 'ordering' => 15],
            ['slug' => 'onetime-3000-usd', 'name' => '3000 Credits Pack (USD)', 'amount' => 30.23, 'credits' => 3000, 'ordering' => 16],
            ['slug' => 'onetime-10000-usd', 'name' => '10000 Credits Pack (USD)', 'amount' => 84.84, 'credits' => 10000, 'ordering' => 17],
        ];

        foreach ($usdOnetimePacks as $pack) {
            DB::table('licenses')->insertOrIgnore([
                'slug' => $pack['slug'],
                'name' => $pack['name'],
                'tier' => 'onetime',
                'amount' => $pack['amount'],
                'currency' => 'USD',
                'billing_cycle' => 'one_time',
                'credits' => $pack['credits'],
                'credit_reset_interval' => 'none',
                'period' => null,
                'ordering' => $pack['ordering'],
                'active' => 1,
                'json_restrictions' => json_encode([
                    'upload_limits' => [
                        'global' => [
                            'max_files' => 10,
                            'max_total_size' => 104857600,
                            'max_pages' => 500,
                            'max_file_size' => 52428800,
                        ],
                    ],
                    'feature_restrictions' => [
                        'workflow_builder' => true,
                        'email_support' => true,
                        'api_access' => true,
                        'custom_branding' => true,
                        'priority_queue' => false,
                        'watermark_removal' => true,
                        'advanced_ocr' => true,
                        'team_collaboration' => false,
                    ],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // USD - PREMIUM YEARLY SUBSCRIPTIONS
        $usdPremiumPacks = [
            ['slug' => 'premium-800-yearly-usd', 'name' => 'Premium 800/month (USD)', 'amount' => 11.70, 'credits' => 800, 'ordering' => 30],
            ['slug' => 'premium-3000-yearly-usd', 'name' => 'Premium 3000/month (USD)', 'amount' => 25.36, 'credits' => 3000, 'ordering' => 31],
            ['slug' => 'premium-10000-yearly-usd', 'name' => 'Premium 10000/month (USD)', 'amount' => 70.21, 'credits' => 10000, 'ordering' => 32],
        ];

        foreach ($usdPremiumPacks as $pack) {
            DB::table('licenses')->insertOrIgnore([
                'slug' => $pack['slug'],
                'name' => $pack['name'],
                'tier' => 'premium',
                'amount' => $pack['amount'],
                'currency' => 'USD',
                'billing_cycle' => 'yearly',
                'credits' => $pack['credits'],
                'credit_reset_interval' => 'monthly',
                'period' => 365,
                'ordering' => $pack['ordering'],
                'active' => 1,
                'json_restrictions' => json_encode([
                    'upload_limits' => [
                        'global' => [
                            'max_files' => 100,
                            'max_total_size' => 524288000,
                            'max_pages' => 1000,
                            'max_file_size' => 104857600,
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
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedTestUsers(): void
    {
        $this->command->info('  → Creating simple test users...');

        // Free User with 15 credits
        \App\Models\User::updateOrCreate(
            ['email' => 'user@test.com'],
            [
                'name' => 'Test User',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'credits' => 15,
                'preferred_language' => 'en',
            ]
        );

        // Premium User with active subscription
        \App\Models\User::updateOrCreate(
            ['email' => 'premium@test.com'],
            [
                'name' => 'Premium User',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'credits' => 500,
                'preferred_language' => 'en',
            ]
        );
    }

    private function seedCompleteTestUser(): void
    {
        $this->command->info('  → Creating complete test user with purchase history...');

        // Create user
        $fullUser = \App\Models\User::updateOrCreate(
            ['email' => 'full@example.com'],
            [
                'name' => 'Full Test User',
                'password' => bcrypt('password'),
                'email_verified_at' => '2025-10-01 10:00:00',
                'credits' => 200,
                'preferred_language' => 'nl',
                'created_at' => '2025-10-01 10:00:00',
                'updated_at' => '2025-10-01 10:00:00',
            ]
        );

        // Get license
        $license = \App\Models\License::where('slug', 'onetime-200-eur')->first();

        if ($license) {
            // Assign license
            \App\Models\UserLicense::updateOrCreate(
                [
                    'user_id' => $fullUser->id,
                    'license_id' => $license->id,
                ],
                [
                    'status' => 'active',
                    'starts_at' => '2025-10-01 10:00:00',
                    'ends_at' => null,
                    'source' => 'mollie',
                    'external_ref' => 'tr_fake123456_oct2025',
                    'is_current' => 1,
                    'created_at' => '2025-10-01 10:00:00',
                    'updated_at' => '2025-10-01 10:00:00',
                ]
            );

            // Create credit ledger entry
            \App\Models\CreditLedger::firstOrCreate(
                [
                    'user_id' => $fullUser->id,
                    'reason' => 'purchase',
                    'created_at' => '2025-10-01 10:00:00',
                ],
                [
                    'batch_id' => null,
                    'workflow_id' => null,
                    'delta' => 200,
                    'balance_after' => 200,
                    'meta' => [
                        'license_id' => $license->id,
                        'license_slug' => 'onetime-200-eur',
                        'payment_source' => 'mollie',
                        'external_ref' => 'tr_fake123456_oct2025',
                        'amount_paid' => '4.13 EUR',
                    ],
                ]
            );
        }

        // Create workflow
        \App\Models\Workflow::updateOrCreate(
            [
                'user_id' => $fullUser->id,
                'name' => 'Merge naar PDF/A',
            ],
            [
                'steps_json' => [
                    [
                        'step' => 'merge_pdfs',
                        'options' => [
                            'page_order' => 'as_uploaded',
                            'include_bookmarks' => true,
                        ],
                    ],
                    [
                        'step' => 'pdf_to_pdfa',
                        'options' => [
                            'flavor' => 'PDF/A-1b',
                        ],
                    ],
                ],
                'status' => 'active',
                'is_default' => 0,
                'output_type' => 'pdf',
                'delivery_method' => 'download',
                'created_at' => '2025-10-01 10:30:00',
                'updated_at' => '2025-10-01 10:30:00',
            ]
        );
    }
}
