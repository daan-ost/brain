<?php

use App\Models\User;

it('returns 404 when feature flag is off', function () {
    config(['features.demo_crud' => false]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/demo-items')
        ->assertNotFound();
});

it('returns 200 when feature flag is on', function () {
    config(['features.demo_crud' => true]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/demo-items')
        ->assertOk();
});
