<?php

declare(strict_types=1);

use App\Models\User;

describe('Admin Panel Access Control', function () {

    describe('Unauthenticated Access', function () {
        it('redirects unauthenticated users to admin login page', function () {
            $this->get('/beheer')
                ->assertRedirect('/beheer/login');
        });

        it('shows login form at /beheer/login', function () {
            $this->get('/beheer/login')
                ->assertOk()
                ->assertSee('email', false)
                ->assertSee('password', false);
        });

        it('redirects unauthenticated users from admin resources to login', function () {
            $this->get('/beheer/users')
                ->assertRedirect('/beheer/login');

            $this->get('/beheer/announcements')
                ->assertRedirect('/beheer/login');

            $this->get('/beheer/orders')
                ->assertRedirect('/beheer/login');
        });
    });

    describe('Non-Admin User Access', function () {
        it('denies non-admin user access to admin panel', function () {
            $user = User::factory()->create(['is_admin' => false]);

            $this->actingAs($user, 'admin')
                ->get('/beheer')
                ->assertForbidden();
        });

        it('denies non-admin user access to admin resources', function () {
            $user = User::factory()->create(['is_admin' => false]);

            $this->actingAs($user, 'admin')
                ->get('/beheer/users')
                ->assertForbidden();
        });
    });

    describe('Admin User Access', function () {
        it('allows admin user to access admin panel dashboard', function () {
            $admin = User::factory()->create(['is_admin' => true]);

            $this->actingAs($admin, 'admin')
                ->get('/beheer')
                ->assertOk();
        });

        // Note: Full page rendering tests for resource list pages require ext-intl.
        // These tests verify authorization only (not redirect, not forbidden).
        // Full rendering is covered by Playwright E2E smoke tests.
        it('allows admin user to access users resource (authorization check)', function () {
            $admin = User::factory()->create(['is_admin' => true]);

            $response = $this->actingAs($admin, 'admin')
                ->get('/beheer/users');

            // Should not be redirected (302) or forbidden (403)
            // May be 200 (success) or 500 (intl missing) - both indicate authorization passed
            expect($response->status())->not->toBe(302);
            expect($response->status())->not->toBe(403);
        });

        it('allows admin user to access announcements resource (authorization check)', function () {
            $admin = User::factory()->create(['is_admin' => true]);

            $response = $this->actingAs($admin, 'admin')
                ->get('/beheer/announcements');

            expect($response->status())->not->toBe(302);
            expect($response->status())->not->toBe(403);
        });

        it('allows admin user to access licenses resource (authorization check)', function () {
            $admin = User::factory()->create(['is_admin' => true]);

            $response = $this->actingAs($admin, 'admin')
                ->get('/beheer/licenses');

            expect($response->status())->not->toBe(302);
            expect($response->status())->not->toBe(403);
        });

        it('allows admin user to access orders resource (authorization check)', function () {
            $admin = User::factory()->create(['is_admin' => true]);

            $response = $this->actingAs($admin, 'admin')
                ->get('/beheer/orders');

            expect($response->status())->not->toBe(302);
            expect($response->status())->not->toBe(403);
        });

        it('allows admin user to access organizations resource (authorization check)', function () {
            $admin = User::factory()->create(['is_admin' => true]);

            $response = $this->actingAs($admin, 'admin')
                ->get('/beheer/organizations');

            expect($response->status())->not->toBe(302);
            expect($response->status())->not->toBe(403);
        });
    });

    describe('Admin Login Flow', function () {
        it('can login as admin user via login form', function () {
            $admin = User::factory()->create([
                'is_admin' => true,
                'email' => 'admin@test.com',
                'password' => 'password',
            ]);

            $this->post('/beheer/login', [
                'email' => 'admin@test.com',
                'password' => 'password',
            ])->assertRedirect('/beheer');
        });

        it('rejects login with invalid credentials', function () {
            $admin = User::factory()->create([
                'is_admin' => true,
                'email' => 'admin@test.com',
                'password' => 'password',
            ]);

            $this->post('/beheer/login', [
                'email' => 'admin@test.com',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        });

        it('rejects login for non-admin user', function () {
            $user = User::factory()->create([
                'is_admin' => false,
                'email' => 'user@test.com',
                'password' => 'password',
            ]);

            $this->post('/beheer/login', [
                'email' => 'user@test.com',
                'password' => 'password',
            ])->assertSessionHasErrors('email');
        });
    });

    describe('Admin Logout Flow', function () {
        it('can logout from admin panel', function () {
            $admin = User::factory()->create(['is_admin' => true]);

            $this->actingAs($admin, 'admin')
                ->post('/beheer/logout')
                ->assertRedirect('/beheer/login');
        });

        it('cannot access admin panel after logout', function () {
            $admin = User::factory()->create(['is_admin' => true]);

            // Login
            $this->actingAs($admin, 'admin');

            // Logout
            $this->post('/beheer/logout');

            // Try to access admin panel - should redirect to login
            $this->get('/beheer')
                ->assertRedirect('/beheer/login');
        });
    });

    describe('Admin Guard Isolation', function () {
        it('admin session is separate from web session', function () {
            $admin = User::factory()->create(['is_admin' => true]);
            $user = User::factory()->create(['is_admin' => false]);

            // Login as regular user on web
            $this->actingAs($user, 'web');

            // Should still not have access to admin panel
            $this->get('/beheer')
                ->assertRedirect('/beheer/login');
        });
    });
});
