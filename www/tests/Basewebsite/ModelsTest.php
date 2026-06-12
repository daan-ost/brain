<?php

/**
 * Basewebsite Propagation Smoke Tests — Shared Models & Factories
 *
 * Verifies that basewebsite models and their factories work correctly.
 * If a propagated migration or model change breaks something, these tests catch it.
 */

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\License;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Models\UserLicense;

describe('User Model', function () {
    it('can create user via factory', function () {
        $user = User::factory()->create();
        expect($user->id)->not->toBeNull();
        expect($user->email)->not->toBeNull();
    });

    it('supports soft deletes', function () {
        if (! in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(User::class))) {
            $this->markTestSkipped('User model does not use SoftDeletes');
        }

        $user = User::factory()->create();
        $user->delete();

        expect($user->trashed())->toBeTrue();
        expect(User::withTrashed()->find($user->id))->not->toBeNull();
    });

    it('has organization relationship', function () {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        expect($user->organizations)->toHaveCount(1);
        expect($user->organizations->first()->id)->toBe($org->id);
    });
});

describe('Organization Model', function () {
    it('can create organization via factory', function () {
        $org = Organization::factory()->create();
        expect($org->id)->not->toBeNull();
        expect($org->name)->not->toBeNull();
        expect($org->slug)->not->toBeNull();
    });

    it('auto-generates slug from name when slug not provided', function () {
        $org = Organization::factory()->create(['name' => 'My Test Org', 'slug' => null]);
        expect($org->slug)->toBe('my-test-org');
    });

    it('supports soft deletes', function () {
        if (! in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(Organization::class))) {
            $this->markTestSkipped('Organization model does not use SoftDeletes');
        }

        $org = Organization::factory()->create();
        $org->delete();
        expect($org->trashed())->toBeTrue();
    });

    it('has users relationship', function () {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $org->users()->attach($user, ['role' => \App\Enums\OrganizationRole::Owner->value, 'joined_at' => now()]);

        expect($org->users)->toHaveCount(1);
    });
});

describe('Order Model', function () {
    it('can create order via factory', function () {
        $order = Order::factory()->create();
        expect($order->id)->not->toBeNull();
        expect($order->status)->toBeInstanceOf(OrderStatus::class);
    });

    it('casts status to enum', function () {
        $order = Order::factory()->create(['status' => OrderStatus::Paid]);
        $order->refresh();
        expect($order->status)->toBe(OrderStatus::Paid);
    });
});

describe('License Model', function () {
    it('can create license via factory', function () {
        $license = License::factory()->create();
        expect($license->id)->not->toBeNull();
        expect($license->name)->not->toBeNull();
    });
});

describe('User License', function () {
    it('can create user license via factory', function () {
        $userLicense = UserLicense::factory()->create();
        expect($userLicense->user)->not->toBeNull();
        expect($userLicense->license)->not->toBeNull();
    });
});

describe('Organization License', function () {
    it('can create organization license via factory', function () {
        $orgLicense = OrganizationLicense::factory()->create();
        expect($orgLicense->organization)->not->toBeNull();
        expect($orgLicense->license)->not->toBeNull();
    });
});

describe('Enums', function () {
    it('OrderStatus has all required cases', function () {
        expect(OrderStatus::Paid->value)->toBe('paid');
        expect(OrderStatus::Pending->value)->toBe('pending');
        expect(OrderStatus::Failed->value)->toBe('failed');
        expect(OrderStatus::Canceled->value)->toBe('canceled');
    });

    it('OrderStatus has label method', function () {
        expect(OrderStatus::Paid->label())->toBeString();
        expect(OrderStatus::Pending->label())->toBeString();
    });
});
