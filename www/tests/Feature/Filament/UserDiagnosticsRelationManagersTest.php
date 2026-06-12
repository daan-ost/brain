<?php

declare(strict_types=1);

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Filament\Resources\UserResource\RelationManagers\CreditLedgerRelationManager;
use App\Filament\Resources\UserResource\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\UserResource\RelationManagers\UserLicensesRelationManager;
use App\Models\CreditLedger;
use App\Models\License;
use App\Models\Order;
use App\Models\User;
use App\Models\UserLicense;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin, 'admin');
});

describe('UserLicensesRelationManager — admin diagnostics', function () {
    it('is registered on UserResource', function () {
        $relations = UserResource::getRelations();
        $relationClasses = array_map(fn ($r) => is_string($r) ? $r : get_class($r), $relations);

        expect($relationClasses)->toContain(UserLicensesRelationManager::class);
    });

    it('renders without errors for a user with no licenses', function () {
        $user = User::factory()->create();

        Livewire::test(UserLicensesRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => ViewUser::class,
        ])->assertSuccessful();
    });

    it('renders without errors for a user with mixed-cycle licenses including a premature anomaly', function () {
        $user = User::factory()->create();
        $yearlyLicense = License::factory()->create(['billing_cycle' => 'yearly']);
        $monthlyLicense = License::factory()->create(['billing_cycle' => 'monthly']);

        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $yearlyLicense->id,
            'status' => UserLicense::STATUS_ACTIVE,
            'starts_at' => now()->subDays(60),
            'ends_at' => now()->addDays(300),
        ]);
        // Anomaly: yearly cancelled na 1 maand
        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $yearlyLicense->id,
            'status' => UserLicense::STATUS_CANCELED,
            'starts_at' => now()->subDays(40),
            'ends_at' => now()->subDays(10),
        ]);
        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $monthlyLicense->id,
            'status' => UserLicense::STATUS_ACTIVE,
            'starts_at' => now()->subDays(15),
            'ends_at' => now()->addDays(15),
        ]);

        Livewire::test(UserLicensesRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => ViewUser::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords($user->userLicenses);
    });

    it('billing_cycle filter narrows results to a single cycle', function () {
        $user = User::factory()->create();
        $yearly = License::factory()->create(['billing_cycle' => 'yearly']);
        $monthly = License::factory()->create(['billing_cycle' => 'monthly']);

        $yearlyLicense = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $yearly->id,
        ]);
        $monthlyLicense = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $monthly->id,
        ]);

        Livewire::test(UserLicensesRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => ViewUser::class,
        ])
            ->filterTable('billing_cycle', 'yearly')
            ->assertCanSeeTableRecords([$yearlyLicense])
            ->assertCanNotSeeTableRecords([$monthlyLicense]);
    });

    it('premature_expiry filter surfaces only anomaly licenses', function () {
        $user = User::factory()->create();
        $yearly = License::factory()->create(['billing_cycle' => 'yearly']);

        $healthy = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $yearly->id,
            'status' => UserLicense::STATUS_CANCELED,
            'starts_at' => now()->subDays(360),
            'ends_at' => now(),
        ]);
        $anomaly = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $yearly->id,
            'status' => UserLicense::STATUS_CANCELED,
            'starts_at' => now()->subDays(20),
            'ends_at' => now(),
        ]);

        Livewire::test(UserLicensesRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => ViewUser::class,
        ])
            ->filterTable('premature_expiry', true)
            ->assertCanSeeTableRecords([$anomaly])
            ->assertCanNotSeeTableRecords([$healthy]);
    });

    it('has_subscription filter recognizes both legacy mollie and new provider columns', function () {
        $user = User::factory()->create();
        $license = License::factory()->create();

        $legacyMollie = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'mollie_subscription_id' => 'sub_legacy',
            'provider_subscription_id' => null,
        ]);
        $stripe = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'mollie_subscription_id' => null,
            'provider_subscription_id' => 'sub_stripe',
            'payment_provider' => 'stripe',
        ]);
        $noSubscription = UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'mollie_subscription_id' => null,
            'provider_subscription_id' => null,
        ]);

        Livewire::test(UserLicensesRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => ViewUser::class,
        ])
            ->filterTable('has_subscription', true)
            ->assertCanSeeTableRecords([$legacyMollie, $stripe])
            ->assertCanNotSeeTableRecords([$noSubscription]);
    });
});

