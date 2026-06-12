<?php

use App\Models\License;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationCreditLedger;
use App\Models\OrganizationCreditPool;
use App\Models\OrganizationLicense;
use App\Services\OrganizationLicenseRenewalService;
use App\Services\PaymentFulfillmentService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
});

describe('Organization License Invoice Management', function () {
    it('can activate a pending invoice license', function () {
        $organization = createOrganization(['name' => 'Test Corp']);
        $license = createLicense(['tier' => 'onetime', 'credits' => 100]);

        // Create organization license in pending state
        $orgLicense = OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'pending',
            'billing_method' => 'invoice',
            'payment_status' => 'unpaid',
            'invoice_number' => 'INV-TEST-001',
            'invoice_due_date' => now()->addDays(14),
            'source' => 'checkout',
        ]);

        expect($orgLicense->status)->toBe('pending');

        // Activate the license
        $orgLicense->activate();

        expect($orgLicense->status)->toBe('active');
        // Payment status should still be unpaid - activation doesn't mean payment
        expect($orgLicense->payment_status)->toBe('unpaid');
    });

    it('marks license as paid correctly', function () {
        $organization = createOrganization(['name' => 'Test Corp']);
        $license = createLicense(['tier' => 'onetime', 'credits' => 100]);

        $orgLicense = OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'active',
            'billing_method' => 'invoice',
            'payment_status' => 'unpaid',
            'invoice_number' => 'INV-TEST-002',
            'source' => 'checkout',
        ]);

        expect($orgLicense->payment_status)->toBe('unpaid');
        expect($orgLicense->paid_at)->toBeNull();

        // Mark as paid
        $orgLicense->markAsPaid();

        expect($orgLicense->payment_status)->toBe('paid');
        expect($orgLicense->paid_at)->not->toBeNull();
    });

    it('adds credits when non-trusted organization invoice is marked as paid', function () {
        $organization = createOrganization([
            'name' => 'Non-Trusted Corp',
            'is_trusted' => false,
        ]);

        // Create credit pool
        $organization->creditPool()->create(['balance_credits' => 0]);

        $license = createLicense(['tier' => 'onetime', 'credits' => 200]);

        // Create order first (like checkout flow does)
        $order = Order::create([
            'payer_type' => 'organization',
            'payer_id' => $organization->id,
            'license_id' => $license->id,
            'type' => 'onetime',
            'currency' => 'EUR',
            'net_amount' => 100,
            'tax_amount' => 21,
            'gross_amount' => 121,
            'country' => 'NL',
            'status' => 'invoice_requested',
            'meta' => [
                'credits_amount' => 200,
                'payment_provider' => 'invoice',
            ],
        ]);

        // Create organization license linked to order
        $orgLicense = OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'pending',
            'billing_method' => 'invoice',
            'payment_status' => 'unpaid',
            'invoice_number' => 'INV-TEST-003',
            'invoice_due_date' => now()->addDays(14),
            'source' => 'checkout',
            'external_ref' => $order->id,
        ]);

        // Update order meta with license reference
        $order->update([
            'meta' => array_merge($order->meta ?? [], [
                'invoice_license_id' => $orgLicense->id,
            ]),
        ]);

        // Simulate admin marking as paid: mark license, activate, fulfill order
        $orgLicense->markAsPaid();
        $orgLicense->activate();

        $order->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        // Fulfill the order (add credits)
        $fulfillmentService = app(PaymentFulfillmentService::class);
        $fulfillmentService->fulfillOrder($order);

        // Verify credits were added
        $organization->refresh();
        expect($organization->creditPool->balance_credits)->toBe(200);

        // Verify ledger entry
        $ledgerEntry = OrganizationCreditLedger::where('organization_id', $organization->id)
            ->where('delta', 200)
            ->first();
        expect($ledgerEntry)->not->toBeNull();
        expect($ledgerEntry->reason)->toBe('purchase');
    });

    it('does not double-add credits when trusted organization invoice is marked as paid', function () {
        $organization = createOrganization([
            'name' => 'Trusted Corp',
            'is_trusted' => true,
        ]);

        // Create credit pool with credits already added (from checkout)
        $organization->creditPool()->create(['balance_credits' => 150]);

        $license = createLicense(['tier' => 'onetime', 'credits' => 150]);

        // Create order (already has credits via trusted flow)
        $order = Order::create([
            'payer_type' => 'organization',
            'payer_id' => $organization->id,
            'license_id' => $license->id,
            'type' => 'onetime',
            'currency' => 'EUR',
            'net_amount' => 100,
            'tax_amount' => 21,
            'gross_amount' => 121,
            'country' => 'NL',
            'status' => 'invoice_requested',
            'meta' => [
                'credits_amount' => 150,
                'payment_provider' => 'invoice',
                'trusted_organization' => true,
                'auto_approved' => true,
            ],
        ]);

        // Create ledger entry (already added during checkout for trusted)
        // order_id is stored in meta JSON field, not as a direct column
        OrganizationCreditLedger::create([
            'organization_id' => $organization->id,
            'delta' => 150,
            'balance_after' => 150,
            'reason' => 'purchase',
            'meta' => ['order_id' => $order->id],
        ]);

        // Create organization license (already active for trusted)
        $orgLicense = OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'active', // Already active for trusted
            'billing_method' => 'invoice',
            'payment_status' => 'unpaid', // But not yet paid
            'invoice_number' => 'INV-TEST-004',
            'invoice_due_date' => now()->addDays(14),
            'source' => 'checkout',
            'external_ref' => $order->id,
        ]);

        $order->update([
            'meta' => array_merge($order->meta ?? [], [
                'invoice_license_id' => $orgLicense->id,
            ]),
        ]);

        // Simulate admin marking as paid
        $orgLicense->markAsPaid();

        $order->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        // Try to fulfill - service should detect already fulfilled
        $fulfillmentService = app(PaymentFulfillmentService::class);
        $fulfillmentService->fulfillOrder($order);

        // CRITICAL: Verify credits were NOT double-added
        $organization->refresh();
        expect($organization->creditPool->balance_credits)->toBe(150); // Still 150, not 300

        // Verify only ONE ledger entry exists
        $ledgerCount = OrganizationCreditLedger::where('organization_id', $organization->id)->count();
        expect($ledgerCount)->toBe(1);
    });

    it('tracks payment status separately from license status', function () {
        $organization = createOrganization([
            'name' => 'Status Test Corp',
            'is_trusted' => true,
        ]);

        $license = createLicense(['tier' => 'onetime', 'credits' => 100]);

        // Create active license with unpaid status (trusted org scenario)
        $orgLicense = OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'active', // License is active
            'billing_method' => 'invoice',
            'payment_status' => 'unpaid', // But not paid
            'invoice_number' => 'INV-TEST-005',
            'source' => 'checkout',
        ]);

        // License can be active but unpaid
        expect($orgLicense->status)->toBe('active');
        expect($orgLicense->payment_status)->toBe('unpaid');
        expect($orgLicense->isActive())->toBeTrue();
        expect($orgLicense->isPaid())->toBeFalse();

        // Mark as paid
        $orgLicense->markAsPaid();

        expect($orgLicense->status)->toBe('active');
        expect($orgLicense->payment_status)->toBe('paid');
        expect($orgLicense->isActive())->toBeTrue();
        expect($orgLicense->isPaid())->toBeTrue();
    });
});

