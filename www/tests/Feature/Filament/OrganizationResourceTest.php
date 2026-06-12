<?php

declare(strict_types=1);

use App\Filament\Resources\OrganizationResource;
use App\Models\Organization;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin, 'admin');
});

describe('OrganizationResource::Authorization', function () {
    it('denies non-admin access to list page', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $this->actingAs($regularUser, 'admin');

        $this->get(OrganizationResource::getUrl('index'))
            ->assertForbidden();
    });

    it('denies non-admin access to create page', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $this->actingAs($regularUser, 'admin');

        $this->get(OrganizationResource::getUrl('create'))
            ->assertForbidden();
    });

    it('denies non-admin access to edit page', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $organization = Organization::factory()->create();
        $this->actingAs($regularUser, 'admin');

        $this->get(OrganizationResource::getUrl('edit', ['record' => $organization]))
            ->assertForbidden();
    });

    it('denies non-admin access to view page', function () {
        $regularUser = User::factory()->create(['is_admin' => false]);
        $organization = Organization::factory()->create();
        $this->actingAs($regularUser, 'admin');

        $this->get(OrganizationResource::getUrl('view', ['record' => $organization]))
            ->assertForbidden();
    });

    it('allows admin access to create page', function () {
        $response = $this->get(OrganizationResource::getUrl('create'));

        expect($response->status())->not->toBe(403);
        expect($response->status())->not->toBe(302);
    });

    it('allows admin access to edit page', function () {
        $organization = Organization::factory()->create();

        $response = $this->get(OrganizationResource::getUrl('edit', ['record' => $organization]));

        expect($response->status())->not->toBe(403);
        expect($response->status())->not->toBe(302);
    });

    it('allows admin access to view page', function () {
        $organization = Organization::factory()->create();

        $response = $this->get(OrganizationResource::getUrl('view', ['record' => $organization]));

        expect($response->status())->not->toBe(403);
        expect($response->status())->not->toBe(302);
    });
});

describe('OrganizationResource::Routes', function () {
    it('has correct index route', function () {
        expect(OrganizationResource::getUrl('index'))->toContain('/beheer/organizations');
    });

    it('has correct create route', function () {
        expect(OrganizationResource::getUrl('create'))->toContain('/beheer/organizations/create');
    });

    it('has correct edit route', function () {
        $organization = Organization::factory()->create();
        expect(OrganizationResource::getUrl('edit', ['record' => $organization]))->toContain("/beheer/organizations/{$organization->id}/edit");
    });

    it('has correct view route', function () {
        $organization = Organization::factory()->create();
        expect(OrganizationResource::getUrl('view', ['record' => $organization]))->toContain("/beheer/organizations/{$organization->id}");
    });
});

describe('OrganizationResource::Model', function () {
    it('uses Organization model', function () {
        expect(OrganizationResource::getModel())->toBe(Organization::class);
    });

    it('has navigation icon', function () {
        expect(OrganizationResource::getNavigationIcon())->toBe('heroicon-o-building-office');
    });

    it('belongs to Users & Organizations navigation group', function () {
        expect(OrganizationResource::getNavigationGroup())->toBe('Users & Organizations');
    });
});

describe('OrganizationResource::Pages', function () {
    it('has list page', function () {
        $pages = OrganizationResource::getPages();
        expect($pages)->toHaveKey('index');
    });

    it('has create page', function () {
        $pages = OrganizationResource::getPages();
        expect($pages)->toHaveKey('create');
    });

    it('has edit page', function () {
        $pages = OrganizationResource::getPages();
        expect($pages)->toHaveKey('edit');
    });

    it('has view page', function () {
        $pages = OrganizationResource::getPages();
        expect($pages)->toHaveKey('view');
    });
});

describe('OrganizationResource::OrganizationAttributes', function () {
    it('can have name', function () {
        $organization = Organization::factory()->create(['name' => 'Test Organization']);
        expect($organization->name)->toBe('Test Organization');
    });

    it('can have slug', function () {
        $organization = Organization::factory()->create(['slug' => 'test-org']);
        expect($organization->slug)->toBe('test-org');
    });

    it('can be trusted', function () {
        $organization = Organization::factory()->create(['is_trusted' => true]);
        expect($organization->is_trusted)->toBeTrue();
    });

    it('can be untrusted', function () {
        $organization = Organization::factory()->create(['is_trusted' => false]);
        expect($organization->is_trusted)->toBeFalse();
    });
});

