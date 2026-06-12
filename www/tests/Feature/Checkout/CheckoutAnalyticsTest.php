<?php

use App\Livewire\CheckoutWizard;
use App\Models\AnalyticsEvent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    session()->flush();
});

describe('Checkout Started Analytics Event', function () {
    it('logs checkout_started event when checkout page loads with valid license', function () {
        $user = createUser(['email_verified_at' => now()]);
        $license = createLicense([
            'tier' => 'onetime',
            'slug' => 'business-100',
            'credits' => 100,
        ]);

        $this->actingAs($user);

        Livewire::withQueryParams([
            'license' => $license->id,
        ])->test(CheckoutWizard::class);

        // Verify checkout_started event was logged
        $event = AnalyticsEvent::where('event', 'checkout_started')
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        expect($event)->not->toBeNull()
            ->and($event->meta['license_id'])->toBe($license->id)
            ->and($event->meta['license_slug'])->toBe('business-100')
            ->and($event->meta['license_tier'])->toBe('onetime')
            ->and($event->meta['payment_flow'])->toBe('online')
            ->and($event->meta['currency'])->toBe('EUR');
    });

    it('logs checkout_started with invoice payment flow', function () {
        $user = createUser(['email_verified_at' => now()]);
        $organization = createOrganization(['name' => 'Test Corp']);
        $organization->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $license = createLicense([
            'tier' => 'onetime',
            'slug' => 'enterprise-500',
        ]);

        $this->actingAs($user);

        Livewire::withQueryParams([
            'license' => $license->id,
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        $event = AnalyticsEvent::where('event', 'checkout_started')
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        expect($event)->not->toBeNull()
            ->and($event->meta['payment_flow'])->toBe('invoice')
            ->and($event->meta['payer_type'])->toBe('organization')
            ->and($event->meta['payer_id'])->toBe($organization->id);
    });

    it('logs checkout_started with trusted organization status', function () {
        $user = createUser(['email_verified_at' => now()]);
        $trustedOrg = createOrganization([
            'name' => 'Trusted Corp',
            'is_trusted' => true,
        ]);
        $trustedOrg->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $license = createLicense(['tier' => 'onetime']);

        $this->actingAs($user);

        Livewire::withQueryParams([
            'license' => $license->id,
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        $event = AnalyticsEvent::where('event', 'checkout_started')
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        expect($event)->not->toBeNull()
            ->and($event->meta['is_trusted'])->toBeTrue();
    });
});

describe('Checkout Payer Selected Analytics Event', function () {
    it('logs checkout_payer_selected when switching to organization', function () {
        $user = createUser(['email_verified_at' => now()]);
        $organization = createOrganization(['name' => 'Payer Corp']);
        $organization->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $license = createLicense([
            'tier' => 'onetime',
            'slug' => 'business-100',
        ]);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
        ])->test(CheckoutWizard::class);

        // Clear any previous events from mount
        AnalyticsEvent::where('event', 'checkout_payer_selected')->delete();

        // Switch to organization
        $component->call('switchPayer', 'organization', $organization->id);

        $event = AnalyticsEvent::where('event', 'checkout_payer_selected')
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        expect($event)->not->toBeNull()
            ->and($event->meta['license_id'])->toBe($license->id)
            ->and($event->meta['license_slug'])->toBe('business-100')
            ->and($event->meta['payer_type'])->toBe('organization')
            ->and($event->meta['payer_id'])->toBe($organization->id)
            ->and($event->meta['buyer_type'])->toBe('company');
    });

    it('logs checkout_payer_selected when switching to user', function () {
        $user = createUser(['email_verified_at' => now()]);
        $license = createLicense(['tier' => 'onetime']);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
        ])->test(CheckoutWizard::class);

        // Clear events
        AnalyticsEvent::where('event', 'checkout_payer_selected')->delete();

        // Switch to user
        $component->call('switchPayer', 'user', $user->id);

        $event = AnalyticsEvent::where('event', 'checkout_payer_selected')
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        expect($event)->not->toBeNull()
            ->and($event->meta['payer_type'])->toBe('user')
            ->and($event->meta['payer_id'])->toBe($user->id)
            ->and($event->meta['is_trusted'])->toBeFalse()
            ->and($event->meta['buyer_type'])->toBe('individual');
    });

    it('logs checkout_payer_selected with trusted status for trusted organization', function () {
        $user = createUser(['email_verified_at' => now()]);
        $trustedOrg = createOrganization([
            'name' => 'Trusted Corp',
            'is_trusted' => true,
        ]);
        $trustedOrg->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $license = createLicense(['tier' => 'onetime']);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
        ])->test(CheckoutWizard::class);

        // Clear events
        AnalyticsEvent::where('event', 'checkout_payer_selected')->delete();

        // Switch to trusted org
        $component->call('switchPayer', 'organization', $trustedOrg->id);

        $event = AnalyticsEvent::where('event', 'checkout_payer_selected')
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        expect($event)->not->toBeNull()
            ->and($event->meta['is_trusted'])->toBeTrue();
    });

    it('logs checkout_payer_selected with non-trusted status for regular organization', function () {
        $user = createUser(['email_verified_at' => now()]);
        $regularOrg = createOrganization([
            'name' => 'Regular Corp',
            'is_trusted' => false,
        ]);
        $regularOrg->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $license = createLicense(['tier' => 'onetime']);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
        ])->test(CheckoutWizard::class);

        // Clear events
        AnalyticsEvent::where('event', 'checkout_payer_selected')->delete();

        // Switch to regular org
        $component->call('switchPayer', 'organization', $regularOrg->id);

        $event = AnalyticsEvent::where('event', 'checkout_payer_selected')
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        expect($event)->not->toBeNull()
            ->and($event->meta['is_trusted'])->toBeFalse();
    });
});

