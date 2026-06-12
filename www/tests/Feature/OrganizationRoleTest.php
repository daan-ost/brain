<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('slaat de rol op als enum via pivot cast', function () {
    $user = User::factory()->create();
    $org  = Organization::factory()->create();

    $org->users()->attach($user->id, [
        'role'      => OrganizationRole::Owner->value,
        'joined_at' => now(),
    ]);

    $pivot = $org->users()->where('user_id', $user->id)->first()->pivot;

    expect($pivot->role)->toBeInstanceOf(OrganizationRole::class)
        ->and($pivot->role)->toBe(OrganizationRole::Owner);
});

it('cast alle enum waarden correct', function (OrganizationRole $role) {
    $user = User::factory()->create();
    $org  = Organization::factory()->create();

    $org->users()->attach($user->id, [
        'role'      => $role->value,
        'joined_at' => now(),
    ]);

    $pivot = $org->users()->where('user_id', $user->id)->first()->pivot;

    expect($pivot->role)->toBe($role);
})->with([
    OrganizationRole::Owner,
    OrganizationRole::Editor,
    OrganizationRole::Reviewer,
    OrganizationRole::Viewer,
]);

it('hasOrganizationRole geeft true terug bij de juiste rol', function () {
    $user = User::factory()->create();
    $org  = Organization::factory()->create();

    $org->users()->attach($user->id, [
        'role'      => OrganizationRole::Editor->value,
        'joined_at' => now(),
    ]);

    expect($user->hasOrganizationRole($org, OrganizationRole::Editor))->toBeTrue()
        ->and($user->hasOrganizationRole($org, OrganizationRole::Owner))->toBeFalse()
        ->and($user->hasOrganizationRole($org, OrganizationRole::Reviewer))->toBeFalse();
});

it('hasOrganizationRole geeft false terug als user geen lid is', function () {
    $user = User::factory()->create();
    $org  = Organization::factory()->create();

    expect($user->hasOrganizationRole($org, OrganizationRole::Editor))->toBeFalse();
});

it('OrganizationRole::Owner heeft canManage true', function () {
    expect(OrganizationRole::Owner->canManage())->toBeTrue();
});

it('overige rollen hebben canManage false', function (OrganizationRole $role) {
    expect($role->canManage())->toBeFalse();
})->with([
    OrganizationRole::Editor,
    OrganizationRole::Reviewer,
    OrganizationRole::Viewer,
]);

it('label() geeft de juiste string terug', function (OrganizationRole $role, string $expected) {
    expect($role->label())->toBe($expected);
})->with([
    [OrganizationRole::Owner,    'Admin'],
    [OrganizationRole::Editor,   'Editor'],
    [OrganizationRole::Reviewer, 'Reviewer'],
    [OrganizationRole::Viewer,   'Viewer'],
]);