describe('Invoice License Scope', function () {
    it('filters only invoice licenses', function () {
        $organization = createOrganization(['name' => 'Test Corp']);
        $license = createLicense(['tier' => 'onetime', 'credits' => 100]);

        // Create invoice license
        $invoiceLicense = OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'pending',
            'billing_method' => 'invoice',
            'invoice_number' => 'INV-SCOPE-001',
            'source' => 'checkout',
        ]);

        // Create Mollie license
        $mollieLicense = OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'active',
            'billing_method' => 'mollie',
            'source' => 'checkout',
        ]);

        // Query only invoice licenses
        $invoiceLicenses = OrganizationLicense::where('billing_method', 'invoice')->get();

        expect($invoiceLicenses)->toHaveCount(1);
        expect($invoiceLicenses->first()->id)->toBe($invoiceLicense->id);
    });

    it('distinguishes trusted and non-trusted invoices via order meta', function () {
        $trustedOrg = createOrganization([
            'name' => 'Trusted Corp',
            'is_trusted' => true,
        ]);
        $regularOrg = createOrganization([
            'name' => 'Regular Corp',
            'is_trusted' => false,
        ]);
        $license = createLicense(['tier' => 'onetime', 'credits' => 100]);

        // Create order for trusted org
        $trustedOrder = Order::create([
            'payer_type' => 'organization',
            'payer_id' => $trustedOrg->id,
            'license_id' => $license->id,
            'type' => 'onetime',
            'currency' => 'EUR',
            'net_amount' => 100,
            'tax_amount' => 21,
            'gross_amount' => 121,
            'country' => 'NL',
            'status' => 'invoice_requested',
            'meta' => [
                'payment_provider' => 'invoice',
                'trusted_organization' => true,
                'auto_approved' => true,
            ],
        ]);

        // Create order for regular org
        $regularOrder = Order::create([
            'payer_type' => 'organization',
            'payer_id' => $regularOrg->id,
            'license_id' => $license->id,
            'type' => 'onetime',
            'currency' => 'EUR',
            'net_amount' => 100,
            'tax_amount' => 21,
            'gross_amount' => 121,
            'country' => 'NL',
            'status' => 'invoice_requested',
            'meta' => [
                'payment_provider' => 'invoice',
            ],
        ]);

        // Both have same status but different meta
        expect($trustedOrder->status->value)->toBe('invoice_requested');
        expect($regularOrder->status->value)->toBe('invoice_requested');
        expect($trustedOrder->meta['trusted_organization'] ?? false)->toBeTrue();
        expect($regularOrder->meta['trusted_organization'] ?? false)->toBeFalse();
    });
});