describe('Checkout Payment Initiated Analytics Event', function () {
    it('logs checkout_payment_initiated when createOrder is called with valid data', function () {
        $user = createUser(['email_verified_at' => now()]);
        $license = createLicense([
            'tier' => 'onetime',
            'slug' => 'business-100',
            'amount' => 99.00,
            'credits' => 100,
        ]);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
        ])->test(CheckoutWizard::class);

        // Set required billing data
        $component->set('payerType', 'user');
        $component->set('payerId', $user->id);
        $component->set('billingData.email', 'test@example.com');
        $component->set('billingData.full_name', 'Test User');
        $component->set('billingData.street', 'Test Street 123');
        $component->set('billingData.postal_code', '1234AB');
        $component->set('billingData.city', 'Amsterdam');
        $component->set('country', 'NL');
        $component->set('selectedPaymentMethod', 'ideal');

        // Clear events from mount
        AnalyticsEvent::where('event', 'checkout_payment_initiated')->delete();

        // This will fail with redirect but should still log the event
        try {
            $component->call('createOrder');
        } catch (\Exception $e) {
            // Expected - Mollie redirect or other issues in test env
        }

        $event = AnalyticsEvent::where('event', 'checkout_payment_initiated')
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        expect($event)->not->toBeNull()
            ->and($event->meta['license_id'])->toBe($license->id)
            ->and($event->meta['license_slug'])->toBe('business-100')
            ->and($event->meta['license_tier'])->toBe('onetime')
            ->and($event->meta['payment_method'])->toBe('ideal')
            ->and($event->meta['payer_type'])->toBe('user')
            ->and($event->meta['payer_id'])->toBe($user->id)
            ->and($event->meta['currency'])->toBe('EUR')
            ->and($event->meta['buyer_type'])->toBe('individual');

        // Country is set from component state (not AnalyticsService resolver)
        expect(array_key_exists('country', $event->meta))->toBeTrue();
    });

    it('logs checkout_payment_initiated with invoice payment method', function () {
        $user = createUser(['email_verified_at' => now()]);
        $organization = createOrganization([
            'name' => 'Invoice Corp',
            'is_trusted' => true,
        ]);
        $organization->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $license = createLicense([
            'tier' => 'onetime',
            'slug' => 'enterprise-500',
            'amount' => 499.00,
        ]);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        // Set required billing data for organization
        $component->set('billingData.email', 'billing@invoicecorp.com');
        $component->set('billingData.company_name', 'Invoice Corp');
        $component->set('billingData.street', 'Business Street 456');
        $component->set('billingData.postal_code', '5678CD');
        $component->set('billingData.city', 'Rotterdam');

        // Clear events
        AnalyticsEvent::where('event', 'checkout_payment_initiated')->delete();

        // Call createOrder - will handle invoice flow
        try {
            $component->call('createOrder');
        } catch (\Exception $e) {
            // Expected in test environment
        }

        $event = AnalyticsEvent::where('event', 'checkout_payment_initiated')
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        expect($event)->not->toBeNull()
            ->and($event->meta['payment_method'])->toBe('invoice')
            ->and($event->meta['payer_type'])->toBe('organization')
            ->and($event->meta['is_trusted'])->toBeTrue()
            ->and($event->meta['buyer_type'])->toBe('company');
    });

    it('does not log checkout_payment_initiated when validation fails', function () {
        $user = createUser(['email_verified_at' => now()]);
        $license = createLicense(['tier' => 'onetime']);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
        ])->test(CheckoutWizard::class);

        // Clear all events
        AnalyticsEvent::where('event', 'checkout_payment_initiated')->delete();

        // Don't set required fields - validation should fail
        // payerType and payerId are not set

        try {
            $component->call('createOrder');
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Expected
        }

        $event = AnalyticsEvent::where('event', 'checkout_payment_initiated')
            ->where('user_id', $user->id)
            ->first();

        // Event should not be logged because validation failed before the log call
        expect($event)->toBeNull();
    });
});

describe('Analytics Event Data Completeness', function () {
    it('includes all required fields in checkout_started event', function () {
        $user = createUser(['email_verified_at' => now()]);
        $license = createLicense([
            'tier' => 'premium',
            'slug' => 'premium-unlimited',
        ]);

        $this->actingAs($user);

        Livewire::withQueryParams([
            'license' => $license->id,
            'currency' => 'USD',
        ])->test(CheckoutWizard::class);

        $event = AnalyticsEvent::where('event', 'checkout_started')
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        // Verify all expected fields are present
        expect($event->meta)->toHaveKeys([
            'license_id',
            'license_slug',
            'license_tier',
            'payment_flow',
            'currency',
            'payer_type',
            'payer_id',
            'is_trusted',
            'site',        // Added by AnalyticsService
            'country',     // Added by AnalyticsService
            'country_source', // Added by AnalyticsService
        ]);
    });

    it('includes site locale in all checkout events', function () {
        $user = createUser(['email_verified_at' => now()]);
        $license = createLicense(['tier' => 'onetime']);

        $this->actingAs($user);

        // Set locale
        app()->setLocale('nl');

        Livewire::withQueryParams([
            'license' => $license->id,
        ])->test(CheckoutWizard::class);

        $event = AnalyticsEvent::where('event', 'checkout_started')
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        expect($event->meta['site'])->toBe('nl');
    });
});
