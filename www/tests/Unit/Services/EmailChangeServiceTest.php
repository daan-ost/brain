<?php

use App\Models\User;
use App\Services\EmailChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake(); // Don't send actual emails in tests
    $this->service = new EmailChangeService;
});

it('can request email change', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    $result = $this->service->requestEmailChange($user, 'new@example.com');

    expect($result['success'])->toBeTrue();
    expect($result['pending_email'])->toBe('new@example.com');

    $user->refresh();
    expect($user->pending_email)->toBe('new@example.com');
    expect($user->email_change_token)->not->toBeNull();
    expect($user->email_change_token_expires_at)->not->toBeNull();
});

it('prevents email change when rate limited', function () {
    $user = User::factory()->create([
        'email' => 'old@example.com',
        'last_email_change_request_at' => now()->subMinutes(2), // Only 2 minutes ago
    ]);

    $result = $this->service->requestEmailChange($user, 'new@example.com');

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('wait');
});

it('prevents email change to already used email', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create(['email' => 'old@example.com']);

    $result = $this->service->requestEmailChange($user, 'taken@example.com');

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('already in use');
});

it('prevents email change to same email', function () {
    $user = User::factory()->create(['email' => 'same@example.com']);

    $result = $this->service->requestEmailChange($user, 'same@example.com');

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('same');
});

it('can verify email change with valid token', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    // Create pending change
    $token = \Illuminate\Support\Str::random(64);
    $user->update([
        'pending_email' => 'new@example.com',
        'email_change_token' => hash('sha256', $token),
        'email_change_token_expires_at' => now()->addHours(24),
    ]);

    $result = $this->service->verifyEmailChange($user, $token);

    expect($result['success'])->toBeTrue();

    $user->refresh();
    expect($user->email)->toBe('new@example.com');
    expect($user->pending_email)->toBeNull();
    expect($user->email_change_token)->toBeNull();
    expect($user->email_verified_at)->not->toBeNull();
});

it('rejects verification with invalid token', function () {
    $user = User::factory()->create([
        'email' => 'old@example.com',
        'pending_email' => 'new@example.com',
        'email_change_token' => hash('sha256', 'valid-token'),
        'email_change_token_expires_at' => now()->addHours(24),
    ]);

    $result = $this->service->verifyEmailChange($user, 'wrong-token');

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('Invalid');

    $user->refresh();
    expect($user->email)->toBe('old@example.com'); // Email unchanged
});

it('rejects verification when no pending change exists', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    $result = $this->service->verifyEmailChange($user, 'any-token');

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('No pending');
});

it('prevents verification if new email is taken by another user', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $token = \Illuminate\Support\Str::random(64);

    $user->update([
        'pending_email' => 'new@example.com',
        'email_change_token' => hash('sha256', $token),
        'email_change_token_expires_at' => now()->addHours(24),
    ]);

    // Another user takes the email in the meantime (race condition)
    User::factory()->create(['email' => 'new@example.com']);

    $result = $this->service->verifyEmailChange($user, $token);

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('no longer available');

    $user->refresh();
    expect($user->pending_email)->toBeNull(); // Pending change cancelled
});

it('can cancel pending email change', function () {
    $user = User::factory()->create([
        'email' => 'old@example.com',
        'pending_email' => 'new@example.com',
        'email_change_token' => hash('sha256', 'some-token'),
        'email_change_token_expires_at' => now()->addHours(24),
    ]);

    $result = $this->service->cancelEmailChange($user);

    expect($result)->toBeTrue();

    $user->refresh();
    expect($user->pending_email)->toBeNull();
    expect($user->email_change_token)->toBeNull();
});

it('returns false when canceling non-existent pending change', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    $result = $this->service->cancelEmailChange($user);

    expect($result)->toBeFalse();
});

it('hashes tokens with sha256', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    // Request email change
    $this->service->requestEmailChange($user, 'new@example.com');

    $user->refresh();

    // Token should be 64 characters (SHA256 hex digest)
    expect(strlen($user->email_change_token))->toBe(64);
    // Should be lowercase hex
    expect($user->email_change_token)->toMatch('/^[a-f0-9]{64}$/');
});

it('sets expiry to 24 hours from now', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->service->requestEmailChange($user, 'new@example.com');

    $user->refresh();

    // Check expiry is between 23.9 and 24 hours (accounting for timing)
    $minutesUntilExpiry = now()->diffInMinutes($user->email_change_token_expires_at);
    expect($minutesUntilExpiry)->toBeGreaterThanOrEqual(1439); // 23.98 hours
    expect($minutesUntilExpiry)->toBeLessThanOrEqual(1441); // 24.01 hours
});

it('rejects verification with expired token', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $token = \Illuminate\Support\Str::random(64);

    $user->update([
        'pending_email' => 'new@example.com',
        'email_change_token' => hash('sha256', $token),
        'email_change_token_expires_at' => now()->subHours(1), // Expired 1 hour ago
        'email_change_requested_at' => now()->subHours(25),
    ]);

    // Expiry is validated via hasPendingEmailChange() method
    $result = $this->service->verifyEmailChange($user, $token);

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('No pending');

    $user->refresh();

    // Email should remain unchanged
    expect($user->email)->toBe('old@example.com');
});

it('clears all email change fields on successful verification', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $token = \Illuminate\Support\Str::random(64);

    $user->update([
        'pending_email' => 'new@example.com',
        'email_change_token' => hash('sha256', $token),
        'email_change_token_expires_at' => now()->addHours(24),
        'email_change_requested_at' => now(),
        'last_email_change_request_at' => now(),
    ]);

    $this->service->verifyEmailChange($user, $token);

    $user->refresh();

    // All email change fields should be cleared
    expect($user->pending_email)->toBeNull();
    expect($user->email_change_token)->toBeNull();
    expect($user->email_change_token_expires_at)->toBeNull();
    expect($user->email_change_requested_at)->toBeNull();

    // last_email_change_request_at should remain for rate limiting
    expect($user->last_email_change_request_at)->not->toBeNull();
});

it('sends confirmation email to old address after successful change', function () {
    $user = User::factory()->create([
        'email' => 'old@example.com',
        'name' => 'Test User',
        'preferred_language' => 'en',
    ]);

    $token = \Illuminate\Support\Str::random(64);

    $user->update([
        'pending_email' => 'new@example.com',
        'email_change_token' => hash('sha256', $token),
        'email_change_token_expires_at' => now()->addHours(24),
    ]);

    $result = $this->service->verifyEmailChange($user, $token);

    // Verify the result includes both emails
    expect($result['success'])->toBeTrue();
    expect($result['old_email'])->toBe('old@example.com');
    expect($result['new_email'])->toBe('new@example.com');

    // Verify confirmation email was dispatched to OLD address
    \Illuminate\Support\Facades\Queue::assertPushed(
        \App\Jobs\SendPostmarkTemplateEmail::class,
        function ($job) {
            // Access job properties using reflection since they're private
            $reflection = new \ReflectionClass($job);
            $templateProperty = $reflection->getProperty('templateAlias');
            $templateProperty->setAccessible(true);
            $toProperty = $reflection->getProperty('to');
            $toProperty->setAccessible(true);

            $template = $templateProperty->getValue($job);
            $to = $toProperty->getValue($job);

            return str_contains($template, 'email-change-completed')
                && $to === 'old@example.com';
        }
    );
});