describe('Credit Reset vs Add Behavior', function () {
    it('activatePendingLicense resets credits instead of adding', function () {
        $organization = createOrganization([
            'name' => 'Reset Test Corp',
            'is_trusted' => false,
        ]);

        // Create credit pool with existing credits (from previous period)
        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 50, // Had 50 credits left
        ]);

        $license = createLicense(['tier' => 'premium', 'credits' => 200]);

        // Create pending license (non-trusted waiting for payment)
        $orgLicense = OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'pending',
            'billing_method' => 'invoice',
            'payment_status' => 'unpaid',
            'invoice_number' => 'INV-RESET-001',
            'source' => 'renewal',
        ]);

        // Use activatePendingLicense (should RESET, not ADD)
        $renewalService = app(OrganizationLicenseRenewalService::class);
        $renewalService->activatePendingLicense($orgLicense);

        // Verify credits were RESET to 200 (not 50 + 200 = 250)
        $organization->refresh();
        expect($organization->creditPool->balance_credits)->toBe(200);

        // Verify ledger shows correct delta
        $ledgerEntry = OrganizationCreditLedger::where('organization_id', $organization->id)
            ->where('reason', 'reset_renewal')
            ->first();
        expect($ledgerEntry)->not->toBeNull();
        expect($ledgerEntry->delta)->toBe(150); // 200 - 50 = 150
        expect($ledgerEntry->balance_after)->toBe(200);
    });

    it('activatePendingLicense sets last_credit_reset_at to prevent duplicate cronjob reset', function () {
        $organization = createOrganization([
            'name' => 'Cronjob Protection Corp',
            'is_trusted' => false,
        ]);

        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 0,
        ]);

        $license = createLicense(['tier' => 'premium', 'credits' => 200]);

        $orgLicense = OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'pending',
            'billing_method' => 'invoice',
            'payment_status' => 'unpaid',
            'invoice_number' => 'INV-CRONJOB-001',
            'source' => 'renewal',
            'last_credit_reset_at' => null, // Not set yet
        ]);

        expect($orgLicense->last_credit_reset_at)->toBeNull();

        // Activate the license
        $renewalService = app(OrganizationLicenseRenewalService::class);
        $renewalService->activatePendingLicense($orgLicense);

        // Verify last_credit_reset_at is now set
        $orgLicense->refresh();
        expect($orgLicense->last_credit_reset_at)->not->toBeNull();
        expect($orgLicense->last_credit_reset_at->isToday())->toBeTrue();
    });

    it('processRenewal for trusted org sets last_credit_reset_at', function () {
        $organization = createOrganization([
            'name' => 'Trusted Renewal Corp',
            'is_trusted' => true,
        ]);

        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 100,
        ]);

        $license = createLicense([
            'tier' => 'premium',
            'credits' => 200,
            'billing_cycle' => 'monthly',
        ]);

        // Create current active license
        $currentLicense = OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'active',
            'billing_method' => 'invoice',
            'payment_status' => 'paid',
            'source' => 'checkout',
            'is_current' => true,
        ]);

        // Process renewal
        $renewalService = app(OrganizationLicenseRenewalService::class);
        $newLicense = $renewalService->processRenewal($currentLicense);

        // For trusted org, new license should have last_credit_reset_at set
        expect($newLicense->status)->toBe('active');
        expect($newLicense->last_credit_reset_at)->not->toBeNull();
        expect($newLicense->last_credit_reset_at->isToday())->toBeTrue();
    });

    it('processRenewal for non-trusted org does not set last_credit_reset_at', function () {
        $organization = createOrganization([
            'name' => 'Non-Trusted Renewal Corp',
            'is_trusted' => false,
        ]);

        OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 100,
        ]);

        $license = createLicense([
            'tier' => 'premium',
            'credits' => 200,
            'billing_cycle' => 'monthly',
        ]);

        // Create current active license
        $currentLicense = OrganizationLicense::create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'status' => 'active',
            'billing_method' => 'invoice',
            'payment_status' => 'paid',
            'source' => 'checkout',
            'is_current' => true,
        ]);

        // Process renewal
        $renewalService = app(OrganizationLicenseRenewalService::class);
        $newLicense = $renewalService->processRenewal($currentLicense);

        // For non-trusted org, new license should be pending with no last_credit_reset_at
        expect($newLicense->status)->toBe('pending');
        expect($newLicense->last_credit_reset_at)->toBeNull();

        // Credits should NOT have been reset (still 100)
        $organization->refresh();
        expect($organization->creditPool->balance_credits)->toBe(100);
    });
});
