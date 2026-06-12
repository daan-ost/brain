<?php

use App\Enums\OrderStatus;
use App\Livewire\CheckoutWizard;
use App\Models\License;
use App\Models\Order;
use App\Models\Organization;
use App\Services\PaymentFulfillmentService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
});

describe('Trusted Organization Credit Fulfillment', function () {
    it('calls fulfillOrder for trusted organization invoice purchases', function () {
        $user = createUser(['email_verified_at' => now()]);

        $trustedOrg = createOrganization([
            'name' => 'Trusted Corp',
            'is_trusted' => true,
        ]);
        $trustedOrg->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        // Create a license with credits
        $license = createLicense([
            'tier' => 'onetime',
            'credits' => 100,
        ]);

        // Mock the PaymentFulfillmentService
        $mockFulfillmentService = Mockery::mock(PaymentFulfillmentService::class);
        $mockFulfillmentService->shouldReceive('fulfillOrder')
            ->once()
            ->andReturn(true);

        $this->app->instance(PaymentFulfillmentService::class, $mockFulfillmentService);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'tier' => 'onetime',
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        // Fill in required billing data
        $component->set('country', 'NL');
        $component->set('billingData.email', 'test@trustedcorp.com');
        $component->set('billingData.company_name', 'Trusted Corp');
        $component->set('billingData.street', 'Test Street 123');
        $component->set('billingData.postal_code', '1234 AB');
        $component->set('billingData.city', 'Amsterdam');

        // Submit the order
        $component->call('createOrder');

        // Verify no errors occurred
        $component->assertHasNoErrors();
    });

    it('does not call fulfillOrder for non-trusted organization invoice purchases', function () {
        $user = createUser(['email_verified_at' => now()]);

        $regularOrg = createOrganization([
            'name' => 'Regular Corp',
            'is_trusted' => false,
        ]);
        $regularOrg->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $license = createLicense([
            'tier' => 'onetime',
            'credits' => 100,
        ]);

        // Mock the PaymentFulfillmentService - should NOT be called
        $mockFulfillmentService = Mockery::mock(PaymentFulfillmentService::class);
        $mockFulfillmentService->shouldNotReceive('fulfillOrder');

        $this->app->instance(PaymentFulfillmentService::class, $mockFulfillmentService);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'tier' => 'onetime',
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        // Fill in required billing data
        $component->set('country', 'NL');
        $component->set('billingData.email', 'test@regularcorp.com');
        $component->set('billingData.company_name', 'Regular Corp');
        $component->set('billingData.street', 'Test Street 456');
        $component->set('billingData.postal_code', '5678 CD');
        $component->set('billingData.city', 'Rotterdam');

        // Submit the order
        $component->call('createOrder');
    });

    it('does not add credits for non-trusted organization at checkout', function () {
        $user = createUser(['email_verified_at' => now()]);

        $regularOrg = createOrganization([
            'name' => 'No Credits Corp',
            'is_trusted' => false,
        ]);
        $regularOrg->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        // Create credit pool with initial balance of 0
        $regularOrg->creditPool()->create([
            'balance_credits' => 0,
        ]);

        $license = createLicense([
            'tier' => 'onetime',
            'credits' => 500,
        ]);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'tier' => 'onetime',
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        // Fill in required billing data
        $component->set('country', 'NL');
        $component->set('billingData.email', 'test@nocreditscorp.com');
        $component->set('billingData.company_name', 'No Credits Corp');
        $component->set('billingData.street', 'No Credits Street 789');
        $component->set('billingData.postal_code', '7890 EF');
        $component->set('billingData.city', 'Groningen');

        // Submit the order
        $component->call('createOrder');

        // Refresh organization and verify NO credits were added
        $regularOrg->refresh();
        expect($regularOrg->creditPool->balance_credits)->toBe(0);

        // Verify no credit ledger entry was created
        $ledgerEntry = \App\Models\OrganizationCreditLedger::where('organization_id', $regularOrg->id)->first();
        expect($ledgerEntry)->toBeNull();
    });

    it('sets order status to pending for non-trusted organization', function () {
        $user = createUser(['email_verified_at' => now()]);

        $regularOrg = createOrganization([
            'name' => 'Pending Status Corp',
            'is_trusted' => false,
        ]);
        $regularOrg->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $license = createLicense([
            'tier' => 'onetime',
            'credits' => 100,
        ]);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'tier' => 'onetime',
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        // Fill in required billing data
        $component->set('country', 'NL');
        $component->set('billingData.email', 'test@pendingcorp.com');
        $component->set('billingData.company_name', 'Pending Status Corp');
        $component->set('billingData.street', 'Pending Street 101');
        $component->set('billingData.postal_code', '1010 GH');
        $component->set('billingData.city', 'Maastricht');

        // Submit the order
        $component->call('createOrder');

        // Find the order and check status is NOT paid
        $order = Order::where('payer_type', 'organization')
            ->where('payer_id', $regularOrg->id)
            ->latest()
            ->first();

        expect($order)->not->toBeNull();
        expect($order->status)->toBe(OrderStatus::InvoiceRequested);
        expect($order->meta['trusted_organization'] ?? false)->toBeFalse();
    });

    it('adds credits to organization pool for trusted organization', function () {
        $user = createUser(['email_verified_at' => now()]);

        $trustedOrg = createOrganization([
            'name' => 'Credit Test Corp',
            'is_trusted' => true,
        ]);
        $trustedOrg->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        // Create credit pool with initial balance
        $trustedOrg->creditPool()->create([
            'balance_credits' => 0,
        ]);

        $license = createLicense([
            'tier' => 'onetime',
            'credits' => 200,
        ]);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'tier' => 'onetime',
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        // Fill in required billing data
        $component->set('country', 'NL');
        $component->set('billingData.email', 'test@creditcorp.com');
        $component->set('billingData.company_name', 'Credit Test Corp');
        $component->set('billingData.street', 'Credit Street 789');
        $component->set('billingData.postal_code', '9012 EF');
        $component->set('billingData.city', 'Utrecht');

        // Submit the order
        $component->call('createOrder');

        // Refresh organization and check credits
        $trustedOrg->refresh();
        expect($trustedOrg->creditPool->balance_credits)->toBe(200);
    });

    it('creates credit ledger entry for trusted organization purchase', function () {
        $user = createUser(['email_verified_at' => now()]);

        $trustedOrg = createOrganization([
            'name' => 'Ledger Test Corp',
            'is_trusted' => true,
        ]);
        $trustedOrg->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        // Create credit pool
        $trustedOrg->creditPool()->create([
            'balance_credits' => 50,
        ]);

        $license = createLicense([
            'tier' => 'onetime',
            'credits' => 150,
        ]);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'tier' => 'onetime',
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        // Fill in required billing data
        $component->set('country', 'NL');
        $component->set('billingData.email', 'test@ledgercorp.com');
        $component->set('billingData.company_name', 'Ledger Test Corp');
        $component->set('billingData.street', 'Ledger Street 101');
        $component->set('billingData.postal_code', '1011 GH');
        $component->set('billingData.city', 'Den Haag');

        // Submit the order
        $component->call('createOrder');

        // Check credit ledger entry was created
        $ledgerEntry = \App\Models\OrganizationCreditLedger::where('organization_id', $trustedOrg->id)
            ->where('delta', 150)
            ->first();

        expect($ledgerEntry)->not->toBeNull();
        expect($ledgerEntry->reason)->toBe('purchase');
    });

    it('sets order status to invoice_requested for trusted organization (with auto_approved flag)', function () {
        $user = createUser(['email_verified_at' => now()]);

        $trustedOrg = createOrganization([
            'name' => 'Status Test Corp',
            'is_trusted' => true,
        ]);
        $trustedOrg->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $trustedOrg->creditPool()->create([
            'balance_credits' => 0,
        ]);

        $license = createLicense([
            'tier' => 'onetime',
            'credits' => 100,
        ]);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'tier' => 'onetime',
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        // Fill in required billing data
        $component->set('country', 'NL');
        $component->set('billingData.email', 'test@statuscorp.com');
        $component->set('billingData.company_name', 'Status Test Corp');
        $component->set('billingData.street', 'Status Street 202');
        $component->set('billingData.postal_code', '2022 IJ');
        $component->set('billingData.city', 'Eindhoven');

        // Submit the order
        $component->call('createOrder');

        // Find the order and check status
        $order = Order::where('payer_type', 'organization')
            ->where('payer_id', $trustedOrg->id)
            ->latest()
            ->first();

        expect($order)->not->toBeNull();
        // Order status should be 'invoice_requested' - invoice still pending payment
        // The trusted_organization and auto_approved flags in meta differentiate from non-trusted
        expect($order->status)->toBe(OrderStatus::InvoiceRequested);
        expect($order->meta['trusted_organization'])->toBeTrue();
        expect($order->meta['auto_approved'])->toBeTrue();

        // Check organization license - should be active but payment_status should be unpaid
        $orgLicense = \App\Models\OrganizationLicense::where('organization_id', $trustedOrg->id)->first();
        expect($orgLicense->status)->toBe('active');
        expect($orgLicense->payment_status)->toBe('unpaid');
        expect($orgLicense->paid_at)->toBeNull();
    });
});
