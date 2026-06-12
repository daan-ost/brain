<?php

namespace Database\Seeders;

use App\Enums\DemoItemPriority;
use App\Enums\DemoItemStatus;
use App\Models\DemoItem;
use App\Models\License;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationCreditPool;
use App\Models\OrganizationDomain;
use App\Models\User;
use App\Models\UserLicense;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PlaywrightTestSeeder extends Seeder
{
    /**
     * Seed data voor Playwright E2E tests
     * Maakt test user aan met subscription
     */
    public function run(): void
    {
        $this->command->info('🎭 Seeding data for Playwright tests...');

        // 1. Maak test user aan (of update)
        $testUser = User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'credits' => 100,
                'credits_updated_at' => now(),
                'preferred_language' => 'en',
            ]
        );

        $this->command->info('✅ Test user: test@example.com / password');

        // 2. Maak admin user aan (voor Filament backend testing)
        $adminUser = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('admin123'),
                'email_verified_at' => now(),
                'credits' => 1000,
                'credits_updated_at' => now(),
                'preferred_language' => 'en',
                'is_admin' => true,
            ]
        );

        // Create admin role if it doesn't exist
        $adminRole = \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);

        // Assign admin role if not already assigned
        if (! $adminUser->hasRole('admin')) {
            $adminUser->assignRole($adminRole);
        }

        $this->command->info('✅ Admin user: admin@example.com / admin123');

        // 3. Maak een license aan als die nog niet bestaat
        $premiumLicense = License::firstOrCreate(
            ['slug' => 'premium-monthly-test'],
            [
                'name' => 'Premium Monthly (Test)',
                'tier' => 'premium',
                'amount' => 999, // €9.99
                'currency' => 'EUR',
                'billing_cycle' => 'monthly',
                'credits' => 100,
                'period' => 30,
                'json_restrictions' => [
                    'upload_limits' => [
                        'global' => [
                            'max_files' => 100,
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
                ],
                'ordering' => 100,
                'active' => true,
                'valid_from' => Carbon::today(),
                'valid_until' => null,
            ]
        );

        $this->command->info('✅ Premium license created');

        // 4. Geef test user een actieve subscription (als die nog niet bestaat)
        $existingLicense = UserLicense::where('user_id', $testUser->id)
            ->where('license_id', $premiumLicense->id)
            ->where('status', 'active')
            ->first();

        if (! $existingLicense) {
            UserLicense::create([
                'user_id' => $testUser->id,
                'license_id' => $premiumLicense->id,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => now()->addMonth(),
                'source' => 'manual',  // mollie, manual, etc.
                'is_current' => true,
            ]);

            $this->command->info('✅ Active subscription created for test user');
        } else {
            $this->command->info('ℹ️  Test user already has active subscription');
        }

        // 5. Maak profile test user 1 aan (voor profiel account testen - Engels)
        $testUserProfile1 = User::updateOrCreate(
            ['email' => 'test+profile1@example.com'],
            [
                'name' => 'Profile Test User 1',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'preferred_language' => 'en',
                'billing_country_code' => 'US',
                'credits' => 50,
                'credits_updated_at' => now(),
            ]
        );
        $this->command->info('✅ Profile test user 1 (EN): test+profile1@example.com / password');

        // 9. Maak profile test user 2 aan (voor taal wisseling testen - Nederlands)
        $testUserProfile2 = User::updateOrCreate(
            ['email' => 'test+profile2@example.com'],
            [
                'name' => 'Profile Test User 2',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'preferred_language' => 'nl',
                'billing_country_code' => 'NL',
                'credits' => 75,
                'credits_updated_at' => now(),
            ]
        );
        $this->command->info('✅ Profile test user 2 (NL): test+profile2@example.com / password');

        // 10. Maak profile test user 3 aan (voor account deletion testen)
        $testUserProfile3 = User::updateOrCreate(
            ['email' => 'test+profile3@example.com'],
            [
                'name' => 'Profile Delete Test',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'preferred_language' => 'en',
                'billing_country_code' => 'GB',
                'credits' => 25,
                'credits_updated_at' => now(),
            ]
        );
        $this->command->info('✅ Profile test user 3 (deletion): test+profile3@example.com / password');

        // 11. Maak guest analytics test user aan
        $testUserGuestAnalytics = User::updateOrCreate(
            ['email' => 'test+guestanalytics@example.com'],
            [
                'name' => 'Guest Analytics Test',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'preferred_language' => 'en',
                'billing_country_code' => 'US',
                'credits' => 100,
                'credits_updated_at' => now(),
            ]
        );
        $this->command->info('✅ Guest analytics test user: test+guestanalytics@example.com / password');

        // 12. Maak test user zonder transacties aan (voor empty state testing)
        $testUserNoTransactions = User::updateOrCreate(
            ['email' => 'test+notransactions@example.com'],
            [
                'name' => 'No Transactions Test',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'preferred_language' => 'en',
                'billing_country_code' => 'US',
                'credits' => 15,
                'credits_updated_at' => now(),
            ]
        );
        $this->command->info('✅ No transactions test user: test+notransactions@example.com / password');

        // Create demo items test data (if feature is enabled)
        if (config('features.demo_crud')) {
            $this->createDemoItemTestData($testUser);
        }

        // 10. Create organization test users and organizations
        $this->createOrganizationTestData();

        // 11. Create organization invoices for testing
        $this->createOrganizationInvoices();

        $this->command->info("\n🎉 Playwright test data ready!");
        $this->command->info('   Users:');
        $this->command->info('   1. admin@example.com / admin123 (admin role - Filament backend)');
        $this->command->info('   2. test@example.com / password (admin of Test Organization)');
        $this->command->info('   3. test+profile1@example.com / password (profile EN, 10 transactions)');
        $this->command->info('   4. test+profile2@example.com / password (profile NL, 3 transactions)');
        $this->command->info('   5. test+profile3@example.com / password (profile deletion)');
        $this->command->info('   6. test+guestanalytics@example.com / password (guest analytics testing)');
        $this->command->info('   7. test+notransactions@example.com / password (no transactions - empty state testing)');
        $this->command->info('   8. test+orgadmin@example.com / password (organization admin)');
        $this->command->info('   9. test+orgmember@example.com / password (organization member)');
        $this->command->info('   10. test+noorg@example.com / password (no organization - can create)');
        $this->command->info("   11. test+unverified@example.com / password (unverified email)\n");
    }

    /**
     * Create demo item test data
     */
    private function createDemoItemTestData(User $testUser): void
    {
        $this->command->info("\n📋 Setting up demo items test data...");

        // Remove existing demo items for this user to avoid duplicates
        DemoItem::where('user_id', $testUser->id)->forceDelete();

        // 3 draft items
        DemoItem::create(['user_id' => $testUser->id, 'title' => 'Draft Report Q1', 'description' => 'Quarterly report draft', 'status' => DemoItemStatus::Draft, 'priority' => DemoItemPriority::Low, 'amount' => 150.00, 'due_date' => now()->addDays(14)]);
        DemoItem::create(['user_id' => $testUser->id, 'title' => 'Draft Budget Plan', 'description' => 'Annual budget planning', 'status' => DemoItemStatus::Draft, 'priority' => DemoItemPriority::Medium, 'amount' => 500.00, 'due_date' => now()->addDays(30)]);
        DemoItem::create(['user_id' => $testUser->id, 'title' => 'Draft Marketing Brief', 'description' => 'Campaign brief for Q2', 'status' => DemoItemStatus::Draft, 'priority' => DemoItemPriority::High, 'amount' => 250.00]);

        // 3 active items (1 overdue)
        DemoItem::create(['user_id' => $testUser->id, 'title' => 'Active Website Redesign', 'description' => 'Redesign the company website', 'status' => DemoItemStatus::Active, 'priority' => DemoItemPriority::High, 'amount' => 2500.00, 'due_date' => now()->addDays(7)]);
        DemoItem::create(['user_id' => $testUser->id, 'title' => 'Active API Integration', 'description' => 'Integrate third-party payment API', 'status' => DemoItemStatus::Active, 'priority' => DemoItemPriority::Urgent, 'amount' => 1200.00, 'due_date' => now()->addDays(3)]);
        DemoItem::create(['user_id' => $testUser->id, 'title' => 'Overdue Server Migration', 'description' => 'Migrate servers to new infrastructure', 'status' => DemoItemStatus::Active, 'priority' => DemoItemPriority::Urgent, 'amount' => 800.00, 'due_date' => now()->subDays(5)]);

        // 2 completed items
        DemoItem::create(['user_id' => $testUser->id, 'title' => 'Completed Logo Design', 'description' => 'New company logo', 'status' => DemoItemStatus::Completed, 'priority' => DemoItemPriority::Medium, 'amount' => 350.00, 'completed_at' => now()->subDays(3)]);
        DemoItem::create(['user_id' => $testUser->id, 'title' => 'Completed SEO Audit', 'description' => 'Full SEO audit of website', 'status' => DemoItemStatus::Completed, 'priority' => DemoItemPriority::Low, 'amount' => 175.00, 'completed_at' => now()->subWeek()]);

        // 2 cancelled items
        DemoItem::create(['user_id' => $testUser->id, 'title' => 'Cancelled Social Campaign', 'description' => 'Social media campaign cancelled', 'status' => DemoItemStatus::Cancelled, 'priority' => DemoItemPriority::Low, 'amount' => 100.00]);
        DemoItem::create(['user_id' => $testUser->id, 'title' => 'Cancelled Print Materials', 'description' => 'Print brochure cancelled', 'status' => DemoItemStatus::Cancelled, 'priority' => DemoItemPriority::Medium, 'amount' => 75.00]);

        $this->command->info('✅ Created 10 demo items for test user');
    }

    /**
     * Create organization test data
     */
    private function createOrganizationTestData(): void
    {
        $this->command->info("\n🏢 Setting up organization test data...");

        // 1. Create test organization with admin
        $orgAdmin = User::updateOrCreate(
            ['email' => 'test+orgadmin@example.com'],
            [
                'name' => 'Organization Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'credits' => 50,
                'credits_updated_at' => now(),
                'preferred_language' => 'en',
                'billing_country_code' => 'NL',
            ]
        );

        $testOrganization = Organization::updateOrCreate(
            ['name' => 'Test Organization'],
            [
                'slug' => 'test-organization',
                'billing_country_code' => 'NL',
                'currency_preference' => 'EUR',
                'vat_number' => 'NL123456789B01',
                'vat_validated_at' => now(),
                'is_trusted' => true,
            ]
        );

        // Attach admin to organization
        if (! $testOrganization->users()->where('user_id', $orgAdmin->id)->exists()) {
            $testOrganization->users()->attach($orgAdmin->id, [
                'role' => 'admin',
                'joined_at' => now()->subMonths(3),
            ]);
        }

        // Also make the main test user an admin of this organization
        if (! $testOrganization->users()->where('user_id', User::where('email', 'test@example.com')->first()->id)->exists()) {
            $testOrganization->users()->attach(
                User::where('email', 'test@example.com')->first()->id,
                [
                    'role' => 'admin',
                    'joined_at' => now()->subMonths(6),
                ]
            );
        }

        // Create credit pool for organization
        OrganizationCreditPool::updateOrCreate(
            ['organization_id' => $testOrganization->id],
            [
                'balance_credits' => 250,
                'updated_at' => now(),
            ]
        );

        $this->command->info('✅ Test Organization created with 250 credits');

        // 2. Create organization member
        $orgMember = User::updateOrCreate(
            ['email' => 'test+orgmember@example.com'],
            [
                'name' => 'Organization Member',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'credits' => 25,
                'credits_updated_at' => now(),
                'preferred_language' => 'en',
                'billing_country_code' => 'NL',
            ]
        );

        // Attach member to organization
        if (! $testOrganization->users()->where('user_id', $orgMember->id)->exists()) {
            $testOrganization->users()->attach($orgMember->id, [
                'role' => 'member',
                'joined_at' => now()->subMonths(1),
            ]);
        }

        $this->command->info('✅ Organization members created');

        // 3. Create organization domains
        OrganizationDomain::updateOrCreate(
            [
                'organization_id' => $testOrganization->id,
                'domain' => 'testorg.com',
            ],
            [
                'is_primary' => true,
                'validated' => true,
                'validated_at' => now()->subMonths(2),
                'auto_enroll_with_verified_domain' => true,
                'max_storage_days' => 365,
                'support_email' => 'support@testorg.com',
                'valid_until' => now()->addYear(),
                'active' => true,
            ]
        );

        OrganizationDomain::updateOrCreate(
            [
                'organization_id' => $testOrganization->id,
                'domain' => 'testorg.nl',
            ],
            [
                'is_primary' => false,
                'validated' => false,
                'validated_at' => null,
                'auto_enroll_with_verified_domain' => false,
                'max_storage_days' => 180,
                'support_email' => null,
                'valid_until' => now()->addMonths(6),
                'active' => false,
            ]
        );

        $this->command->info('✅ Organization domains created (testorg.com, testorg.nl)');

        // 4. Create user without organization (can create org)
        $noOrgUser = User::updateOrCreate(
            ['email' => 'test+noorg@example.com'],
            [
                'name' => 'User Without Organization',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'credits' => 15,
                'credits_updated_at' => now(),
                'preferred_language' => 'en',
                'billing_country_code' => 'US',
            ]
        );

        $this->command->info('✅ User without organization created (can create org)');

        // 5. Create unverified user (cannot create org)
        $unverifiedUser = User::updateOrCreate(
            ['email' => 'test+unverified@example.com'],
            [
                'name' => 'Unverified User',
                'password' => Hash::make('password'),
                'email_verified_at' => null, // NOT VERIFIED
                'credits' => 15,
                'credits_updated_at' => now(),
                'preferred_language' => 'en',
                'billing_country_code' => 'GB',
            ]
        );

        $this->command->info('✅ Unverified user created (cannot create org)');

        // 6. Create second organization for multi-org testing
        $secondOrganization = Organization::updateOrCreate(
            ['name' => 'Second Test Org'],
            [
                'slug' => 'second-test-org',
                'billing_country_code' => 'BE',
                'currency_preference' => 'EUR',
                'vat_number' => 'BE0123456789',
                'vat_validated_at' => now(),
                'is_trusted' => false,
            ]
        );

        // Attach org member to second organization (testing multi-org membership)
        if (! $secondOrganization->users()->where('user_id', $orgMember->id)->exists()) {
            $secondOrganization->users()->attach($orgMember->id, [
                'role' => 'member',
                'joined_at' => now()->subWeeks(2),
            ]);
        }

        // Create credit pool for second organization
        OrganizationCreditPool::updateOrCreate(
            ['organization_id' => $secondOrganization->id],
            [
                'balance_credits' => 100,
                'updated_at' => now(),
            ]
        );

        $this->command->info('✅ Second organization created (multi-org member test)');

        $this->command->info("\n📊 Organization test data summary:");
        $this->command->info('   Organizations:');
        $this->command->info('   - Test Organization (NL, 250 credits, 2 domains, 3 members)');
        $this->command->info('   - Second Test Org (BE, 100 credits, 0 domains, 1 member)');
        $this->command->info('   ');
        $this->command->info('   Organization Members:');
        $this->command->info('   - test@example.com (admin of Test Organization)');
        $this->command->info('   - test+orgadmin@example.com (admin of Test Organization)');
        $this->command->info('   - test+orgmember@example.com (member of Test Org + Second Test Org)');
    }

    /**
     * Create organization invoices for testing
     */
    private function createOrganizationInvoices(): void
    {
        $this->command->info("\n💰 Creating organization invoices...");

        $testOrganization = Organization::where('name', 'Test Organization')->first();
        $premiumLicense = License::where('slug', 'premium-monthly-test')->first();

        if (! $testOrganization || ! $premiumLicense) {
            $this->command->warn('⚠️  Cannot create invoices: organization or license not found');

            return;
        }

        // Create 2 paid organization invoices
        $invoiceDate1 = now()->subMonths(2);
        $invoiceDate2 = now()->subMonths(1);

        Order::updateOrCreate(
            ['invoice_number' => '2025-Q1-00001'],
            [
                'payer_type' => 'organization',
                'payer_id' => $testOrganization->id,
                'license_id' => $premiumLicense->id,
                'type' => 'subscription',
                'currency' => 'EUR',
                'net_amount' => 82.64,
                'tax_amount' => 17.36,
                'gross_amount' => 100.00,
                'country' => 'NL',
                'vat_id' => 'NL123456789B01',
                'status' => 'paid',
                'invoice_number' => '2025-Q1-00001',
                'invoice_file_path' => null, // Will be generated on-the-fly
                'invoice_date' => $invoiceDate1,
                'paid_at' => $invoiceDate1->copy()->addHours(2),
                'payment_method' => 'ideal',
                'billing_snapshot' => [
                    'billing_name' => 'Test Organization',
                    'billing_email' => 'billing@testorg.com',
                    'billing_address' => '123 Test Street',
                    'billing_city' => 'Amsterdam',
                    'billing_postal_code' => '1012 AB',
                    'billing_country' => 'NL',
                ],
            ]
        );

        Order::updateOrCreate(
            ['invoice_number' => '2025-Q1-00002'],
            [
                'payer_type' => 'organization',
                'payer_id' => $testOrganization->id,
                'license_id' => $premiumLicense->id,
                'type' => 'subscription',
                'currency' => 'EUR',
                'net_amount' => 82.64,
                'tax_amount' => 17.36,
                'gross_amount' => 100.00,
                'country' => 'NL',
                'vat_id' => 'NL123456789B01',
                'status' => 'paid',
                'invoice_number' => '2025-Q1-00002',
                'invoice_file_path' => null, // Will be generated on-the-fly
                'invoice_date' => $invoiceDate2,
                'paid_at' => $invoiceDate2->copy()->addHours(1),
                'payment_method' => 'ideal',
                'billing_snapshot' => [
                    'billing_name' => 'Test Organization',
                    'billing_email' => 'billing@testorg.com',
                    'billing_address' => '123 Test Street',
                    'billing_city' => 'Amsterdam',
                    'billing_postal_code' => '1012 AB',
                    'billing_country' => 'NL',
                ],
            ]
        );

        // Also create a personal invoice for test@example.com
        $testUser = User::where('email', 'test@example.com')->first();
        if ($testUser) {
            Order::updateOrCreate(
                ['invoice_number' => '2025-Q1-00003'],
                [
                    'payer_type' => 'user',
                    'payer_id' => $testUser->id,
                    'license_id' => $premiumLicense->id,
                    'type' => 'subscription',
                    'currency' => 'EUR',
                    'net_amount' => 8.26,
                    'tax_amount' => 1.73,
                    'gross_amount' => 9.99,
                    'country' => 'NL',
                    'status' => 'paid',
                    'invoice_number' => '2025-Q1-00003',
                    'invoice_file_path' => null,
                    'invoice_date' => now()->subWeeks(2),
                    'paid_at' => now()->subWeeks(2)->addHours(1),
                    'payment_method' => 'creditcard',
                    'billing_snapshot' => [
                        'billing_name' => 'Test User',
                        'billing_email' => 'test@example.com',
                    ],
                ]
            );
        }

        $this->command->info('✅ Created 2 organization invoices + 1 personal invoice');
    }

}
