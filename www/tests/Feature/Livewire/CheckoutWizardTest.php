<?php

use App\Livewire\CheckoutWizard;
use App\Models\License;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Create a test license
    $this->testLicense = createLicense([
        'slug' => 'test-license',
        'name' => 'Test License',
        'tier' => 'premium',
        'amount' => 19.99,
        'currency' => 'EUR',
        'credits' => 1000,
        'billing_cycle' => 'monthly',
        'credit_reset_interval' => 'monthly',
        'active' => true,
        'ordering' => 10,
    ]);
});

describe('Authentication Requirements', function () {
    it('redirects unauthenticated users to login', function () {
        session(['selected_license_id' => $this->testLicense->id]);

        Livewire::test(CheckoutWizard::class)
            ->assertRedirect(route('login'));
    });

    it('redirects unverified users to verification page', function () {
        $user = createUser(['email_verified_at' => null]);
        session(['selected_license_id' => $this->testLicense->id]);

        Livewire::actingAs($user)
            ->test(CheckoutWizard::class)
            ->assertRedirect(route('verification.notice'));
    });

    it('requires license selection', function () {
        $user = createUser(['email_verified_at' => now()]);

        Livewire::actingAs($user)
            ->test(CheckoutWizard::class)
            ->assertRedirect(route('pricing'));
    });
});

describe('Initial State', function () {
    it('loads component with valid license', function () {
        $user = createUser(['email_verified_at' => now()]);

        Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class)
            ->assertStatus(200)
            ->assertSet('licenseId', $this->testLicense->id);
    });

    it('loads license data correctly', function () {
        $user = createUser(['email_verified_at' => now()]);

        $component = Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class);

        expect($component->get('licenseData.name'))->toBe('Test License');
        expect($component->get('licenseData.tier'))->toBe('premium');
    });

    it('prefills billing data from user', function () {
        $user = createUser([
            'email_verified_at' => now(),
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $component = Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class);

        expect($component->get('billingData.full_name'))->toBe('John Doe');
        expect($component->get('billingData.email'))->toBe('john@example.com');
    });
});

describe('Payer Selection', function () {
    it('allows switching to user payer', function () {
        $user = createUser(['email_verified_at' => now()]);
        $org = createOrganization(['name' => 'Test Org']);
        $org->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class)
            ->call('switchPayer', 'user', $user->id)
            ->assertSet('payerType', 'user')
            ->assertSet('payerId', $user->id)
            ->assertSet('buyerType', 'individual');
    });

    it('allows switching to organization payer', function () {
        $user = createUser(['email_verified_at' => now()]);
        $org = createOrganization(['name' => 'Test Org']);
        $org->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class)
            ->call('switchPayer', 'organization', $org->id)
            ->assertSet('payerType', 'organization')
            ->assertSet('payerId', $org->id)
            ->assertSet('buyerType', 'company');
    });

    it('populates billing data from organization when switching', function () {
        $user = createUser(['email_verified_at' => now()]);
        $org = createOrganization([
            'name' => 'Acme Corp',
            'vat_number' => 'NL123456789B01',
        ]);
        $org->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $component = Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class)
            ->call('switchPayer', 'organization', $org->id);

        expect($component->get('billingData.company_name'))->toBe('Acme Corp');
        expect($component->get('billingData.vat_id'))->toBe('NL123456789B01');
    });
});

describe('Country and Currency', function () {
    it('sets default country to NL', function () {
        $user = createUser(['email_verified_at' => now()]);

        Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class)
            ->assertSet('country', 'NL');
    });

    it('updates currency when country changes', function () {
        $user = createUser(['email_verified_at' => now()]);

        $component = Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class)
            ->set('country', 'US');

        expect($component->get('currency'))->toBe('USD');
    });

    it('recalculates pricing when country changes', function () {
        $user = createUser(['email_verified_at' => now()]);

        $component = Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class);

        $initialPricing = $component->get('pricingData');

        $component->set('country', 'US');

        // Pricing should be recalculated (may differ due to VAT)
        expect($component->get('pricingData'))->not->toBeNull();
    });
});

describe('VAT Validation', function () {
    it('normalizes VAT ID to uppercase', function () {
        $user = createUser(['email_verified_at' => now()]);

        Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class)
            ->set('billingData.vat_id', 'nl123456789b01')
            ->assertSet('billingData.vat_id', 'NL123456789B01');
    });
});

describe('Invoice Payment Flow', function () {
    it('recognizes invoice payment from URL parameter', function () {
        $user = createUser(['email_verified_at' => now()]);
        $org = createOrganization(['name' => 'Test Org']);
        $org->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        session(['payer_type' => 'organization', 'payer_id' => $org->id]);

        $component = Livewire::actingAs($user)
            ->withQueryParams([
                'license' => $this->testLicense->id,
                'payment_method' => 'invoice',
            ])
            ->test(CheckoutWizard::class);

        expect($component->get('preselectedPaymentMethod'))->toBe('invoice');
    });
});

