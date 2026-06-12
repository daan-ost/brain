<?php

use App\Enums\OrganizationRole;
use App\Http\Middleware\CheckOrganizationAdmin;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Route;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// Registreer een test-route met de middleware vóór iedere test.
beforeEach(function () {
    Route::get('/_test/org-admin', fn () => response('ok', 200))
        ->middleware(['web', 'auth', CheckOrganizationAdmin::class])
        ->name('_test.org-admin');
});

it('niet-ingelogde gebruiker wordt doorgestuurd naar login', function () {
    $this->get('/_test/org-admin')
        ->assertRedirect(route('login'));
});

it('ingelogde gebruiker zonder organisatie wordt geblokkeerd', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/_test/org-admin')
        ->assertRedirect(route('profile.account'))
        ->assertSessionHas('error');
});

it('Owner heeft toegang', function () {
    $owner = User::factory()->create();
    $org   = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => OrganizationRole::Owner->value, 'joined_at' => now()]);

    $this->actingAs($owner)
        ->get('/_test/org-admin')
        ->assertOk();
});

it('Editor wordt geblokkeerd door de middleware', function () {
    $editor = User::factory()->create();
    $org    = Organization::factory()->create();
    $org->users()->attach($editor->id, ['role' => OrganizationRole::Editor->value, 'joined_at' => now()]);

    $this->actingAs($editor)
        ->get('/_test/org-admin')
        ->assertRedirect(route('profile.account'))
        ->assertSessionHas('error');
});

it('Reviewer wordt geblokkeerd door de middleware', function () {
    $reviewer = User::factory()->create();
    $org      = Organization::factory()->create();
    $org->users()->attach($reviewer->id, ['role' => OrganizationRole::Reviewer->value, 'joined_at' => now()]);

    $this->actingAs($reviewer)
        ->get('/_test/org-admin')
        ->assertRedirect(route('profile.account'))
        ->assertSessionHas('error');
});

it('Viewer wordt geblokkeerd door de middleware', function () {
    $viewer = User::factory()->create();
    $org    = Organization::factory()->create();
    $org->users()->attach($viewer->id, ['role' => OrganizationRole::Viewer->value, 'joined_at' => now()]);

    $this->actingAs($viewer)
        ->get('/_test/org-admin')
        ->assertRedirect(route('profile.account'))
        ->assertSessionHas('error');
});
