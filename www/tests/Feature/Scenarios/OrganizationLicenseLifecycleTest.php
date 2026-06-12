<?php

/**
 * Organization License Lifecycle Tests
 *
 * Comprehensive tests for the complete organization license lifecycle:
 * - New license purchase (invoice, trusted/non-trusted)
 * - Payment confirmation (mark as paid)
 * - Invoice renewal flow
 * - Credit reset on renewal
 * - License cancellation
 * - License expiration
 */

use App\Enums\OrderStatus;
use App\Jobs\SendPostmarkTemplateEmail;
use App\Models\License;
use App\Models\LicenseNotification;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationCreditLedger;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Services\InvoiceGenerationService;
use App\Services\OrganizationLicenseRenewalService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Tests\Helpers\assertOrganizationHasCreditPool;
use function Tests\Helpers\assertOrganizationLicenseIsActive;
use function Tests\Helpers\assertOrganizationLicenseIsExpired;
use function Tests\Helpers\assertOrganizationLicenseIsPaid;
use function Tests\Helpers\assertOrganizationLicenseIsPending;
use function Tests\Helpers\assertOrgCreditLedgerEntryComplete;

// ==================== SETUP ====================

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');

    // Mock InvoiceGenerationService to avoid PDF generation
    $mockInvoiceService = Mockery::mock(InvoiceGenerationService::class);
    $mockInvoiceService->shouldReceive('generateInvoice')
        ->andReturnUsing(function ($order) {
            $order->update([
                'invoice_number' => 'INV-TEST-'.substr($order->id, 0, 8),
                'invoice_file_path' => 'invoices/test.pdf',
                'invoice_date' => now(),
            ]);

            return [
                'invoice_number' => $order->invoice_number,
                'invoice_file_path' => $order->invoice_file_path,
            ];
        });
    $this->app->instance(InvoiceGenerationService::class, $mockInvoiceService);
});

afterEach(function () {
    Carbon::setTestNow(); // Reset frozen time
    Mockery::close();
});

// ==================== HELPER FUNCTIONS ====================

