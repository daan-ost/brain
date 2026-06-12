<?php

declare(strict_types=1);

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\CreditLedger;
use App\Models\License;
use App\Models\User;
use App\Models\UserLicense;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin, 'admin');
});

describe('UserResource::grantCredits', function () {
    it('grants bonus credits to a user and creates ledger entry', function () {
        $user = User::factory()->create(['credits' => 100]);

        Livewire::test(ListUsers::class)
            ->callTableAction('grantCredits', $user, data: [
                'amount' => 50,
                'reason' => 'bonus',
                'notes' => 'Test bonus grant',
            ])
            ->assertNotified('Credits granted successfully');

        $user->refresh();
        expect($user->credits)->toBe(150);

        $ledger = CreditLedger::where('user_id', $user->id)->latest('id')->first();
        expect($ledger)->not->toBeNull();
        expect($ledger->delta)->toBe(50);
        expect($ledger->reason)->toBe('bonus');
        expect($ledger->balance_after)->toBe(150);
    });

    it('stores correct meta data in ledger entry', function () {
        $user = User::factory()->create(['credits' => 0]);

        Livewire::test(ListUsers::class)
            ->callTableAction('grantCredits', $user, data: [
                'amount' => 200,
                'reason' => 'compensation',
                'notes' => 'Service outage compensation',
            ]);

        $ledger = CreditLedger::where('user_id', $user->id)->latest('id')->first();

        expect($ledger->meta['admin_reason'])->toBe('Service outage compensation');
        expect($ledger->meta['granted_by'])->toBe($this->admin->name);
        expect($ledger->meta)->toHaveKey('granted_at');
    });

    it('works with all valid reason types', function (string $reason) {
        $user = User::factory()->create(['credits' => 0]);

        Livewire::test(ListUsers::class)
            ->callTableAction('grantCredits', $user, data: [
                'amount' => 10,
                'reason' => $reason,
                'notes' => null,
            ])
            ->assertNotified('Credits granted successfully');

        $ledger = CreditLedger::where('user_id', $user->id)
            ->where('reason', $reason)
            ->first();
        expect($ledger)->not->toBeNull();
    })->with(['bonus', 'compensation', 'promotion', 'refund', 'correction']);

    it('updates credits_updated_at timestamp', function () {
        $user = User::factory()->create(['credits' => 0, 'credits_updated_at' => null]);

        Livewire::test(ListUsers::class)
            ->callTableAction('grantCredits', $user, data: [
                'amount' => 50,
                'reason' => 'bonus',
                'notes' => null,
            ]);

        $user->refresh();
        expect($user->credits_updated_at)->not->toBeNull();
    });

    it('requires minimum 1 credit', function () {
        $user = User::factory()->create(['credits' => 100]);

        Livewire::test(ListUsers::class)
            ->callTableAction('grantCredits', $user, data: [
                'amount' => 0,
                'reason' => 'bonus',
                'notes' => null,
            ])
            ->assertHasTableActionErrors(['amount']);
    });
});

describe('UserResource::addLicense', function () {
    it('adds a license to a user with source manual', function () {
        $user = User::factory()->create();
        $license = License::factory()->create(['active' => true]);

        Livewire::test(ListUsers::class)
            ->callTableAction('addLicense', $user, data: [
                'license_id' => $license->id,
                'starts_at' => now()->toDateTimeString(),
                'ends_at' => null,
                'status' => 'active',
            ])
            ->assertNotified('License added successfully');

        $userLicense = UserLicense::where('user_id', $user->id)
            ->where('license_id', $license->id)
            ->first();

        expect($userLicense)->not->toBeNull();
        expect($userLicense->source)->toBe('manual');
        expect($userLicense->status)->toBe('active');
    });

    it('prevents duplicate license assignment', function () {
        $user = User::factory()->create();
        $license = License::factory()->create(['active' => true]);

        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
        ]);

        Livewire::test(ListUsers::class)
            ->callTableAction('addLicense', $user, data: [
                'license_id' => $license->id,
                'starts_at' => now()->toDateTimeString(),
                'ends_at' => null,
                'status' => 'active',
            ])
            ->assertNotified('User already has this license');

        expect(UserLicense::where('user_id', $user->id)
            ->where('license_id', $license->id)
            ->count())->toBe(1);
    });

    it('stores status correctly', function (string $status) {
        $user = User::factory()->create();
        $license = License::factory()->create(['active' => true]);

        Livewire::test(ListUsers::class)
            ->callTableAction('addLicense', $user, data: [
                'license_id' => $license->id,
                'starts_at' => now()->toDateTimeString(),
                'ends_at' => null,
                'status' => $status,
            ])
            ->assertNotified('License added successfully');

        $userLicense = UserLicense::where('user_id', $user->id)->first();
        expect($userLicense->status)->toBe($status);
    })->with(['active', 'inactive', 'trial']);

    it('stores dates correctly', function () {
        $user = User::factory()->create();
        $license = License::factory()->create(['active' => true]);

        $startsAt = now()->toDateTimeString();
        $endsAt = now()->addYear()->toDateTimeString();

        Livewire::test(ListUsers::class)
            ->callTableAction('addLicense', $user, data: [
                'license_id' => $license->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => 'active',
            ]);

        $userLicense = UserLicense::where('user_id', $user->id)->first();
        expect($userLicense->starts_at)->not->toBeNull();
        expect($userLicense->ends_at)->not->toBeNull();
    });
});
