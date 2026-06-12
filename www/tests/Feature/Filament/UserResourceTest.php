<?php

declare(strict_types=1);

use App\Filament\Resources\UserResource;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin, 'admin');
});

describe('UserResource::Authorization', function () {
    it('denies non-admin access to list page', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $this->actingAs($regularUser, 'admin');

        $this->get(UserResource::getUrl('index'))
            ->assertForbidden();
    });

    it('denies non-admin access to create page', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $this->actingAs($regularUser, 'admin');

        $this->get(UserResource::getUrl('create'))
            ->assertForbidden();
    });

    it('denies non-admin access to edit page', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $targetUser = User::factory()->create();
        $this->actingAs($regularUser, 'admin');

        $this->get(UserResource::getUrl('edit', ['record' => $targetUser]))
            ->assertForbidden();
    });

    it('denies non-admin access to view page', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $targetUser = User::factory()->create();
        $this->actingAs($regularUser, 'admin');

        $this->get(UserResource::getUrl('view', ['record' => $targetUser]))
            ->assertForbidden();
    });

    it('allows admin access to create page', function () {
        $response = $this->get(UserResource::getUrl('create'));

        // Check not forbidden or redirected
        expect($response->status())->not->toBe(403);
        expect($response->status())->not->toBe(302);
    });

    it('allows admin access to edit page', function () {
        $targetUser = User::factory()->create();

        $response = $this->get(UserResource::getUrl('edit', ['record' => $targetUser]));

        expect($response->status())->not->toBe(403);
        expect($response->status())->not->toBe(302);
    });

    it('allows admin access to view page', function () {
        $targetUser = User::factory()->create();

        $response = $this->get(UserResource::getUrl('view', ['record' => $targetUser]));

        expect($response->status())->not->toBe(403);
        expect($response->status())->not->toBe(302);
    });
});

describe('UserResource::Routes', function () {
    it('has correct index route', function () {
        expect(UserResource::getUrl('index'))->toContain('/beheer/users');
    });

    it('has correct create route', function () {
        expect(UserResource::getUrl('create'))->toContain('/beheer/users/create');
    });

    it('has correct edit route', function () {
        $user = User::factory()->create();
        expect(UserResource::getUrl('edit', ['record' => $user]))->toContain("/beheer/users/{$user->id}/edit");
    });

    it('has correct view route', function () {
        $user = User::factory()->create();
        expect(UserResource::getUrl('view', ['record' => $user]))->toContain("/beheer/users/{$user->id}");
    });
});

describe('UserResource::Model', function () {
    it('uses User model', function () {
        expect(UserResource::getModel())->toBe(User::class);
    });

    it('has navigation icon', function () {
        expect(UserResource::getNavigationIcon())->toBe('heroicon-o-users');
    });

    it('belongs to Users & Organizations navigation group', function () {
        expect(UserResource::getNavigationGroup())->toBe('Users & Organizations');
    });
});

describe('UserResource::Pages', function () {
    it('has list page', function () {
        $pages = UserResource::getPages();
        expect($pages)->toHaveKey('index');
    });

    it('has create page', function () {
        $pages = UserResource::getPages();
        expect($pages)->toHaveKey('create');
    });

    it('has edit page', function () {
        $pages = UserResource::getPages();
        expect($pages)->toHaveKey('edit');
    });

    it('has view page', function () {
        $pages = UserResource::getPages();
        expect($pages)->toHaveKey('view');
    });
});

describe('UserResource::RelationManagers', function () {
    it('has organization relation manager', function () {
        $relations = UserResource::getRelations();
        $relationClasses = array_map(fn ($r) => is_string($r) ? $r : get_class($r), $relations);

        expect($relationClasses)->toContain(\App\Filament\Resources\UserResource\RelationManagers\OrganizationsRelationManager::class);
    });

    it('has user licenses relation manager', function () {
        $relations = UserResource::getRelations();
        $relationClasses = array_map(fn ($r) => is_string($r) ? $r : get_class($r), $relations);

        expect($relationClasses)->toContain(\App\Filament\Resources\UserResource\RelationManagers\UserLicensesRelationManager::class);
    });

    it('has orders relation manager', function () {
        $relations = UserResource::getRelations();
        $relationClasses = array_map(fn ($r) => is_string($r) ? $r : get_class($r), $relations);

        expect($relationClasses)->toContain(\App\Filament\Resources\UserResource\RelationManagers\OrdersRelationManager::class);
    });

    it('has credit ledger relation manager', function () {
        $relations = UserResource::getRelations();
        $relationClasses = array_map(fn ($r) => is_string($r) ? $r : get_class($r), $relations);

        expect($relationClasses)->toContain(\App\Filament\Resources\UserResource\RelationManagers\CreditLedgerRelationManager::class);
    });

    it('has analytics events relation manager', function () {
        $relations = UserResource::getRelations();
        $relationClasses = array_map(fn ($r) => is_string($r) ? $r : get_class($r), $relations);

        expect($relationClasses)->toContain(\App\Filament\Resources\UserResource\RelationManagers\AnalyticsEventsRelationManager::class);
    });

    it('has message threads relation manager', function () {
        $relations = UserResource::getRelations();
        $relationClasses = array_map(fn ($r) => is_string($r) ? $r : get_class($r), $relations);

        expect($relationClasses)->toContain(\App\Filament\Resources\UserResource\RelationManagers\MessageThreadsRelationManager::class);
    });
});