describe('OrdersRelationManager — admin diagnostics', function () {
    it('is registered on UserResource', function () {
        $relations = UserResource::getRelations();
        $relationClasses = array_map(fn ($r) => is_string($r) ? $r : get_class($r), $relations);

        expect($relationClasses)->toContain(OrdersRelationManager::class);
    });

    it('renders without errors for a user with no orders', function () {
        $user = User::factory()->create();

        Livewire::test(OrdersRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => ViewUser::class,
        ])->assertSuccessful();
    });

    it('renders without errors with mixed Mollie and Stripe orders', function () {
        $user = User::factory()->create();

        Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'payment_provider' => null,
            'mollie_payment_id' => 'tr_legacy',
        ]);
        Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'payment_provider' => 'stripe',
            'provider_payment_id' => 'pi_new',
            'mollie_payment_id' => null,
        ]);

        Livewire::test(OrdersRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => ViewUser::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords($user->orders);
    });

    it('payment_provider filter matches both explicit stripe column and legacy mollie rows', function () {
        $user = User::factory()->create();

        $legacyMollie = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'payment_provider' => null,
            'mollie_payment_id' => 'tr_legacy',
        ]);
        $explicitStripe = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
            'payment_provider' => 'stripe',
            'provider_payment_id' => 'pi_new',
            'mollie_payment_id' => null,
        ]);

        Livewire::test(OrdersRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => ViewUser::class,
        ])
            ->filterTable('payment_provider', 'mollie')
            ->assertCanSeeTableRecords([$legacyMollie])
            ->assertCanNotSeeTableRecords([$explicitStripe]);

        Livewire::test(OrdersRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => ViewUser::class,
        ])
            ->filterTable('payment_provider', 'stripe')
            ->assertCanSeeTableRecords([$explicitStripe])
            ->assertCanNotSeeTableRecords([$legacyMollie]);
    });
});

describe('CreditLedgerRelationManager — admin diagnostics', function () {
    it('renders without errors for a user with no ledger entries', function () {
        $user = User::factory()->create();

        Livewire::test(CreditLedgerRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => ViewUser::class,
        ])->assertSuccessful();
    });

    it('renders without errors with mixed reason ledger entries', function () {
        $user = User::factory()->create();

        CreditLedger::factory()->create([
            'user_id' => $user->id,
            'reason' => 'purchase',
            'delta' => 100,
            'meta' => ['order_id' => 'order-abc12345', 'gross_amount' => 99.99, 'currency' => 'EUR'],
        ]);
        CreditLedger::factory()->create([
            'user_id' => $user->id,
            'reason' => 'spend',
            'delta' => -10,
            'meta' => ['documents_count' => 3, 'workflow_name' => 'convert'],
        ]);
        CreditLedger::factory()->create([
            'user_id' => $user->id,
            'reason' => 'license_expired',
            'delta' => -50,
            'meta' => ['license_tier' => 'premium', 'license_id' => 42, 'expired_credits' => 50],
        ]);
        CreditLedger::factory()->create([
            'user_id' => $user->id,
            'reason' => 'admin_adjustment',
            'delta' => 25,
            'meta' => ['admin_reason' => 'goodwill correction'],
        ]);

        Livewire::test(CreditLedgerRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => ViewUser::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords($user->creditLedger);
    });

    it('survives ledger rows with non-array meta without crashing', function () {
        $user = User::factory()->create();

        // Forceer een rij met meta die NIET als array hydrateert (legacy/handgeëdite data).
        // Array cast retourneert dan een string, en de defensieve is_array() check
        // moet voorkomen dat we "Cannot use string offset" crashen.
        $entry = CreditLedger::factory()->create([
            'user_id' => $user->id,
            'reason' => 'correction',
            'delta' => 10,
            'meta' => [],
        ]);
        \DB::table('credit_ledger')->where('id', $entry->id)->update(['meta' => '"legacy-string"']);

        Livewire::test(CreditLedgerRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => ViewUser::class,
        ])->assertSuccessful();
    });

    it('reason filter narrows to selected reason types', function () {
        $user = User::factory()->create();

        $purchase = CreditLedger::factory()->create([
            'user_id' => $user->id,
            'reason' => 'purchase',
            'delta' => 100,
        ]);
        $spend = CreditLedger::factory()->create([
            'user_id' => $user->id,
            'reason' => 'spend',
            'delta' => -5,
        ]);

        Livewire::test(CreditLedgerRelationManager::class, [
            'ownerRecord' => $user,
            'pageClass' => ViewUser::class,
        ])
            ->filterTable('reason', ['purchase'])
            ->assertCanSeeTableRecords([$purchase])
            ->assertCanNotSeeTableRecords([$spend]);
    });
});
