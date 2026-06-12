<?php

/**
 * Basewebsite Propagation Smoke Tests — Application Boot
 *
 * Verifies that propagated basewebsite code doesn't break the child site.
 * These tests should pass on ALL child sites that inherit from basewebsite.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

describe('Application Boot', function () {
    it('boots without errors', function () {
        expect(app())->not->toBeNull();
    });

    it('can list all routes without errors', function () {
        Artisan::call('route:list', ['--json' => true]);

        $routes = json_decode(Artisan::output(), true);
        expect($routes)->toBeArray();
        expect(count($routes))->toBeGreaterThan(10);
    });

    it('has a working database connection', function () {
        $result = \Illuminate\Support\Facades\DB::select('SELECT 1 as ok');
        expect($result[0]->ok)->toBe(1);
    });
});

describe('Key Routes Exist', function () {
    it('has auth routes', function () {
        $this->get('/login')->assertStatus(200);
        $this->get('/register')->assertStatus(200);
        $this->get('/forgot-password')->assertStatus(200);
    });

    it('has admin panel route', function () {
        $this->get('/beheer/login')->assertStatus(200);
    });

    it('has webhook endpoints', function () {
        // Webhooks should accept POST — route exists if not 404/405
        $response = $this->post('/webhooks/postmark', []);
        expect($response->status())->not->toBe(404);
        expect($response->status())->not->toBe(405);
    });

    it('redirects unauthenticated dashboard to login', function () {
        $this->get('/dashboard')->assertRedirect('/login');
    });

    it('redirects unauthenticated profile to login', function () {
        $this->get('/profile/account')->assertRedirect('/login');
    });
});
