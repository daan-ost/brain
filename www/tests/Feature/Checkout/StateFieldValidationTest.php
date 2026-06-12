<?php

use App\Livewire\CheckoutWizard;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
});

describe('State Field Validation', function () {
    it('requires state field for US country', function () {
        $user = createUser(['email_verified_at' => now()]);
        $organization = createOrganization([
            'name' => 'US Corp',
            'billing_country_code' => 'US',
            'is_trusted' => true,
        ]);
        $organization->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $license = createLicense(['tier' => 'onetime']);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'tier' => 'onetime',
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        // Set country to US
        $component->set('country', 'US');

        // Fill required fields except state
        $component->set('billingData.email', 'test@example.com');
        $component->set('billingData.company_name', 'US Corp');
        $component->set('billingData.street', '123 Main St');
        $component->set('billingData.postal_code', '90210');
        $component->set('billingData.city', 'Los Angeles');
        // Intentionally not setting state

        $component->call('createOrder');

        $component->assertHasErrors(['billingData.state']);
    });

    it('requires state field for CA country', function () {
        $user = createUser(['email_verified_at' => now()]);
        $organization = createOrganization([
            'name' => 'CA Corp',
            'billing_country_code' => 'CA',
            'is_trusted' => true,
        ]);
        $organization->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $license = createLicense(['tier' => 'onetime']);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'tier' => 'onetime',
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        $component->set('country', 'CA');
        $component->set('billingData.email', 'test@example.com');
        $component->set('billingData.company_name', 'CA Corp');
        $component->set('billingData.street', '123 Main St');
        $component->set('billingData.postal_code', 'M5V 1J1');
        $component->set('billingData.city', 'Toronto');

        $component->call('createOrder');

        $component->assertHasErrors(['billingData.state']);
    });

    it('requires state field for AU country', function () {
        $user = createUser(['email_verified_at' => now()]);
        $organization = createOrganization([
            'name' => 'AU Corp',
            'billing_country_code' => 'AU',
            'is_trusted' => true,
        ]);
        $organization->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $license = createLicense(['tier' => 'onetime']);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'tier' => 'onetime',
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        $component->set('country', 'AU');
        $component->set('billingData.email', 'test@example.com');
        $component->set('billingData.company_name', 'AU Corp');
        $component->set('billingData.street', '123 Main St');
        $component->set('billingData.postal_code', '2000');
        $component->set('billingData.city', 'Sydney');

        $component->call('createOrder');

        $component->assertHasErrors(['billingData.state']);
    });

    it('does not require state field for NL country', function () {
        $user = createUser(['email_verified_at' => now()]);
        $organization = createOrganization([
            'name' => 'NL Corp',
            'billing_country_code' => 'NL',
            'is_trusted' => true,
        ]);
        $organization->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $license = createLicense(['tier' => 'onetime']);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'tier' => 'onetime',
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        $component->set('country', 'NL');
        $component->set('billingData.email', 'test@example.com');
        $component->set('billingData.company_name', 'NL Corp');
        $component->set('billingData.street', 'Teststraat 123');
        $component->set('billingData.postal_code', '1234 AB');
        $component->set('billingData.city', 'Amsterdam');

        $component->assertHasNoErrors(['billingData.state']);
    });

    it('does not require state field for DE country', function () {
        $user = createUser(['email_verified_at' => now()]);
        $organization = createOrganization([
            'name' => 'DE Corp',
            'billing_country_code' => 'DE',
            'is_trusted' => true,
        ]);
        $organization->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $license = createLicense(['tier' => 'onetime']);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'tier' => 'onetime',
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        $component->set('country', 'DE');
        $component->set('billingData.email', 'test@example.com');
        $component->set('billingData.company_name', 'DE Corp');
        $component->set('billingData.street', 'Teststraße 123');
        $component->set('billingData.postal_code', '10115');
        $component->set('billingData.city', 'Berlin');

        $component->assertHasNoErrors(['billingData.state']);
    });

    it('accepts valid state for US orders', function () {
        $user = createUser(['email_verified_at' => now()]);
        $organization = createOrganization([
            'name' => 'US Corp',
            'billing_country_code' => 'US',
            'is_trusted' => true,
        ]);
        $organization->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $license = createLicense(['tier' => 'onetime']);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'tier' => 'onetime',
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        $component->set('country', 'US');
        $component->set('billingData.email', 'test@example.com');
        $component->set('billingData.company_name', 'US Corp');
        $component->set('billingData.street', '123 Main St');
        $component->set('billingData.postal_code', '90210');
        $component->set('billingData.city', 'Los Angeles');
        $component->set('billingData.state', 'California');

        $component->assertHasNoErrors(['billingData.state']);
    });
});

describe('State Field in Billing Data', function () {
    it('includes state in billing data array', function () {
        $user = createUser(['email_verified_at' => now()]);
        $organization = createOrganization(['name' => 'Test Corp']);
        $organization->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        $license = createLicense(['tier' => 'onetime']);

        $this->actingAs($user);

        $component = Livewire::withQueryParams([
            'license' => $license->id,
            'tier' => 'onetime',
            'payment_method' => 'invoice',
        ])->test(CheckoutWizard::class);

        $component->set('billingData.state', 'California');

        expect($component->get('billingData.state'))->toBe('California');
    });
});
