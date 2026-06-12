<?php

declare(strict_types=1);

use App\Filament\Resources\OrganizationResource\RelationManagers\OrganizationLicensesRelationManager;
use App\Filament\Resources\UserResource\RelationManagers\UserLicensesRelationManager;
use App\Models\License;
use App\Models\Organization;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Models\UserLicense;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin, 'admin');
});

describe('UserLicensesRelationManager::MollieDetails', function () {
    it('renders relation manager without errors', function () {
        $user = User::factory()->create();

        Livewire::test(UserLicensesRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => \App\Filament\Resources\UserResource\Pages\ViewUser::class,
        ])->assertSuccessful();
    });

    it('user license can have mollie customer id', function () {
        $user = User::factory()->create();
        $license = License::factory()->create();

        $userLicense = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'mollie_customer_id' => 'cst_test123',
            'mollie_subscription_id' => 'sub_test456',
        ]);

        expect($userLicense->mollie_customer_id)->toBe('cst_test123');
        expect($userLicense->mollie_subscription_id)->toBe('sub_test456');
    });

    it('user license can have null mollie ids', function () {
        $user = User::factory()->create();
        $license = License::factory()->create();

        $userLicense = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'mollie_customer_id' => null,
            'mollie_subscription_id' => null,
        ]);

        expect($userLicense->mollie_customer_id)->toBeNull();
        expect($userLicense->mollie_subscription_id)->toBeNull();
    });

    it('user license belongs to user', function () {
        $user = User::factory()->create();
        $license = License::factory()->create();

        $userLicense = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
        ]);

        expect($userLicense->user->id)->toBe($user->id);
    });

    it('user license belongs to license', function () {
        $user = User::factory()->create();
        $license = License::factory()->create(['name' => 'Premium Plan']);

        $userLicense = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
        ]);

        expect($userLicense->license->name)->toBe('Premium Plan');
    });

    it('user has userLicenses relationship', function () {
        $user = User::factory()->create();
        $license = License::factory()->create();

        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
        ]);

        expect($user->userLicenses()->count())->toBe(1);
    });
});

describe('OrganizationLicensesRelationManager::MollieDetails', function () {
    it('renders relation manager without errors', function () {
        $organization = Organization::factory()->create();

        Livewire::test(OrganizationLicensesRelationManager::class, [
            'ownerRecord' => $organization,
            'pageClass' => \App\Filament\Resources\OrganizationResource\Pages\ViewOrganization::class,
        ])->assertSuccessful();
    });

    it('organization license can have mollie customer id', function () {
        $organization = Organization::factory()->create();
        $license = License::factory()->create();

        $orgLicense = OrganizationLicense::factory()->create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'mollie_customer_id' => 'cst_org123',
            'mollie_subscription_id' => 'sub_org456',
        ]);

        expect($orgLicense->mollie_customer_id)->toBe('cst_org123');
        expect($orgLicense->mollie_subscription_id)->toBe('sub_org456');
    });

    it('organization license can have null mollie ids', function () {
        $organization = Organization::factory()->create();
        $license = License::factory()->create();

        $orgLicense = OrganizationLicense::factory()->create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'mollie_customer_id' => null,
            'mollie_subscription_id' => null,
        ]);

        expect($orgLicense->mollie_customer_id)->toBeNull();
        expect($orgLicense->mollie_subscription_id)->toBeNull();
    });

    it('organization license belongs to organization', function () {
        $organization = Organization::factory()->create(['name' => 'Test Org']);
        $license = License::factory()->create();

        $orgLicense = OrganizationLicense::factory()->create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
        ]);

        expect($orgLicense->organization->name)->toBe('Test Org');
    });

    it('organization license belongs to license', function () {
        $organization = Organization::factory()->create();
        $license = License::factory()->create(['name' => 'Enterprise Plan']);

        $orgLicense = OrganizationLicense::factory()->create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
        ]);

        expect($orgLicense->license->name)->toBe('Enterprise Plan');
    });

    it('organization has organizationLicenses relationship', function () {
        $organization = Organization::factory()->create();
        $license = License::factory()->create();

        OrganizationLicense::factory()->create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
        ]);

        expect($organization->organizationLicenses()->count())->toBe(1);
    });

    it('organization license can have billing method', function () {
        $organization = Organization::factory()->create();
        $license = License::factory()->create();

        $onlineLicense = OrganizationLicense::factory()->create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'billing_method' => 'online',
        ]);

        $invoiceLicense = OrganizationLicense::factory()->create([
            'organization_id' => $organization->id,
            'license_id' => $license->id,
            'billing_method' => 'invoice',
        ]);

        expect($onlineLicense->billing_method)->toBe('online');
        expect($invoiceLicense->billing_method)->toBe('invoice');
    });
});
