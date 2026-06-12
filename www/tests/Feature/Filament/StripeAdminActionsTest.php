<?php

declare(strict_types=1);

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrganizationResource\RelationManagers\OrganizationLicensesRelationManager;
use App\Filament\Resources\OrganizationResource\Pages\ViewOrganization;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Filament\Resources\UserResource\RelationManagers\UserLicensesRelationManager;
use App\Models\License;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Models\UserLicense;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin, 'admin');
});

describe('UserLicensesRelationManager — Stripe-aware actions', function () {
    it('shows cancel action for Stripe subscription license', function () {
        $user = User::factory()->create();
        $license = License::factory()->create();
        $stripeLicense = UserLicense::factory()->stripe()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'ends_at' => null,
        ]);

        Livewire::test(UserLicensesRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => ViewUser::class,
        ])
            ->assertSuccessful()
            ->assertTableActionVisible('cancelSubscription', $stripeLicense);
    });

    it('shows cancel action for legacy Mollie subscription license (regression)', function () {
        $user = User::factory()->create();
        $license = License::factory()->create();
        $mollieLicense = UserLicense::factory()->mollie()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'ends_at' => null,
        ]);

        Livewire::test(UserLicensesRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => ViewUser::class,
        ])
            ->assertSuccessful()
            ->assertTableActionVisible('cancelSubscription', $mollieLicense);
    });

    it('hides cancel action for license without any subscription id', function () {
        $user = User::factory()->create();
        $license = License::factory()->create();
        $manualLicense = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
            'ends_at' => null,
            'mollie_subscription_id' => null,
            'provider_subscription_id' => null,
        ]);

        Livewire::test(UserLicensesRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => ViewUser::class,
        ])
            ->assertSuccessful()
            ->assertTableActionHidden('cancelSubscription', $manualLicense);
    });

    it('shows viewInProviderDashboard for Stripe license', function () {
        $user = User::factory()->create();
        $license = License::factory()->create();
        $stripeLicense = UserLicense::factory()->stripe()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
        ]);

        Livewire::test(UserLicensesRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => ViewUser::class,
        ])
            ->assertSuccessful()
            ->assertTableActionVisible('viewInProviderDashboard', $stripeLicense);
    });
});

describe('OrganizationLicensesRelationManager — Stripe-aware actions', function () {
    it('shows cancel action for Stripe organization subscription', function () {
        $org = Organization::factory()->create();
        $license = License::factory()->create();
        $stripeLicense = OrganizationLicense::factory()->stripe()->create([
            'organization_id' => $org->id,
            'license_id' => $license->id,
            'status' => 'active',
            'ends_at' => null,
        ]);

        Livewire::test(OrganizationLicensesRelationManager::class, [
            'ownerRecord' => $org,
            'pageClass' => ViewOrganization::class,
        ])
            ->assertSuccessful()
            ->assertTableActionVisible('cancelSubscription', $stripeLicense);
    });

    it('has_subscription filter includes Stripe-only organization licenses', function () {
        $org = Organization::factory()->create();
        $license = License::factory()->create();

        $stripeLicense = OrganizationLicense::factory()->stripe()->create([
            'organization_id' => $org->id,
            'license_id' => $license->id,
        ]);
        $manualLicense = OrganizationLicense::factory()->create([
            'organization_id' => $org->id,
            'license_id' => $license->id,
            'mollie_subscription_id' => null,
            'provider_subscription_id' => null,
        ]);

        Livewire::test(OrganizationLicensesRelationManager::class, [
            'ownerRecord' => $org,
            'pageClass' => ViewOrganization::class,
        ])
            ->filterTable('has_subscription', true)
            ->assertCanSeeTableRecords([$stripeLicense])
            ->assertCanNotSeeTableRecords([$manualLicense]);
    });
});

describe('OrderResource — Stripe refund action', function () {
    it('shows refund action for paid Stripe order', function () {
        $stripeOrder = Order::factory()->stripe()->create([
            'status' => 'paid',
            'gross_amount' => 49.99,
        ]);

        Livewire::test(\App\Filament\Resources\OrderResource\Pages\ListOrders::class)
            ->assertSuccessful()
            ->assertTableActionVisible('refund', $stripeOrder);
    });

    it('shows refund action for paid Mollie order (regression)', function () {
        $mollieOrder = Order::factory()->create([
            'status' => 'paid',
            'gross_amount' => 49.99,
            'payment_provider' => null,
            'mollie_payment_id' => 'tr_x',
        ]);

        Livewire::test(\App\Filament\Resources\OrderResource\Pages\ListOrders::class)
            ->assertSuccessful()
            ->assertTableActionVisible('refund', $mollieOrder);
    });

    it('hides refund action when order has no provider payment id', function () {
        $orphanOrder = Order::factory()->create([
            'status' => 'paid',
            'gross_amount' => 49.99,
            'payment_provider' => null,
            'mollie_payment_id' => null,
            'provider_payment_id' => null,
        ]);

        Livewire::test(\App\Filament\Resources\OrderResource\Pages\ListOrders::class)
            ->assertSuccessful()
            ->assertTableActionHidden('refund', $orphanOrder);
    });
});