describe('OrganizationResource::BillingInfo', function () {
    it('can have billing country code NL', function () {
        $organization = Organization::factory()->create(['billing_country_code' => 'NL']);
        expect($organization->billing_country_code)->toBe('NL');
    });

    it('can have billing country code DE', function () {
        $organization = Organization::factory()->create(['billing_country_code' => 'DE']);
        expect($organization->billing_country_code)->toBe('DE');
    });

    it('can have EUR currency preference', function () {
        $organization = Organization::factory()->create(['currency_preference' => 'EUR']);
        expect($organization->currency_preference)->toBe('EUR');
    });

    it('can have USD currency preference', function () {
        $organization = Organization::factory()->create(['currency_preference' => 'USD']);
        expect($organization->currency_preference)->toBe('USD');
    });

    it('can have VAT number', function () {
        $organization = Organization::factory()->create(['vat_number' => 'NL123456789B01']);
        expect($organization->vat_number)->toBe('NL123456789B01');
    });

    it('can have validated VAT', function () {
        $date = now();
        $organization = Organization::factory()->create([
            'vat_number' => 'NL123456789B01',
            'vat_validated_at' => $date,
        ]);
        expect($organization->vat_validated_at)->not->toBeNull();
    });
});

describe('OrganizationResource::Memberships', function () {
    it('can have users as members', function () {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        $organization->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Editor->value]);

        expect($organization->users()->count())->toBe(1);
        expect($organization->users()->first()->id)->toBe($user->id);
    });

    it('can have users as admins', function () {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        $organization->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Owner->value]);

        $membership = $organization->users()->first()->pivot;
        expect($membership->role)->toBe(\App\Enums\OrganizationRole::Owner);
    });

    it('can have multiple members', function () {
        $organization = Organization::factory()->create();
        $users = User::factory()->count(3)->create();

        foreach ($users as $user) {
            $organization->users()->attach($user->id, ['role' => \App\Enums\OrganizationRole::Editor->value]);
        }

        expect($organization->users()->count())->toBe(3);
    });
});

describe('OrganizationResource::RelationManagers', function () {
    it('has members relation manager', function () {
        $relations = OrganizationResource::getRelations();
        $relationClasses = array_map(fn ($r) => is_string($r) ? $r : get_class($r), $relations);

        expect($relationClasses)->toContain(\App\Filament\Resources\OrganizationResource\RelationManagers\MembersRelationManager::class);
    });

    it('has domains relation manager', function () {
        $relations = OrganizationResource::getRelations();
        $relationClasses = array_map(fn ($r) => is_string($r) ? $r : get_class($r), $relations);

        expect($relationClasses)->toContain(\App\Filament\Resources\OrganizationResource\RelationManagers\DomainsRelationManager::class);
    });

    it('has organization licenses relation manager', function () {
        $relations = OrganizationResource::getRelations();
        $relationClasses = array_map(fn ($r) => is_string($r) ? $r : get_class($r), $relations);

        expect($relationClasses)->toContain(\App\Filament\Resources\OrganizationResource\RelationManagers\OrganizationLicensesRelationManager::class);
    });

    it('has orders relation manager', function () {
        $relations = OrganizationResource::getRelations();
        $relationClasses = array_map(fn ($r) => is_string($r) ? $r : get_class($r), $relations);

        expect($relationClasses)->toContain(\App\Filament\Resources\OrganizationResource\RelationManagers\OrdersRelationManager::class);
    });

    it('has credit ledger relation manager', function () {
        $relations = OrganizationResource::getRelations();
        $relationClasses = array_map(fn ($r) => is_string($r) ? $r : get_class($r), $relations);

        expect($relationClasses)->toContain(\App\Filament\Resources\OrganizationResource\RelationManagers\CreditLedgerRelationManager::class);
    });

    it('has invitations relation manager', function () {
        $relations = OrganizationResource::getRelations();
        $relationClasses = array_map(fn ($r) => is_string($r) ? $r : get_class($r), $relations);

        expect($relationClasses)->toContain(\App\Filament\Resources\OrganizationResource\RelationManagers\InvitationsRelationManager::class);
    });
});
