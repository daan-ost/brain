<?php

use App\Http\Middleware\ApiV1RestrictNewUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->middleware = new ApiV1RestrictNewUsers;
});

it('allows all users when go-live date is not configured', function () {
    Config::set('api.v1_go_live_date', null);

    $user = User::factory()->create();

    $request = Request::create('/api/v1/sessions', 'POST');
    $request->setUserResolver(function () use ($user) {
        return $user;
    });

    $response = $this->middleware->handle($request, function ($req) {
        return response()->json(['success' => true]);
    });

    expect($response->getStatusCode())->toBe(200);
});

it('allows users registered before go-live date', function () {
    // Set go-live date to January 1, 2025
    Config::set('api.v1_go_live_date', '2025-01-01');

    // Create user registered before go-live date
    $user = User::factory()->create([
        'created_at' => now()->parse('2024-12-01'),
    ]);

    $request = Request::create('/api/v1/sessions', 'POST');
    $request->setUserResolver(function () use ($user) {
        return $user;
    });

    $response = $this->middleware->handle($request, function ($req) {
        return response()->json(['success' => true]);
    });

    expect($response->getStatusCode())->toBe(200);
});

it('blocks users registered after go-live date', function () {
    // Set go-live date to January 1, 2025
    Config::set('api.v1_go_live_date', '2025-01-01');

    // Create user registered after go-live date
    $user = User::factory()->create([
        'created_at' => now()->parse('2025-02-01'),
    ]);

    $request = Request::create('/api/v1/sessions', 'POST');
    $request->setUserResolver(function () use ($user) {
        return $user;
    });

    $response = $this->middleware->handle($request, function ($req) {
        return response()->json(['success' => true]);
    });

    expect($response->getStatusCode())->toBe(403);
    $data = json_decode($response->getContent(), true);
    expect($data['error'])->toBe('API v1 is not available for new users. Please use API v2.');
    expect($data['message'])->toContain('cutoff date');
    expect($data)->toHaveKey('cutoff_date');
    expect($data)->toHaveKey('documentation_url');
});

it('allows users registered exactly on go-live date', function () {
    // Set go-live date to January 1, 2025
    Config::set('api.v1_go_live_date', '2025-01-01 00:00:00');

    // Create user registered exactly on go-live date
    $user = User::factory()->create([
        'created_at' => now()->parse('2025-01-01 00:00:00'),
    ]);

    $request = Request::create('/api/v1/sessions', 'POST');
    $request->setUserResolver(function () use ($user) {
        return $user;
    });

    $response = $this->middleware->handle($request, function ($req) {
        return response()->json(['success' => true]);
    });

    // User created exactly on cutoff date should be allowed (not after)
    expect($response->getStatusCode())->toBe(200);
});

it('returns 401 when user is not authenticated', function () {
    Config::set('api.v1_go_live_date', '2025-01-01');

    $request = Request::create('/api/v1/sessions', 'POST');
    $request->setUserResolver(function () {
        return null; // No authenticated user
    });

    $response = $this->middleware->handle($request, function ($req) {
        return response()->json(['success' => true]);
    });

    expect($response->getStatusCode())->toBe(401);
    $data = json_decode($response->getContent(), true);
    expect($data['error'])->toBe('Unauthenticated');
});

it('includes documentation URL in rejection response', function () {
    Config::set('api.v1_go_live_date', '2025-01-01');
    Config::set('api.v2_documentation_url', 'https://docs.example.com/api/v2');

    $user = User::factory()->create([
        'created_at' => now()->parse('2025-02-01'),
    ]);

    $request = Request::create('/api/v1/sessions', 'POST');
    $request->setUserResolver(function () use ($user) {
        return $user;
    });

    $response = $this->middleware->handle($request, function ($req) {
        return response()->json(['success' => true]);
    });

    expect($response->getStatusCode())->toBe(403);
    $data = json_decode($response->getContent(), true);
    expect($data['documentation_url'])->toBe('https://docs.example.com/api/v2');
});
