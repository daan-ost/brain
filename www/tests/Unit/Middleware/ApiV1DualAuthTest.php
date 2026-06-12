<?php

use App\Http\Middleware\ApiV1DualAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->middleware = new ApiV1DualAuth;
    $this->user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => Hash::make('password123'),
    ]);
});

// Note: Sanctum token tests are covered in Feature tests
// Unit tests focus on HTTP Basic Auth logic

it('authenticates user with valid HTTP Basic Auth credentials (email)', function () {
    $request = Request::create('/api/v1/sessions', 'POST');
    $request->headers->set('Authorization', 'Basic '.base64_encode('test@example.com:password123'));

    $response = $this->middleware->handle($request, function ($req) {
        return response()->json(['success' => true]);
    });

    expect($response->getStatusCode())->toBe(200);
    expect(Auth::check())->toBeTrue();
    expect(Auth::user()->email)->toBe('test@example.com');
});

it('rejects Basic Auth with malformed credentials', function () {
    $request = Request::create('/api/v1/sessions', 'POST');
    // Invalid base64 without colon separator
    $request->headers->set('Authorization', 'Basic '.base64_encode('invalidemail'));

    $response = $this->middleware->handle($request, function ($req) {
        return response()->json(['success' => true]);
    });

    expect($response->getStatusCode())->toBe(401);
    expect(Auth::check())->toBeFalse();
});

it('rejects invalid HTTP Basic Auth credentials', function () {
    $request = Request::create('/api/v1/sessions', 'POST');
    $request->headers->set('Authorization', 'Basic '.base64_encode('test@example.com:wrongpassword'));

    $response = $this->middleware->handle($request, function ($req) {
        return response()->json(['success' => true]);
    });

    expect($response->getStatusCode())->toBe(401);
    $data = json_decode($response->getContent(), true);
    expect($data['error'])->toBe('Invalid credentials');
    expect(Auth::check())->toBeFalse();
});

it('rejects request with no authentication', function () {
    $request = Request::create('/api/v1/sessions', 'POST');

    $response = $this->middleware->handle($request, function ($req) {
        return response()->json(['success' => true]);
    });

    expect($response->getStatusCode())->toBe(401);
    $data = json_decode($response->getContent(), true);
    expect($data['error'])->toContain('Authentication required');
    expect(Auth::check())->toBeFalse();
});

// Sanctum token priority tests are covered in Feature tests

it('rejects non-existent user with Basic Auth', function () {
    $request = Request::create('/api/v1/sessions', 'POST');
    $request->headers->set('Authorization', 'Basic '.base64_encode('nonexistent@example.com:password123'));

    $response = $this->middleware->handle($request, function ($req) {
        return response()->json(['success' => true]);
    });

    expect($response->getStatusCode())->toBe(401);
    expect(Auth::check())->toBeFalse();
});