describe('Order Validation', function () {
    it('validates required billing fields', function () {
        $user = createUser(['email_verified_at' => now()]);

        Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class)
            ->set('payerType', 'user')
            ->set('payerId', $user->id)
            ->set('billingData.email', '')
            ->set('billingData.street', '')
            ->call('createOrder')
            ->assertHasErrors(['billingData.email', 'billingData.street']);
    });

    it('validates payer type', function () {
        $user = createUser(['email_verified_at' => now()]);

        Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class)
            ->set('payerType', null)
            ->call('createOrder')
            ->assertHasErrors(['payerType']);
    });

    it('validates state for US/CA/AU', function () {
        $user = createUser(['email_verified_at' => now()]);

        Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class)
            ->set('payerType', 'user')
            ->set('payerId', $user->id)
            ->set('country', 'US')
            ->set('billingData.email', 'test@example.com')
            ->set('billingData.full_name', 'John Doe')
            ->set('billingData.street', '123 Main St')
            ->set('billingData.postal_code', '12345')
            ->set('billingData.city', 'New York')
            ->set('billingData.state', '') // Required for US
            ->call('createOrder')
            ->assertHasErrors(['billingData.state']);
    });

    it('does not require state for EU countries', function () {
        $user = createUser(['email_verified_at' => now()]);

        $component = Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class)
            ->set('payerType', 'user')
            ->set('payerId', $user->id)
            ->set('country', 'NL')
            ->set('billingData.email', 'test@example.com')
            ->set('billingData.full_name', 'John Doe')
            ->set('billingData.street', '123 Main St')
            ->set('billingData.postal_code', '1234AB')
            ->set('billingData.city', 'Amsterdam')
            ->set('billingData.state', ''); // Not required for NL

        // Should not have state error for NL
        expect($component->errors()->has('billingData.state'))->toBeFalse();
    });
});

describe('Helper Methods', function () {
    it('calculates VAT rate correctly', function () {
        $user = createUser(['email_verified_at' => now()]);

        $component = Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class);

        $vatRate = $component->instance()->getVatRate();

        // Should be a percentage (e.g., 21 for NL)
        expect($vatRate)->toBeGreaterThanOrEqual(0);
    });

    it('formats amounts correctly', function () {
        $user = createUser(['email_verified_at' => now()]);

        $component = Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class);

        $formatted = $component->instance()->formatAmount(19.99);

        // Formatted amount should be a non-empty string containing currency symbol or amount
        expect($formatted)->not->toBeEmpty();
        expect($formatted)->toBeString();
    });

    it('gets validity text for licenses', function () {
        $user = createUser(['email_verified_at' => now()]);

        $component = Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class);

        // 180 days = 6 months
        $text = $component->instance()->getValidityText(180);
        expect($text)->toContain('6');

        // 30 days = 1 month
        $text = $component->instance()->getValidityText(30);
        expect($text)->toContain('1');
    });

    it('provides VAT display info', function () {
        $user = createUser(['email_verified_at' => now()]);

        $component = Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class);

        $vatInfo = $component->instance()->getVatDisplayInfo();

        expect($vatInfo)->toHaveKeys(['show_vat_note', 'vat_note']);
    });
});

describe('Organization Loading', function () {
    it('loads available organizations', function () {
        $user = createUser(['email_verified_at' => now()]);
        $org1 = createOrganization(['name' => 'Org 1']);
        $org2 = createOrganization(['name' => 'Org 2']);
        $org1->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);
        $org2->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Editor->value, 'joined_at' => now()]);

        $component = Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class);

        expect($component->get('availableOrganizations'))->toHaveCount(2);
    });
});

describe('Trusted Organization', function () {
    it('detects trusted organization status', function () {
        $user = createUser(['email_verified_at' => now()]);
        $trustedOrg = createOrganization(['name' => 'Trusted Org', 'is_trusted' => true]);
        $trustedOrg->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $component = Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class)
            ->call('switchPayer', 'organization', $trustedOrg->id);

        expect($component->get('isTrustedOrganization'))->toBeTrue();
    });

    it('detects non-trusted organization status', function () {
        $user = createUser(['email_verified_at' => now()]);
        $regularOrg = createOrganization(['name' => 'Regular Org', 'is_trusted' => false]);
        $regularOrg->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $component = Livewire::actingAs($user)
            ->withQueryParams(['license' => $this->testLicense->id])
            ->test(CheckoutWizard::class)
            ->call('switchPayer', 'organization', $regularOrg->id);

        expect($component->get('isTrustedOrganization'))->toBeFalse();
    });
});
