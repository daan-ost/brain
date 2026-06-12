<?php

use App\Livewire\CheckoutWizard;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
});

describe('Trusted Organization Detection', function () {
    it('updates trusted status when switching to trusted organization', function () {
        $user = createUser(['email_verified_at' => now()]);

        $trustedOrg = createOrganization([
            'name' => 'Trusted Corp',
            'is_trusted' => true,
        ]);
        $trustedOrg->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $license = createLicense(['tier' => 'onetime']);

        // Set up the request properly for Livewire
        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'tier' => 'onetime',
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        // Switch to trusted org
        $component->call('switchPayer', 'organization', $trustedOrg->id);
        $component->assertSet('isTrustedOrganization', true);
    });

    it('updates trusted status when switching to non-trusted organization', function () {
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
            'tier' => 'onetime',
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        // Switch to regular org
        $component->call('switchPayer', 'organization', $regularOrg->id);
        $component->assertSet('isTrustedOrganization', false);
    });

    it('updates trusted status when switching between organizations', function () {
        $user = createUser(['email_verified_at' => now()]);

        $trustedOrg = createOrganization([
            'name' => 'Trusted Corp',
            'is_trusted' => true,
        ]);
        $trustedOrg->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $regularOrg = createOrganization([
            'name' => 'Regular Corp',
            'is_trusted' => false,
        ]);
        $regularOrg->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()->addSecond()]);

        $license = createLicense(['tier' => 'onetime']);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'tier' => 'onetime',
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        // Switch to regular org
        $component->call('switchPayer', 'organization', $regularOrg->id);
        $component->assertSet('isTrustedOrganization', false);

        // Switch to trusted org
        $component->call('switchPayer', 'organization', $trustedOrg->id);
        $component->assertSet('isTrustedOrganization', true);
    });

    it('sets trusted to false for user payer type', function () {
        $user = createUser(['email_verified_at' => now()]);
        $license = createLicense(['tier' => 'onetime']);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'tier' => 'onetime',
        ])->test(CheckoutWizard::class);

        // Switch to user
        $component->call('switchPayer', 'user', $user->id);
        $component->assertSet('isTrustedOrganization', false);
    });
});

describe('Invoice Payment Auto-Selection', function () {
    it('auto-selects first organization for invoice payments', function () {
        $user = createUser(['email_verified_at' => now()]);
        $organization = createOrganization(['name' => 'Auto Corp']);
        $organization->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $license = createLicense(['tier' => 'onetime']);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'tier' => 'onetime',
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        $component->assertSet('payerType', 'organization');
        $component->assertSet('payerId', $organization->id);
    });

    it('populates billing data from organization profile', function () {
        $user = createUser(['email_verified_at' => now()]);
        $organization = createOrganization([
            'name' => 'Billing Corp',
            'billing_country_code' => 'NL',
            'vat_number' => 'NL123456789B01',
        ]);
        $organization->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $license = createLicense(['tier' => 'onetime']);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'tier' => 'onetime',
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        expect($component->get('billingData.company_name'))->toBe('Billing Corp');
        expect($component->get('billingData.vat_id'))->toBe('NL123456789B01');
    });
});

describe('Company Billing Fields', function () {
    it('sets buyer type to company for organization purchases', function () {
        $user = createUser(['email_verified_at' => now()]);
        $organization = createOrganization(['name' => 'Company Corp']);
        $organization->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $license = createLicense(['tier' => 'onetime']);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'tier' => 'onetime',
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        $component->assertSet('buyerType', 'company');
    });
});
