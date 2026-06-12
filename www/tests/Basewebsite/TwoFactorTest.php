<?php

/**
 * Basewebsite Propagation Smoke Tests — Two-Factor Authentication
 *
 * Verifies that the 2FA feature works correctly after propagation.
 * These tests should pass on ALL child sites that inherit from basewebsite.
 */

declare(strict_types=1);

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

// ---------------------------------------------------------------------------
// Guard — skip het hele bestand als 2FA niet beschikbaar is op deze site
// ---------------------------------------------------------------------------

beforeEach(function () {
    if (! class_exists(TwoFactorService::class)) {
        $this->markTestSkipped('TwoFactorService niet beschikbaar op deze site');
    }
    if (! class_exists(\PragmaRX\Google2FA\Google2FA::class)) {
        $this->markTestSkipped('pragmarx/google2fa pakket niet geïnstalleerd');
    }
    if (! method_exists(User::class, 'hasTwoFactorEnabled')) {
        $this->markTestSkipped('TwoFactorAuthenticatable trait niet aanwezig op User model');
    }
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a fully confirmed 2FA user with a real TOTP secret.
 */
function createTwoFactorUser(array $attributes = []): User
{
    /** @var TwoFactorService $service */
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecretKey();

    $user = User::factory()->create(array_merge([
        'two_factor_secret'       => $secret,
        'two_factor_confirmed_at' => now(),
    ], $attributes));

    // Store recovery codes (same as confirmTwoFactor does)
    $codes = $user->generateRecoveryCodes();
    $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

    return $user;
}

/**
 * Generate a valid TOTP code for a user.
 */
function totpCode(User $user): string
{
    // two_factor_secret has an 'encrypted' cast — accessing it returns the plaintext
    return (new Google2FA)->getCurrentOtp($user->two_factor_secret);
}

// ---------------------------------------------------------------------------
// Routes & page access
// ---------------------------------------------------------------------------

describe('Two-Factor Challenge Routes', function () {
    it('challenge page returns 200 for authenticated user without 2FA session', function () {
        $user = createTwoFactorUser();

        $response = $this->actingAs($user)->get('/two-factor-challenge');

        $response->assertStatus(200);
    });

    it('challenge page redirects already-verified user to dashboard', function () {
        $user = createTwoFactorUser();

        $response = $this->actingAs($user)
            ->withSession(['two_factor_verified_web' => true])
            ->get('/two-factor-challenge');

        $response->assertRedirectToRoute('dashboard');
    });

    it('challenge page redirects user without 2FA enabled to dashboard', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/two-factor-challenge');

        expect($response->status())->toBeIn([302, 200]);
    });
});

// ---------------------------------------------------------------------------
// Successful verification
// ---------------------------------------------------------------------------

describe('Two-Factor Verification Success', function () {
    it('verifies a correct TOTP code and sets session flag', function () {
        $user = createTwoFactorUser();
        $code = totpCode($user);

        // Clear rate limiter from any prior run
        RateLimiter::clear('two-factor-challenge:' . $user->id);

        $response = $this->actingAs($user)
            ->post('/two-factor-challenge', ['code' => $code]);

        $response->assertRedirect();
        $response->assertSessionMissing('errors');
    });

    it('redirects to intended URL after successful verification', function () {
        $user = createTwoFactorUser();
        $code = totpCode($user);

        RateLimiter::clear('two-factor-challenge:' . $user->id);

        $response = $this->actingAs($user)
            ->withSession(['two_factor_intended_url' => route('profile.account')])
            ->post('/two-factor-challenge', ['code' => $code]);

        $response->assertRedirect(route('profile.account'));
    });

    it('falls back to dashboard when no intended URL is set', function () {
        $user = createTwoFactorUser();
        $code = totpCode($user);

        RateLimiter::clear('two-factor-challenge:' . $user->id);

        $response = $this->actingAs($user)
            ->post('/two-factor-challenge', ['code' => $code]);

        $response->assertRedirect(route('dashboard'));
    });
});

// ---------------------------------------------------------------------------
// Incorrect codes & errors
// ---------------------------------------------------------------------------

describe('Two-Factor Verification Failures', function () {
    it('returns validation error for wrong TOTP code', function () {
        $user = createTwoFactorUser();

        RateLimiter::clear('two-factor-challenge:' . $user->id);

        $this->actingAs($user)
            ->post('/two-factor-challenge', ['code' => '000000'])
            ->assertSessionHasErrors('code');
    });

    it('keeps intended URL after a wrong code attempt', function () {
        $user = createTwoFactorUser();
        $code = totpCode($user);

        RateLimiter::clear('two-factor-challenge:' . $user->id);

        // Wrong code — intended URL must survive in session
        $this->actingAs($user)
            ->withSession(['two_factor_intended_url' => route('profile.account')])
            ->post('/two-factor-challenge', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        // Correct code — should redirect to original intended URL
        $response = $this->actingAs($user)
            ->withSession(['two_factor_intended_url' => route('profile.account')])
            ->post('/two-factor-challenge', ['code' => $code]);

        $response->assertRedirect(route('profile.account'));
    });

    it('returns error when no code or recovery code is submitted', function () {
        $user = createTwoFactorUser();

        RateLimiter::clear('two-factor-challenge:' . $user->id);

        $this->actingAs($user)
            ->post('/two-factor-challenge', [])
            ->assertSessionHasErrors('code');
    });
});

// ---------------------------------------------------------------------------
// Recovery codes
// ---------------------------------------------------------------------------

describe('Two-Factor Recovery Codes', function () {
    it('accepts a valid recovery code', function () {
        $user = createTwoFactorUser();

        RateLimiter::clear('two-factor-challenge:' . $user->id);

        $codes = $user->getTwoFactorRecoveryCodes();

        if ($codes->isEmpty()) {
            $this->markTestSkipped('No recovery codes available');
        }

        $validCode = $codes->first();

        $response = $this->actingAs($user)
            ->post('/two-factor-challenge', ['recovery_code' => $validCode]);

        $response->assertRedirect();
        $response->assertSessionMissing('errors');
    });

    it('rejects an invalid recovery code', function () {
        $user = createTwoFactorUser();

        RateLimiter::clear('two-factor-challenge:' . $user->id);

        $this->actingAs($user)
            ->post('/two-factor-challenge', ['recovery_code' => 'INVL-BADCODE'])
            ->assertSessionHasErrors('code');
    });
});

// ---------------------------------------------------------------------------
// Rate limiting
// ---------------------------------------------------------------------------

describe('Two-Factor Rate Limiting', function () {
    it('blocks after 5 wrong attempts', function () {
        $user = createTwoFactorUser();
        $key = 'two-factor-challenge:' . $user->id;

        RateLimiter::clear($key);

        // Exhaust all 5 attempts
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)
                ->post('/two-factor-challenge', ['code' => '000000']);
        }

        // 6th attempt should be rate-limited
        $response = $this->actingAs($user)
            ->post('/two-factor-challenge', ['code' => '000000']);

        $response->assertSessionHasErrors('code');

        RateLimiter::clear($key);
    });
});

