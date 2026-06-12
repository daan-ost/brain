<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Policies\InvoicePolicy;

beforeEach(function () {
    $this->policy = new InvoicePolicy;
});

describe('InvoicePolicy::viewAny', function () {
    it('allows any authenticated user to view invoice list', function () {
        $user = User::factory()->create();

        expect($this->policy->viewAny($user))->toBeTrue();
    });

    it('allows admin user to view invoice list', function () {
        $admin = User::factory()->create(['is_admin' => true]);

        expect($this->policy->viewAny($admin))->toBeTrue();
    });
});

describe('InvoicePolicy::download - User Orders', function () {
    it('allows payer user to download their own invoice', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
        ]);

        expect($this->policy->download($user, $order))->toBeTrue();
    });

    it('denies user from downloading another users invoice', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $otherUser->id,
        ]);

        expect($this->policy->download($user, $order))->toBeFalse();
    });

    it('denies user from downloading invoice when payer_id does not match', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id + 999,
        ]);

        expect($this->policy->download($user, $order))->toBeFalse();
    });
});

describe('InvoicePolicy::download - Organization Orders', function () {
    it('allows organization admin to download organization invoice', function () {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        // Attach user as admin of organization
        $organization->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value]);

        $order = Order::factory()->create([
            'payer_type' => 'organization',
            'payer_id' => $organization->id,
        ]);

        expect($this->policy->download($user, $order))->toBeTrue();
    });

    it('denies organization member (non-admin) from downloading organization invoice', function () {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        // Attach user as member (not admin) of organization
        $organization->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Editor->value]);

        $order = Order::factory()->create([
            'payer_type' => 'organization',
            'payer_id' => $organization->id,
        ]);

        expect($this->policy->download($user, $order))->toBeFalse();
    });

    it('denies user not in organization from downloading organization invoice', function () {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        // User is NOT attached to organization

        $order = Order::factory()->create([
            'payer_type' => 'organization',
            'payer_id' => $organization->id,
        ]);

        expect($this->policy->download($user, $order))->toBeFalse();
    });

    it('denies user when organization does not exist', function () {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'payer_type' => 'organization',
            'payer_id' => 99999, // Non-existent organization
        ]);

        expect($this->policy->download($user, $order))->toBeFalse();
    });
});

describe('InvoicePolicy::download - Edge Cases', function () {
    it('denies download for unknown payer type', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
        ]);

        // Manually set unknown type after creation to bypass DB constraints
        $order->payer_type = 'unknown_type';

        expect($this->policy->download($user, $order))->toBeFalse();
    });

    it('handles empty payer_type gracefully', function () {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'payer_type' => 'user',
            'payer_id' => $user->id,
        ]);

        // Manually set empty type after creation to bypass DB constraints
        $order->payer_type = '';

        expect($this->policy->download($user, $order))->toBeFalse();
    });
});
