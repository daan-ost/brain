<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// isAdmin()
// ---------------------------------------------------------------------------

it('isAdmin geeft true terug voor Owner rol', function () {
    $owner = User::factory()->create();
    $org   = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => OrganizationRole::Owner->value, 'joined_at' => now()]);

    expect($org->isAdmin($owner))->toBeTrue();
});

it('isAdmin geeft false terug voor Editor rol', function () {
    $editor = User::factory()->create();
    $org    = Organization::factory()->create();
    $org->users()->attach($editor->id, ['role' => OrganizationRole::Editor->value, 'joined_at' => now()]);

    expect($org->isAdmin($editor))->toBeFalse();
});

it('isAdmin geeft false terug voor Reviewer rol', function () {
    $reviewer = User::factory()->create();
    $org      = Organization::factory()->create();
    $org->users()->attach($reviewer->id, ['role' => OrganizationRole::Reviewer->value, 'joined_at' => now()]);

    expect($org->isAdmin($reviewer))->toBeFalse();
});

it('isAdmin geeft false terug voor Viewer rol', function () {
    $viewer = User::factory()->create();
    $org    = Organization::factory()->create();
    $org->users()->attach($viewer->id, ['role' => OrganizationRole::Viewer->value, 'joined_at' => now()]);

    expect($org->isAdmin($viewer))->toBeFalse();
});

it('isAdmin geeft false terug als gebruiker geen lid is', function () {
    $user = User::factory()->create();
    $org  = Organization::factory()->create();

    expect($org->isAdmin($user))->toBeFalse();
});

// ---------------------------------------------------------------------------
// admins()
// ---------------------------------------------------------------------------

it('admins() geeft alleen Owner gebruikers terug', function () {
    $owner    = User::factory()->create();
    $editor   = User::factory()->create();
    $reviewer = User::factory()->create();
    $org      = Organization::factory()->create();

    $org->users()->attach($owner->id,    ['role' => OrganizationRole::Owner->value,    'joined_at' => now()]);
    $org->users()->attach($editor->id,   ['role' => OrganizationRole::Editor->value,   'joined_at' => now()]);
    $org->users()->attach($reviewer->id, ['role' => OrganizationRole::Reviewer->value, 'joined_at' => now()]);

    $admins = $org->admins()->get();

    expect($admins)->toHaveCount(1)
        ->and($admins->first()->id)->toBe($owner->id);
});

it('admins() geeft meerdere Owners terug als die er zijn', function () {
    $owner1 = User::factory()->create();
    $owner2 = User::factory()->create();
    $editor = User::factory()->create();
    $org    = Organization::factory()->create();

    $org->users()->attach($owner1->id, ['role' => OrganizationRole::Owner->value,  'joined_at' => now()]);
    $org->users()->attach($owner2->id, ['role' => OrganizationRole::Owner->value,  'joined_at' => now()]);
    $org->users()->attach($editor->id, ['role' => OrganizationRole::Editor->value, 'joined_at' => now()]);

    expect($org->admins()->count())->toBe(2);
});

it('admins() geeft lege collectie terug als er geen owners zijn', function () {
    $editor = User::factory()->create();
    $org    = Organization::factory()->create();
    $org->users()->attach($editor->id, ['role' => OrganizationRole::Editor->value, 'joined_at' => now()]);

    expect($org->admins()->count())->toBe(0);
});
