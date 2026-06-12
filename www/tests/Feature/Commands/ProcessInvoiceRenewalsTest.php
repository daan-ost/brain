<?php

namespace Tests\Feature\Commands;

use App\Jobs\SendPostmarkTemplateEmail;
use App\Models\License;
use App\Models\LicenseNotification;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Services\InvoiceGenerationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ProcessInvoiceRenewalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');

        // Mock InvoiceGenerationService to avoid PDF generation
        $mockInvoiceService = Mockery::mock(InvoiceGenerationService::class);
        $mockInvoiceService->shouldReceive('generateInvoice')
            ->andReturnUsing(function ($order) {
                // Simulate invoice number generation
                $order->update([
                    'invoice_number' => 'INV-'.now()->format('Ymd').'-'.str_pad($order->id, 4, '0', STR_PAD_LEFT),
                    'invoice_file_path' => 'invoices/test.pdf',
                    'invoice_date' => now(),
                ]);

                return [
                    'invoice_number' => $order->invoice_number,
                    'invoice_file_path' => $order->invoice_file_path,
                    'invoice_date' => $order->invoice_date,
                ];
            });
        $this->app->instance(InvoiceGenerationService::class, $mockInvoiceService);
    }

    public function test_command_runs_successfully(): void
    {
        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful()
            ->expectsOutputToContain('Invoice Renewals Complete');
    }

    public function test_dry_run_does_not_create_licenses_or_orders(): void
    {
        $organization = $this->createOrganizationWithInvoiceLicense(
            isTrusted: true,
            renewalDaysAgo: 0 // Due today
        );

        $initialLicenseCount = OrganizationLicense::count();
        $initialOrderCount = Order::count();

        $this->artisan('license:process-invoice-renewals', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('dry-run mode');

        // No new licenses or orders should be created
        $this->assertEquals($initialLicenseCount, OrganizationLicense::count());
        $this->assertEquals($initialOrderCount, Order::count());

        // No emails should be sent
        Queue::assertNothingPushed();
    }

    public function test_trusted_org_gets_active_license_and_credits_reset(): void
    {
        $organization = $this->createOrganizationWithInvoiceLicense(
            isTrusted: true,
            renewalDaysAgo: 0, // Due today
            initialCredits: 50
        );

        $license = $organization->organizationLicenses()->first();
        $licenseCredits = $license->license->credits;

        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful()
            ->expectsOutputToContain('Renewals processed');

        // New license should be created with status 'active'
        $newLicense = OrganizationLicense::where('organization_id', $organization->id)
            ->where('source', 'invoice_renewal')
            ->first();

        $this->assertNotNull($newLicense);
        $this->assertEquals('active', $newLicense->status);
        $this->assertTrue($newLicense->is_current);
        $this->assertEquals('unpaid', $newLicense->payment_status);

        // Credits should be reset to license amount
        $organization->refresh();
        $this->assertEquals($licenseCredits, $organization->creditPool->balance_credits);

        // Credit ledger entry should exist
        $this->assertDatabaseHas('organization_credit_ledger', [
            'organization_id' => $organization->id,
            'reason' => 'reset_renewal',
            'balance_after' => $licenseCredits,
        ]);

        // Email should be sent
        Queue::assertPushed(SendPostmarkTemplateEmail::class);
    }

    public function test_non_trusted_org_gets_pending_license_no_credit_reset(): void
    {
        $initialCredits = 50;
        $organization = $this->createOrganizationWithInvoiceLicense(
            isTrusted: false,
            renewalDaysAgo: 0, // Due today
            initialCredits: $initialCredits
        );

        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful();

        // New license should be created with status 'pending'
        $newLicense = OrganizationLicense::where('organization_id', $organization->id)
            ->where('source', 'invoice_renewal')
            ->first();

        $this->assertNotNull($newLicense);
        $this->assertEquals('pending', $newLicense->status);
        $this->assertFalse($newLicense->is_current); // Not current until payment
        $this->assertEquals('unpaid', $newLicense->payment_status);

        // Credits should NOT be reset
        $organization->refresh();
        $this->assertEquals($initialCredits, $organization->creditPool->balance_credits);

        // No credit ledger entry for reset
        $this->assertDatabaseMissing('organization_credit_ledger', [
            'organization_id' => $organization->id,
            'reason' => 'reset_renewal',
        ]);

        // Email should still be sent
        Queue::assertPushed(SendPostmarkTemplateEmail::class);
    }

    public function test_skips_license_not_due_for_renewal(): void
    {
        // License that started recently, renewal is in the future
        $organization = $this->createOrganizationWithInvoiceLicense(
            isTrusted: true,
            renewalDaysAgo: -20 // Renewal in 20 days (future)
        );

        $initialLicenseCount = OrganizationLicense::count();

        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful()
            ->expectsOutputToContain('Skipped (not due)');

        // No new license should be created
        $this->assertEquals($initialLicenseCount, OrganizationLicense::count());
        Queue::assertNothingPushed();
    }

    public function test_skips_already_processed_renewal(): void
    {
        $organization = $this->createOrganizationWithInvoiceLicense(
            isTrusted: true,
            renewalDaysAgo: 0 // Due today
        );

        $license = $organization->organizationLicenses()->first();

        // Mark as already processed
        LicenseNotification::create([
            'organization_license_id' => $license->id,
            'notification_type' => LicenseNotification::TYPE_INVOICE_RENEWAL_30_DAYS,
            'sent_at' => now()->subDays(5),
        ]);

        $initialLicenseCount = OrganizationLicense::count();

        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful()
            ->expectsOutputToContain('Skipped (already renewed)');

        // No new license should be created
        $this->assertEquals($initialLicenseCount, OrganizationLicense::count());
    }

    public function test_creates_order_for_renewal(): void
    {
        $organization = $this->createOrganizationWithInvoiceLicense(
            isTrusted: true,
            renewalDaysAgo: 0
        );

        $license = $organization->organizationLicenses()->first();

        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful();

        // Order should be created
        $order = Order::where('payer_type', 'organization')
            ->where('payer_id', $organization->id)
            ->where('status', 'pending')
            ->first();

        $this->assertNotNull($order);
        $this->assertEquals($license->license_id, $order->license_id);
        $this->assertEquals('invoice_renewal', $order->meta['type']);
    }

    public function test_sends_invoice_pending_payment_email(): void
    {
        $organization = $this->createOrganizationWithInvoiceLicense(
            isTrusted: false,
            renewalDaysAgo: 0
        );

        // Add admin to organization
        $admin = User::factory()->create(['preferred_language' => 'nl']);
        $organization->users()->attach($admin->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful();

        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('templateAlias');
            $property->setAccessible(true);

            return str_contains($property->getValue($job), 'invoice-pending-payment');
        });
    }

    public function test_catches_up_renewals_from_past_7_days(): void
    {
        // Renewal was 3 days ago (missed run)
        $organization = $this->createOrganizationWithInvoiceLicense(
            isTrusted: true,
            renewalDaysAgo: 3
        );

        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful();

        // Should still process it
        $newLicense = OrganizationLicense::where('organization_id', $organization->id)
            ->where('source', 'invoice_renewal')
            ->first();

        $this->assertNotNull($newLicense);
    }

    public function test_skips_renewals_older_than_7_days(): void
    {
        // Renewal was 10 days ago (too old)
        $organization = $this->createOrganizationWithInvoiceLicense(
            isTrusted: true,
            renewalDaysAgo: 10
        );

        $initialLicenseCount = OrganizationLicense::count();

        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful();

        // Should not process it
        $this->assertEquals($initialLicenseCount, OrganizationLicense::count());
    }

    // ==================== HELPER METHODS ====================

    private function createOrganizationWithInvoiceLicense(
        bool $isTrusted,
        int $renewalDaysAgo,
        int $initialCredits = 100
    ): Organization {
        // Freeze time to avoid edge case timing issues
        // Set to midday to avoid timezone issues
        $frozenTime = Carbon::create(2025, 11, 28, 12, 0, 0);
        Carbon::setTestNow($frozenTime);

        // Create organization
        $organization = Organization::factory()
            ->withCreditPool($initialCredits)
            ->create([
                'is_trusted' => $isTrusted,
            ]);

        // Add admin user
        $admin = User::factory()->create();
        $organization->users()->attach($admin->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        // Create premium license with monthly billing
        $license = License::factory()->create([
            'tier' => 'premium',
            'billing_cycle' => 'monthly',
            'credits' => 500,
            'amount' => 49.00,
            'currency' => 'EUR',
            'active' => true,
        ]);

        // Calculate starts_at so renewal falls on the desired date
        // renewalDaysAgo = 0 means renewal is today -> started exactly 1 month ago (Oct 28)
        // renewalDaysAgo = 3 means renewal was 3 days ago -> started Oct 25
        // renewalDaysAgo = -20 means renewal is in 20 days -> started Nov 17
        $startsAt = Carbon::create(2025, 10, 28, 12, 0, 0)->subDays($renewalDaysAgo);

        // Create organization license
        OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'active',
            'starts_at' => $startsAt,
            'ends_at' => null,
            'billing_method' => 'invoice',
            'payment_status' => 'paid',
            'source' => 'manual',
            'external_ref' => 'initial-'.uniqid(),
            'is_current' => true,
        ]);

        return $organization;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // Reset frozen time
        parent::tearDown();
    }
}
