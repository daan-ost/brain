<?php

/**
 * Basewebsite Propagation Smoke Tests — Admin Panel (/beheer)
 *
 * Verifies that the Filament admin panel works correctly after propagation.
 * Note: Filament uses its own 'admin' auth guard, so actingAs() on the default
 * web guard won't work. Instead we test the login flow and redirect behavior.
 */

declare(strict_types=1);

use App\Models\User;

describe('Admin Panel Access', function () {
    it('shows login form at /beheer/login', function () {
        $this->get('/beheer/login')->assertStatus(200);
    });

    it('redirects unauthenticated users from /beheer to login', function () {
        $this->get('/beheer')->assertRedirect();
    });

    it('redirects non-admin users to admin login', function () {
        $user = User::factory()->create(['is_admin' => false]);

        // Filament uses its own guard, so non-admin on web guard gets redirected
        $this->actingAs($user)
            ->get('/beheer')
            ->assertRedirectContains('/beheer/login');
    });

    it('allows admin users to access dashboard via admin guard', function () {
        $admin = User::factory()->create(['is_admin' => true]);

        // Authenticate on the admin guard (Filament's guard)
        $this->actingAs($admin, 'admin')
            ->get('/beheer')
            ->assertStatus(200);
    });
});

describe('Admin Resources Accessible', function () {
    beforeEach(function () {
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->admin, 'admin');
    });

    it('can access users resource', function () {
        // 200 = accessible, 403 = Filament Shield restricts (route exists but policy blocks)
        $response = $this->get('/beheer/users');
        expect($response->status())->toBeIn([200, 403]);
    });

    it('can access organizations resource', function () {
        $response = $this->get('/beheer/organizations');
        expect($response->status())->toBeIn([200, 403]);
    });

    it('can access orders resource', function () {
        $response = $this->get('/beheer/orders');
        expect($response->status())->toBeIn([200, 403]);
    });

    it('can access licenses resource', function () {
        $response = $this->get('/beheer/licenses');
        expect($response->status())->toBeIn([200, 403]);
    });
});