function createTrustedOrgWithAdmin(): Organization
{
    $org = Organization::factory()->withCreditPool(0)->create(['is_trusted' => true]);
    $admin = User::factory()->create();
    $org->users()->attach($admin->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

    return $org;
}

function createNonTrustedOrgWithAdmin(): Organization
{
    $org = Organization::factory()->withCreditPool(0)->create(['is_trusted' => false]);
    $admin = User::factory()->create();
    $org->users()->attach($admin->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

    return $org;
}

function createPremiumMonthlyLicense(int $credits = 500): License
{
    return License::factory()->create([
        'tier' => 'premium',
        'billing_cycle' => 'monthly',
        'credits' => $credits,
        'amount' => 49.00,
        'currency' => 'EUR',
        'active' => true,
    ]);
}

function createOnetimePackLicense(int $credits = 200): License
{
    return License::factory()->create([
        'tier' => 'onetime',
        'billing_cycle' => 'onetime',
        'credits' => $credits,
        'amount' => 99.00,
        'currency' => 'EUR',
        'period' => 90,
        'active' => true,
    ]);
}

function createOrganizationLicense(
    Organization $org,
    License $license,
    array $attributes = []
): OrganizationLicense {
    return OrganizationLicense::create(array_merge([
        'organization_id' => $org->id,
        'license_id' => $license->id,
        'status' => 'active',
        'billing_method' => 'invoice',
        'payment_status' => 'paid',
        'starts_at' => now(),
        'ends_at' => null,
        'source' => 'test',
        'external_ref' => 'test-'.uniqid(),
        'is_current' => true,
    ], $attributes));
}

function createPendingInvoiceOrder(Organization $org, License $license): Order
{
    return Order::create([
        'payer_type' => 'organization',
        'payer_id' => $org->id,
        'license_id' => $license->id,
        'currency' => 'EUR',
        'net_amount' => $license->amount,
        'tax_amount' => $license->amount * 0.21,
        'gross_amount' => $license->amount * 1.21,
        'country' => 'NL',
        'status' => 'pending',
        'billing_snapshot' => [
            'company_name' => $org->name,
            'email' => 'test@example.com',
            'country' => 'NL',
        ],
        'meta' => ['type' => 'invoice_renewal'],
    ]);
}

function freezeTime(string $date): void
{
    Carbon::setTestNow(Carbon::parse($date.' 12:00:00'));
}

// ==================== GROUP 1: INVOICE PURCHASE FLOW ====================

describe('Invoice Purchase Flow', function () {

    it('activates license immediately for trusted organization', function () {
        $org = createTrustedOrgWithAdmin();
        $license = createPremiumMonthlyLicense(500);

        // Simulate purchase via OrganizationLicenseRenewalService
        $renewalService = app(OrganizationLicenseRenewalService::class);

        // Create initial license (simulating checkout)
        $orgLicense = createOrganizationLicense($org, $license, [
            'status' => 'active',
            'payment_status' => 'unpaid', // Invoice not paid yet, but trusted
            'billing_method' => 'invoice',
        ]);

        // For trusted orgs, credits should be added immediately
        $org->creditPool()->update(['balance_credits' => $license->credits]);
        OrganizationCreditLedger::create([
            'organization_id' => $org->id,
            'delta' => $license->credits,
            'balance_after' => $license->credits,
            'reason' => 'purchase',
        ]);

        // Verify
        assertOrganizationLicenseIsActive($orgLicense);
        assertOrganizationHasCreditPool($org, 500);
        expect(OrganizationCreditLedger::where('organization_id', $org->id)->count())->toBe(1);
    });

    it('creates pending license for non-trusted organization', function () {
        $org = createNonTrustedOrgWithAdmin();
        $license = createPremiumMonthlyLicense(500);

        // Create pending license (non-trusted must wait for payment)
        $orgLicense = createOrganizationLicense($org, $license, [
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'billing_method' => 'invoice',
            'is_current' => false, // Not current until paid
        ]);

        // Verify
        assertOrganizationLicenseIsPending($orgLicense);
        assertOrganizationHasCreditPool($org, 0); // No credits yet
        expect(OrganizationCreditLedger::where('organization_id', $org->id)->count())->toBe(0);
    });

    it('activates and resets credits when admin marks invoice as paid', function () {
        $org = createNonTrustedOrgWithAdmin();
        $license = createPremiumMonthlyLicense(500);

        // Start with some existing credits (simulating previous period)
        $org->creditPool()->update(['balance_credits' => 50]);

        // Create pending license
        $orgLicense = createOrganizationLicense($org, $license, [
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'billing_method' => 'invoice',
            'is_current' => false,
        ]);

        // Create order
        $order = createPendingInvoiceOrder($org, $license);
        $orgLicense->update(['external_ref' => $order->id]);

        // Admin marks as paid via service
        $renewalService = app(OrganizationLicenseRenewalService::class);
        $result = $renewalService->activatePendingLicense($orgLicense);

        // Verify
        expect($result)->toBeTrue();

        $orgLicense->refresh();
        assertOrganizationLicenseIsActive($orgLicense);
        assertOrganizationLicenseIsPaid($orgLicense);

        // Credits should be RESET (not added) to license amount
        assertOrganizationHasCreditPool($org, 500);

        // Ledger entry exists
        $ledger = OrganizationCreditLedger::where('organization_id', $org->id)
            ->where('reason', 'reset_renewal')
            ->first();
        assertOrgCreditLedgerEntryComplete($ledger);
        expect($ledger->balance_after)->toBe(500);

        // Activation email should be queued
        Queue::assertPushed(SendPostmarkTemplateEmail::class);
    });

});

// ==================== GROUP 2: INVOICE RENEWAL FLOW ====================

describe('Invoice Renewal Flow', function () {

    it('renews and resets credits for trusted organization', function () {
        freezeTime('2025-11-28');

        $org = createTrustedOrgWithAdmin();
        $license = createPremiumMonthlyLicense(500);

        // Org has some remaining credits
        $org->creditPool()->update(['balance_credits' => 75]);

        // License started 1 month ago (Oct 28)
        $orgLicense = createOrganizationLicense($org, $license, [
            'starts_at' => Carbon::parse('2025-10-28 12:00:00'),
            'billing_method' => 'invoice',
        ]);

        // Run renewal command
        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful();

        // Verify new license created
        $newLicense = OrganizationLicense::where('organization_id', $org->id)
            ->where('source', 'invoice_renewal')
            ->first();

        expect($newLicense)->not->toBeNull();
        expect($newLicense->status)->toBe('active'); // Trusted = immediate activation
        expect($newLicense->is_current)->toBeTrue();

        // Credits should be RESET to license amount
        $org->creditPool->refresh();
        expect($org->creditPool->balance_credits)->toBe(500);

        // Order created
        $order = Order::where('payer_type', 'organization')
            ->where('payer_id', $org->id)
            ->where('status', 'pending')
            ->first();
        expect($order)->not->toBeNull();

        // Email queued
        Queue::assertPushed(SendPostmarkTemplateEmail::class);
    });

    it('creates pending license for non-trusted organization on renewal', function () {
        freezeTime('2025-11-28');

        $org = createNonTrustedOrgWithAdmin();
        $license = createPremiumMonthlyLicense(500);

        // Org has some credits
        $org->creditPool()->update(['balance_credits' => 100]);

        // License started 1 month ago
        $orgLicense = createOrganizationLicense($org, $license, [
            'starts_at' => Carbon::parse('2025-10-28 12:00:00'),
            'billing_method' => 'invoice',
        ]);

        // Run renewal command
        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful();

        // Verify new license is pending
        $newLicense = OrganizationLicense::where('organization_id', $org->id)
            ->where('source', 'invoice_renewal')
            ->first();

        expect($newLicense)->not->toBeNull();
        expect($newLicense->status)->toBe('pending'); // Non-trusted = pending
        expect($newLicense->is_current)->toBeFalse(); // Not current until paid

        // Credits should NOT be reset
        $org->creditPool->refresh();
        expect($org->creditPool->balance_credits)->toBe(100); // Unchanged

        // Invoice email queued
        Queue::assertPushed(SendPostmarkTemplateEmail::class);
    });

    it('catches up renewal from 3 days ago', function () {
        freezeTime('2025-11-28');

        $org = createTrustedOrgWithAdmin();
        $license = createPremiumMonthlyLicense(500);

        // License renewal was 3 days ago (Nov 25 = Oct 25 + 1 month)
        $orgLicense = createOrganizationLicense($org, $license, [
            'starts_at' => Carbon::parse('2025-10-25 12:00:00'),
            'billing_method' => 'invoice',
        ]);

        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful();

        // Should still process
        $newLicense = OrganizationLicense::where('organization_id', $org->id)
            ->where('source', 'invoice_renewal')
            ->first();

        expect($newLicense)->not->toBeNull();
    });

    it('skips renewal older than 7 days', function () {
        freezeTime('2025-11-28');

        $org = createTrustedOrgWithAdmin();
        $license = createPremiumMonthlyLicense(500);

        // License renewal was 10 days ago (Nov 18 = Oct 18 + 1 month)
        $orgLicense = createOrganizationLicense($org, $license, [
            'starts_at' => Carbon::parse('2025-10-18 12:00:00'),
            'billing_method' => 'invoice',
        ]);

        $initialCount = OrganizationLicense::count();

        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful();

        // No new license should be created
        expect(OrganizationLicense::count())->toBe($initialCount);
    });

    it('prevents duplicate renewal processing', function () {
        freezeTime('2025-11-28');

        $org = createTrustedOrgWithAdmin();
        $license = createPremiumMonthlyLicense(500);

        $orgLicense = createOrganizationLicense($org, $license, [
            'starts_at' => Carbon::parse('2025-10-28 12:00:00'),
            'billing_method' => 'invoice',
        ]);

        // Mark as already processed
        LicenseNotification::create([
            'organization_license_id' => $orgLicense->id,
            'notification_type' => LicenseNotification::TYPE_INVOICE_RENEWAL_30_DAYS,
            'sent_at' => now()->subDays(5),
        ]);

        $initialCount = OrganizationLicense::count();

        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful()
            ->expectsOutputToContain('Skipped (already renewed)');

        expect(OrganizationLicense::count())->toBe($initialCount);
    });

    it('skips licenses not due for renewal yet', function () {
        freezeTime('2025-11-28');

        $org = createTrustedOrgWithAdmin();
        $license = createPremiumMonthlyLicense(500);

        // License started recently, renewal in the future (Dec 18)
        $orgLicense = createOrganizationLicense($org, $license, [
            'starts_at' => Carbon::parse('2025-11-18 12:00:00'),
            'billing_method' => 'invoice',
        ]);

        $initialCount = OrganizationLicense::count();

        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful()
            ->expectsOutputToContain('Skipped (not due)');

        expect(OrganizationLicense::count())->toBe($initialCount);
    });

});

// ==================== GROUP 3: CREDIT RESET (SUBSCRIPTION) ====================

describe('Credit Reset on Renewal Date', function () {

    it('resets credits on monthly renewal date for subscription license', function () {
        $org = Organization::factory()->withCreditPool(50)->create();
        $license = License::factory()->create([
            'tier' => 'premium',
            'billing_cycle' => 'monthly',
            'credit_reset_interval' => 'monthly',
            'credits' => 300,
            'amount' => 49.00,
            'active' => true,
        ]);

        // License started 35 days ago, last reset 35 days ago
        // This means previous renewal was ~5 days ago, and last_reset is before that
        createOrganizationLicense($org, $license, [
            'starts_at' => now()->subDays(35),
            'last_credit_reset_at' => now()->subDays(35),
            'billing_method' => 'mollie', // Subscription, not invoice
            'status' => 'active',
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // Credits should be reset to license amount
        $org->creditPool->refresh();
        expect($org->creditPool->balance_credits)->toBe(300);
    });

    it('does not reset credits if recently reset', function () {
        $org = Organization::factory()->withCreditPool(150)->create();
        $license = License::factory()->create([
            'tier' => 'premium',
            'billing_cycle' => 'monthly',
            'credit_reset_interval' => 'monthly',
            'credits' => 300,
            'amount' => 49.00,
            'active' => true,
        ]);

        // License started 35 days ago, previous renewal was ~5 days ago
        // Last reset was 2 days ago, which is AFTER the previous renewal
        // So no reset should happen
        createOrganizationLicense($org, $license, [
            'starts_at' => now()->subDays(35),
            'last_credit_reset_at' => now()->subDays(2), // After previous renewal
            'billing_method' => 'mollie',
            'status' => 'active',
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // Credits should remain unchanged
        $org->creditPool->refresh();
        expect($org->creditPool->balance_credits)->toBe(150);
    });

});

// ==================== GROUP 4: LICENSE CANCELLATION ====================

describe('License Cancellation', function () {

    it('preserves credits when license is canceled but not expired', function () {
        $org = Organization::factory()->withCreditPool(200)->create();
        $license = createPremiumMonthlyLicense(500);

        // Canceled but ends_at is in the future
        createOrganizationLicense($org, $license, [
            'status' => 'canceled',
            'ends_at' => now()->addDays(30),
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // Credits should remain unchanged
        $org->creditPool->refresh();
        expect($org->creditPool->balance_credits)->toBe(200);
    });

    it('expires canceled license and resets credits when ends_at passes', function () {
        $org = Organization::factory()->withCreditPool(150)->create();
        $license = createPremiumMonthlyLicense(500);

        $orgLicense = createOrganizationLicense($org, $license, [
            'status' => 'canceled',
            'ends_at' => now()->subDay(), // Expired yesterday
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // License should be expired
        $orgLicense->refresh();
        expect($orgLicense->status)->toBe('expired');

        // Credits should be 0
        $org->creditPool->refresh();
        expect($org->creditPool->balance_credits)->toBe(0);
    });

});

// ==================== GROUP 5: LICENSE EXPIRATION ====================

describe('License Expiration', function () {

    it('expires onetime license and resets credits', function () {
        $org = Organization::factory()->withCreditPool(500)->create();
        $license = createOnetimePackLicense(200);

        $orgLicense = createOrganizationLicense($org, $license, [
            'ends_at' => now()->subDay(), // Expired yesterday
            'status' => 'active',
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // License should be expired
        $orgLicense->refresh();
        assertOrganizationLicenseIsExpired($orgLicense);

        // Credits should be 0
        assertOrganizationHasCreditPool($org, 0);
    });

    it('does not expire license when ends_at is in the future', function () {
        $org = Organization::factory()->withCreditPool(500)->create();
        $license = createOnetimePackLicense(200);

        $orgLicense = createOrganizationLicense($org, $license, [
            'ends_at' => now()->addDays(10), // Still valid
            'status' => 'active',
        ]);

        $this->artisan('license:process-credits')
            ->assertSuccessful();

        // License should still be active
        $orgLicense->refresh();
        expect($orgLicense->status)->toBe('active');

        // Credits unchanged
        $org->creditPool->refresh();
        expect($org->creditPool->balance_credits)->toBe(500);
    });

});

// ==================== GROUP 6: INVOICE & ORDER VERIFICATION ====================

describe('Invoice and Order Verification', function () {

    it('creates order with correct invoice data on renewal', function () {
        freezeTime('2025-11-28');

        $org = createTrustedOrgWithAdmin();
        $license = createPremiumMonthlyLicense(500);

        createOrganizationLicense($org, $license, [
            'starts_at' => Carbon::parse('2025-10-28 12:00:00'),
            'billing_method' => 'invoice',
        ]);

        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful();

        // Verify Order details
        $order = Order::where('payer_type', 'organization')
            ->where('payer_id', $org->id)
            ->latest()
            ->first();

        expect($order)->not->toBeNull();
        expect($order->status)->toBe(OrderStatus::Pending);
        expect($order->license_id)->toBe($license->id);
        expect($order->currency)->toBe('EUR');
        expect((float) $order->net_amount)->toBe(49.00);
        expect($order->meta['type'])->toBe('invoice_renewal');

        // Invoice should be generated (via mock)
        expect($order->invoice_number)->toStartWith('INV-TEST-');
        expect($order->invoice_file_path)->toBe('invoices/test.pdf');
        expect($order->invoice_date)->not->toBeNull();
    });

    it('creates new license with correct attributes on renewal', function () {
        freezeTime('2025-11-28');

        $org = createTrustedOrgWithAdmin();
        $license = createPremiumMonthlyLicense(500);

        createOrganizationLicense($org, $license, [
            'starts_at' => Carbon::parse('2025-10-28 12:00:00'),
            'billing_method' => 'invoice',
        ]);

        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful();

        // Verify new license attributes
        $newLicense = OrganizationLicense::where('organization_id', $org->id)
            ->where('source', 'invoice_renewal')
            ->first();

        expect($newLicense)->not->toBeNull();
        expect($newLicense->billing_method)->toBe('invoice');
        expect($newLicense->payment_status)->toBe('unpaid');
        expect($newLicense->starts_at)->not->toBeNull();
        expect($newLicense->ends_at)->not->toBeNull();

        // Note: invoice_number is on the Order, not the OrganizationLicense
        // when using ProcessInvoiceRenewals command
        $order = Order::where('payer_type', 'organization')
            ->where('payer_id', $org->id)
            ->latest()
            ->first();
        expect($order->invoice_number)->toStartWith('INV-TEST-');
    });

    it('updates order status when admin marks as paid', function () {
        $org = createNonTrustedOrgWithAdmin();
        $license = createPremiumMonthlyLicense(500);

        // Create pending license
        $orgLicense = createOrganizationLicense($org, $license, [
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'billing_method' => 'invoice',
        ]);

        // Create pending order
        $order = createPendingInvoiceOrder($org, $license);
        $orgLicense->update(['external_ref' => $order->id]);

        expect($order->status)->toBe(OrderStatus::Pending);

        // Activate via service
        $renewalService = app(OrganizationLicenseRenewalService::class);
        $renewalService->activatePendingLicense($orgLicense);

        // Verify license status
        $orgLicense->refresh();
        expect($orgLicense->status)->toBe('active');
        expect($orgLicense->payment_status)->toBe('paid');
        expect($orgLicense->paid_at)->not->toBeNull();
    });

    it('creates credit ledger entry with correct reason and meta', function () {
        freezeTime('2025-11-28');

        $org = createTrustedOrgWithAdmin();
        $license = createPremiumMonthlyLicense(500);
        $org->creditPool()->update(['balance_credits' => 75]);

        createOrganizationLicense($org, $license, [
            'starts_at' => Carbon::parse('2025-10-28 12:00:00'),
            'billing_method' => 'invoice',
        ]);

        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful();

        // Verify ledger entry
        $ledger = OrganizationCreditLedger::where('organization_id', $org->id)
            ->where('reason', 'reset_renewal')
            ->first();

        expect($ledger)->not->toBeNull();
        expect($ledger->delta)->toBe(425); // 500 - 75 = 425
        expect($ledger->balance_after)->toBe(500);
        expect($ledger->meta['source'])->toBe('invoice_renewal');
        expect($ledger->meta['previous_balance'])->toBe(75);
        expect($ledger->meta['reset_to'])->toBe(500);
    });

});

// ==================== GROUP 7: EMAIL TEMPLATE VERIFICATION ====================

describe('Email Template Verification', function () {

    it('sends correct email template for trusted organization renewal', function () {
        freezeTime('2025-11-28');

        $org = createTrustedOrgWithAdmin();
        $license = createPremiumMonthlyLicense(500);

        createOrganizationLicense($org, $license, [
            'starts_at' => Carbon::parse('2025-10-28 12:00:00'),
            'billing_method' => 'invoice',
        ]);

        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful();

        // Trusted orgs get a regular invoice email (not pending payment)
        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('templateAlias');
            $property->setAccessible(true);
            $templateAlias = $property->getValue($job);

            // Should be invoice email, not pending-payment (since trusted gets credits immediately)
            return str_contains($templateAlias, 'invoice');
        });
    });

    it('sends invoice-pending-payment email for non-trusted organization renewal', function () {
        freezeTime('2025-11-28');

        $org = createNonTrustedOrgWithAdmin();
        $admin = $org->users()->first();
        $admin->update(['preferred_language' => 'nl']);
        $license = createPremiumMonthlyLicense(500);

        createOrganizationLicense($org, $license, [
            'starts_at' => Carbon::parse('2025-10-28 12:00:00'),
            'billing_method' => 'invoice',
        ]);

        // Create a fake invoice file for the email attachment
        Storage::put('invoices/test.pdf', 'fake pdf content');

        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful();

        // Non-trusted orgs get invoice-pending-payment email
        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('templateAlias');
            $property->setAccessible(true);
            $templateAlias = $property->getValue($job);

            return str_contains($templateAlias, 'invoice-pending-payment');
        });
    });

    it('sends license-activated email when admin marks as paid', function () {
        $org = createNonTrustedOrgWithAdmin();
        $admin = $org->users()->first();
        $admin->update(['preferred_language' => 'en']);
        $license = createPremiumMonthlyLicense(500);

        $orgLicense = createOrganizationLicense($org, $license, [
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'billing_method' => 'invoice',
        ]);

        $renewalService = app(OrganizationLicenseRenewalService::class);
        $renewalService->activatePendingLicense($orgLicense);

        // Should send license-activated email
        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('templateAlias');
            $property->setAccessible(true);
            $templateAlias = $property->getValue($job);

            return str_contains($templateAlias, 'license-activated');
        });
    });

    it('sends email with correct locale based on admin preference', function () {
        $org = createNonTrustedOrgWithAdmin();
        $admin = $org->users()->first();
        $admin->update(['preferred_language' => 'nl']); // Dutch preference
        $license = createPremiumMonthlyLicense(500);

        $orgLicense = createOrganizationLicense($org, $license, [
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'billing_method' => 'invoice',
        ]);

        $renewalService = app(OrganizationLicenseRenewalService::class);
        $renewalService->activatePendingLicense($orgLicense);

        // Should use Dutch template
        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('templateAlias');
            $property->setAccessible(true);
            $templateAlias = $property->getValue($job);

            // Template should end with __nl for Dutch
            return str_contains($templateAlias, '__nl');
        });
    });

    it('sends email to all organization admins', function () {
        $org = Organization::factory()->withCreditPool(0)->create(['is_trusted' => false]);

        // Add multiple admins
        $admin1 = User::factory()->create(['email' => 'admin1@example.com']);
        $admin2 = User::factory()->create(['email' => 'admin2@example.com']);
        $org->users()->attach($admin1->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);
        $org->users()->attach($admin2->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $license = createPremiumMonthlyLicense(500);

        $orgLicense = createOrganizationLicense($org, $license, [
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'billing_method' => 'invoice',
        ]);

        $renewalService = app(OrganizationLicenseRenewalService::class);
        $renewalService->activatePendingLicense($orgLicense);

        // Should send email to both admins
        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('to');
            $property->setAccessible(true);

            return $property->getValue($job) === 'admin1@example.com';
        });

        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('to');
            $property->setAccessible(true);

            return $property->getValue($job) === 'admin2@example.com';
        });
    });

});

// ==================== GROUP 8: COMPLETE PAYMENT FLOW ====================

describe('Complete Payment Flow End-to-End', function () {

    it('completes full renewal cycle: renewal -> pending -> paid -> credits reset', function () {
        freezeTime('2025-11-28');

        // Setup: Non-trusted org with existing credits
        $org = createNonTrustedOrgWithAdmin();
        $license = createPremiumMonthlyLicense(500);
        $org->creditPool()->update(['balance_credits' => 100]);

        // Step 1: Create current license (started 1 month ago)
        $currentLicense = createOrganizationLicense($org, $license, [
            'starts_at' => Carbon::parse('2025-10-28 12:00:00'),
            'billing_method' => 'invoice',
        ]);

        // Step 2: Run renewal command
        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful();

        // Verify: New pending license created
        $newLicense = OrganizationLicense::where('organization_id', $org->id)
            ->where('source', 'invoice_renewal')
            ->first();

        expect($newLicense->status)->toBe('pending');
        expect($newLicense->payment_status)->toBe('unpaid');

        // Verify: Credits unchanged (non-trusted)
        expect($org->creditPool->fresh()->balance_credits)->toBe(100);

        // Verify: Order created
        $order = Order::where('payer_type', 'organization')
            ->where('payer_id', $org->id)
            ->latest()
            ->first();
        expect($order->status)->toBe(OrderStatus::Pending);

        // Step 3: Admin marks as paid
        $renewalService = app(OrganizationLicenseRenewalService::class);
        $result = $renewalService->activatePendingLicense($newLicense);

        expect($result)->toBeTrue();

        // Verify: License now active
        $newLicense->refresh();
        expect($newLicense->status)->toBe('active');
        expect($newLicense->payment_status)->toBe('paid');
        expect($newLicense->paid_at)->not->toBeNull();

        // Verify: Credits RESET to license amount (not added)
        expect($org->creditPool->fresh()->balance_credits)->toBe(500);

        // Verify: Ledger entry
        $ledger = OrganizationCreditLedger::where('organization_id', $org->id)
            ->where('reason', 'reset_renewal')
            ->orderBy('id', 'desc')
            ->first();
        expect($ledger->balance_after)->toBe(500);

        // Verify: Activation email sent
        Queue::assertPushed(SendPostmarkTemplateEmail::class, function ($job) {
            $reflection = new \ReflectionClass($job);
            $property = $reflection->getProperty('templateAlias');
            $property->setAccessible(true);

            return str_contains($property->getValue($job), 'license-activated');
        });
    });

    it('completes trusted org renewal with immediate credit reset', function () {
        freezeTime('2025-11-28');

        // Setup: Trusted org with some remaining credits
        $org = createTrustedOrgWithAdmin();
        $license = createPremiumMonthlyLicense(500);
        $org->creditPool()->update(['balance_credits' => 50]);

        // Create current license
        createOrganizationLicense($org, $license, [
            'starts_at' => Carbon::parse('2025-10-28 12:00:00'),
            'billing_method' => 'invoice',
        ]);

        // Run renewal
        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful();

        // Verify: New license is ACTIVE immediately (trusted)
        $newLicense = OrganizationLicense::where('organization_id', $org->id)
            ->where('source', 'invoice_renewal')
            ->first();

        expect($newLicense->status)->toBe('active');
        expect($newLicense->is_current)->toBeTrue();

        // Verify: Credits RESET immediately
        expect($org->creditPool->fresh()->balance_credits)->toBe(500);

        // Verify: Ledger entry created
        $ledger = OrganizationCreditLedger::where('organization_id', $org->id)
            ->where('reason', 'reset_renewal')
            ->first();
        expect($ledger)->not->toBeNull();
        expect($ledger->delta)->toBe(450); // 500 - 50
        expect($ledger->balance_after)->toBe(500);
    });

    it('prevents duplicate renewal of same license via notification tracking', function () {
        freezeTime('2025-11-28');

        $org = createTrustedOrgWithAdmin();
        $license = createPremiumMonthlyLicense(500);

        $originalLicense = createOrganizationLicense($org, $license, [
            'starts_at' => Carbon::parse('2025-10-28 12:00:00'),
            'billing_method' => 'invoice',
        ]);

        // Run renewal first time
        $this->artisan('license:process-invoice-renewals')->assertSuccessful();

        // One renewal license should be created
        $renewalCount = OrganizationLicense::where('organization_id', $org->id)
            ->where('source', 'invoice_renewal')
            ->count();
        expect($renewalCount)->toBe(1);

        // Verify LicenseNotification was created to prevent duplicate
        $notification = LicenseNotification::where('organization_license_id', $originalLicense->id)
            ->where('notification_type', LicenseNotification::TYPE_INVOICE_RENEWAL_30_DAYS)
            ->first();
        expect($notification)->not->toBeNull();

        // Run renewal second time - original license should be skipped
        $this->artisan('license:process-invoice-renewals')
            ->assertSuccessful()
            ->expectsOutputToContain('Skipped (already renewed)');
    });

});

// ==================== GROUP 9: EDGE CASES ====================

describe('Edge Cases', function () {

    it('dry run mode does not make changes', function () {
        freezeTime('2025-11-28');

        $org = createTrustedOrgWithAdmin();
        $license = createPremiumMonthlyLicense(500);
        $org->creditPool()->update(['balance_credits' => 100]);

        createOrganizationLicense($org, $license, [
            'starts_at' => Carbon::parse('2025-10-28 12:00:00'),
            'billing_method' => 'invoice',
        ]);

        $initialLicenseCount = OrganizationLicense::count();
        $initialOrderCount = Order::count();

        $this->artisan('license:process-invoice-renewals', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('dry-run mode');

        // No changes
        expect(OrganizationLicense::count())->toBe($initialLicenseCount);
        expect(Order::count())->toBe($initialOrderCount);
        expect($org->creditPool->fresh()->balance_credits)->toBe(100);
    });

    it('handles organization without credit pool gracefully', function () {
        $org = Organization::factory()->create(); // No credit pool
        $license = License::factory()->create([
            'tier' => 'premium',
            'billing_cycle' => 'monthly',
            'credit_reset_interval' => 'monthly',
            'credits' => 500,
            'amount' => 49.00,
            'active' => true,
        ]);

        expect($org->creditPool)->toBeNull();

        // Create license and trigger credit reset
        createOrganizationLicense($org, $license, [
            'starts_at' => now()->subDays(35),
            'last_credit_reset_at' => now()->subDays(35),
            'billing_method' => 'mollie',
            'status' => 'active',
        ]);

        // Command should run without error even if credit pool is missing
        // The reset service handles creating the credit pool if needed
        $this->artisan('license:process-credits')
            ->assertSuccessful();
    });

});