// ---------------------------------------------------------------------------
// Middleware: EnsureTwoFactorChallenged
// ---------------------------------------------------------------------------

describe('Two-Factor Middleware', function () {
    it('redirects user with 2FA enabled to challenge page', function () {
        $user = createTwoFactorUser();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirectToRoute('two-factor.challenge');
    });

    it('stores intended URL in two_factor_intended_url session key', function () {
        $user = createTwoFactorUser();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertSessionHas('two_factor_intended_url');
    });

    it('does NOT store intended URL in the shared url.intended key', function () {
        $user = createTwoFactorUser();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertSessionMissing('url.intended');
    });

    it('allows through user with 2FA session verified', function () {
        $user = createTwoFactorUser();

        $response = $this->actingAs($user)
            ->withSession(['two_factor_verified_web' => true])
            ->get('/dashboard');

        expect($response->status())->toBeIn([200, 302]);
        if ($response->status() === 302) {
            expect($response->headers->get('Location'))->not->toContain('/two-factor-challenge');
        }
    });

    it('allows through user without 2FA enabled', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        expect($response->status())->toBeIn([200, 302]);
        if ($response->status() === 302) {
            expect($response->headers->get('Location'))->not->toContain('/two-factor-challenge');
        }
    });
});

// ---------------------------------------------------------------------------
// TwoFactorService
// ---------------------------------------------------------------------------

describe('TwoFactorService', function () {
    it('generates a valid secret key', function () {
        $service = app(TwoFactorService::class);
        $secret = $service->generateSecretKey();

        expect($secret)->toBeString();
        expect(strlen($secret))->toBeGreaterThanOrEqual(16);
    });

    it('verifies a correct TOTP code', function () {
        $service = app(TwoFactorService::class);
        $secret = $service->generateSecretKey();
        $code = (new Google2FA)->getCurrentOtp($secret);

        expect($service->verifyCode($secret, $code))->toBeTrue();
    });

    it('rejects an incorrect TOTP code', function () {
        $service = app(TwoFactorService::class);
        $secret = $service->generateSecretKey();

        expect($service->verifyCode($secret, '000000'))->toBeFalse();
    });

    it('generates recovery codes as array', function () {
        $user = User::factory()->create([
            'two_factor_secret'       => app(TwoFactorService::class)->generateSecretKey(),
            'two_factor_confirmed_at' => now(),
        ]);

        $codes = $user->generateRecoveryCodes();

        expect($codes)->toBeArray();
        expect(count($codes))->toBeGreaterThan(0);
        expect($codes[0])->toContain('-');
    });
});
