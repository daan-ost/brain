<?php

declare(strict_types=1);

use App\Models\User;
use App\Policies\UserPolicy;

beforeEach(function () {
    $this->policy = new UserPolicy;
});

describe('UserPolicy::viewAny', function () {
    it('allows admin users to view any users', function () {
        $admin = User::factory()->create(['is_admin' => true]);

        expect($this->policy->viewAny($admin))->toBeTrue();
    });

    it('denies non-admin users from viewing any users', function () {
        $user = User::factory()->create(['is_admin' => false]);

        expect($this->policy->viewAny($user))->toBeFalse();
    });
});

describe('UserPolicy::view', function () {
    it('allows admin to view any user', function () {
        $admin = User::factory()->create(['is_admin' => true]);
        $targetUser = User::factory()->create();

        expect($this->policy->view($admin, $targetUser))->toBeTrue();
    });

    it('allows user to view their own profile', function () {
        $user = User::factory()->create(['is_admin' => false]);

        expect($this->policy->view($user, $user))->toBeTrue();
    });

    it('denies non-admin user from viewing other users', function () {
        $user = User::factory()->create(['is_admin' => false]);
        $otherUser = User::factory()->create();

        expect($this->policy->view($user, $otherUser))->toBeFalse();
    });
});

describe('UserPolicy::create', function () {
    it('allows admin to create users', function () {
        $admin = User::factory()->create(['is_admin' => true]);

        expect($this->policy->create($admin))->toBeTrue();
    });

    it('denies non-admin from creating users', function () {
        $user = User::factory()->create(['is_admin' => false]);

        expect($this->policy->create($user))->toBeFalse();
    });
});

describe('UserPolicy::update', function () {
    it('allows admin to update any user', function () {
        $admin = User::factory()->create(['is_admin' => true]);
        $targetUser = User::factory()->create();

        expect($this->policy->update($admin, $targetUser))->toBeTrue();
    });

    it('allows user to update their own profile', function () {
        $user = User::factory()->create(['is_admin' => false]);

        expect($this->policy->update($user, $user))->toBeTrue();
    });

    it('denies non-admin from updating other users', function () {
        $user = User::factory()->create(['is_admin' => false]);
        $otherUser = User::factory()->create();

        expect($this->policy->update($user, $otherUser))->toBeFalse();
    });
});

describe('UserPolicy::delete', function () {
    it('allows admin to delete other users', function () {
        $admin = User::factory()->create(['is_admin' => true]);
        $targetUser = User::factory()->create();

        expect($this->policy->delete($admin, $targetUser))->toBeTrue();
    });

    it('prevents admin from deleting themselves', function () {
        $admin = User::factory()->create(['is_admin' => true]);

        expect($this->policy->delete($admin, $admin))->toBeFalse();
    });

    it('denies non-admin from deleting users', function () {
        $user = User::factory()->create(['is_admin' => false]);
        $otherUser = User::factory()->create();

        expect($this->policy->delete($user, $otherUser))->toBeFalse();
    });

});

describe('UserPolicy::restore', function () {
    it('allows admin to restore users', function () {
        $admin = User::factory()->create(['is_admin' => true]);
        $deletedUser = User::factory()->create();

        expect($this->policy->restore($admin, $deletedUser))->toBeTrue();
    });

    it('denies non-admin from restoring users', function () {
        $user = User::factory()->create(['is_admin' => false]);
        $deletedUser = User::factory()->create();

        expect($this->policy->restore($user, $deletedUser))->toBeFalse();
    });
});

describe('UserPolicy::forceDelete', function () {
    it('allows admin to force delete other users', function () {
        $admin = User::factory()->create(['is_admin' => true]);
        $targetUser = User::factory()->create();

        expect($this->policy->forceDelete($admin, $targetUser))->toBeTrue();
    });

    it('prevents admin from force deleting themselves', function () {
        $admin = User::factory()->create(['is_admin' => true]);

        expect($this->policy->forceDelete($admin, $admin))->toBeFalse();
    });

    it('denies non-admin from force deleting users', function () {
        $user = User::factory()->create(['is_admin' => false]);
        $otherUser = User::factory()->create();

        expect($this->policy->forceDelete($user, $otherUser))->toBeFalse();
    });
});

describe('UserPolicy::export', function () {
    it('allows admin to export user data', function () {
        $admin = User::factory()->create(['is_admin' => true]);

        expect($this->policy->export($admin))->toBeTrue();
    });

    it('denies non-admin from exporting user data', function () {
        $user = User::factory()->create(['is_admin' => false]);

        expect($this->policy->export($user))->toBeFalse();
    });
});

describe('UserPolicy::bulkUpdate', function () {
    it('allows admin to perform bulk updates', function () {
        $admin = User::factory()->create(['is_admin' => true]);

        expect($this->policy->bulkUpdate($admin))->toBeTrue();
    });

    it('denies non-admin from performing bulk updates', function () {
        $user = User::factory()->create(['is_admin' => false]);

        expect($this->policy->bulkUpdate($user))->toBeFalse();
    });
});
