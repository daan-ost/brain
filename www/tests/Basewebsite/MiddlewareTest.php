<?php

/**
 * Basewebsite Propagation Smoke Tests — Middleware
 *
 * Verifies that shared middleware works correctly after propagation.
 */

declare(strict_types=1);

use App\Models\User;

describe('Authentication Middleware', function () {
    it('redirects guests to login for protected routes', function () {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/profile/account')->assertRedirect('/login');
        $this->get('/profile/organization')->assertRedirect('/login');
    });

    it('allows authenticated users through', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');
        // Should not redirect to login (302 to /login) — 200 or redirect to another authenticated page is fine
        expect($response->status())->toBeIn([200, 302]);
        if ($response->status() === 302) {
            expect($response->headers->get('Location'))->not->toContain('/login');
        }
    });
});

describe('Security Headers Middleware', function () {
    it('adds security headers to responses', function () {
        $response = $this->get('/login');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options');
    });
});

describe('Locale Middleware', function () {
    it('respects user preferred language', function () {
        $user = User::factory()->create([
            'preferred_language' => 'nl',
        ]);

        $this->actingAs($user)
            ->get('/dashboard');

        expect(app()->getLocale())->toBe('nl');
    });
});

describe('Admin Guard', function () {
    it('admin guard redirects non-admin users', function () {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get('/beheer')
            ->assertRedirectContains('/beheer/login');
    });

    it('admin guard accepts admin users', function () {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin, 'admin')
            ->get('/beheer')
            ->assertStatus(200);
    });
});
