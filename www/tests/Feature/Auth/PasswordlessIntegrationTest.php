<?php

namespace Tests\Feature\Auth;

use App\Models\LoginCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

/**
 * Cross-cutting integration tests die meerdere login-methodes overspannen.
 * Niet de unit-level (één controller) en ook niet de scenario-tests
 * (één flow van A→Z), maar de interacties die makkelijk worden vergeten:
 *
 *  #3 — User met password EN google_id switchen tussen login methodes
 *  #4 — 2FA-enabled user via passwordless flow → challenge → recovery code
 *  #6 — Session-fixation: session_id MOET wijzigen na elke login
 */
class PasswordlessIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google.client_id'     => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function fakeGoogleUser(string $id, string $email, string $name = 'Test'): SocialiteUserContract
    {
        $u = Mockery::mock(SocialiteUserContract::class);
        $u->shouldReceive('getId')->andReturn($id);
        $u->shouldReceive('getEmail')->andReturn($email);
        $u->shouldReceive('getName')->andReturn($name);
        $u->shouldReceive('getNickname')->andReturn(null);
        $u->shouldReceive('getAvatar')->andReturn('https://lh3.googleusercontent.com/a/avatar.png');
        $u->token = 'access-token';
        $u->refreshToken = 'refresh-token';
        $u->user = ['email_verified' => true];

        $driver = Mockery::mock();
        $driver->shouldReceive('user')->once()->andReturn($u);
        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($driver);

        return $u;
    }

    // ============================================================
    // #3: Method switching — user met BEIDE methodes
    // ============================================================

    public function test_switching_user_can_login_with_password_then_google_then_code_without_state_leak(): void
    {
        $user = User::factory()->create([
            'email'             => 'switcher@example.com',
            'password'          => bcrypt('original-password'),
            'google_id'         => 'g-switch-123',
            'email_verified_at' => now(),
        ]);

        // === Methode 1: password ===
        $this->post('/login', [
            'email' => 'switcher@example.com',
            'password' => 'original-password',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        // logout
        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();

        // === Methode 2: Google (zelfde account) ===
        $this->fakeGoogleUser('g-switch-123', 'switcher@example.com');
        $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        // Verifieer: account-data niet aangetast door 2e login methode
        $fresh = $user->fresh();
        $this->assertSame('g-switch-123', $fresh->google_id);
        $this->assertNotNull($fresh->password, 'Password moet behouden zijn na Google login');

        $this->post(route('logout'));
        $this->assertGuest();

        // === Methode 3: email-code (zelfde account) ===
        RateLimiter::clear('login-code-send:switcher@example.com');
        RateLimiter::clear('login-code-verify:switcher@example.com');
        $code = '424242';
        LoginCode::create([
            'email' => 'switcher@example.com',
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->post(route('login.code.verify.submit'), [
            'email' => 'switcher@example.com',
            'code'  => $code,
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        // Final state: alle methodes nog beschikbaar
        $final = $user->fresh();
        $this->assertSame('g-switch-123', $final->google_id);
        $this->assertNotNull($final->password);
        $this->assertNotNull($final->email_verified_at);
    }

    // ============================================================
    // #4: 2FA forceer-challenge bij passwordless login
    // ============================================================

    public function test_email_code_login_forces_two_factor_challenge_for_2fa_user(): void
    {
        $user = User::factory()->create([
            'email'                  => '2fa-code@example.com',
            'two_factor_secret'      => 'JBSWY3DPEHPK3PXP', // base32 dummy
            'two_factor_confirmed_at' => now(),
        ]);

        $code = '111222';
        LoginCode::create([
            'email' => '2fa-code@example.com',
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes(15),
        ]);
        RateLimiter::clear('login-code-verify:2fa-code@example.com');

        // Verify code → moet redirecten naar two-factor.challenge, NIET naar dashboard
        $response = $this->post(route('login.code.verify.submit'), [
            'email' => '2fa-code@example.com',
            'code'  => $code,
        ]);

        $response->assertRedirect(route('two-factor.challenge'));

        // Session bevat de bedoelde dashboard-redirect — wordt gebruikt na challenge
        $this->assertSame(route('dashboard'), session('two_factor_intended_url'));
    }

    public function test_google_login_forces_two_factor_challenge_for_2fa_user(): void
    {
        $user = User::factory()->create([
            'email'                  => '2fa-google@example.com',
            'google_id'              => 'g-2fa-1',
            'two_factor_secret'      => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ]);

        $this->fakeGoogleUser('g-2fa-1', '2fa-google@example.com');

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('two-factor.challenge'));
    }

    public function test_user_without_2fa_skips_challenge_on_passwordless_login(): void
    {
        $user = User::factory()->create([
            'email'             => 'no2fa@example.com',
            'two_factor_secret' => null,
        ]);

        $code = '999888';
        LoginCode::create([
            'email' => 'no2fa@example.com',
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes(15),
        ]);
        RateLimiter::clear('login-code-verify:no2fa@example.com');

        $this->post(route('login.code.verify.submit'), [
            'email' => 'no2fa@example.com',
            'code'  => $code,
        ])->assertRedirect(route('dashboard'));

        $this->assertNull(session('two_factor_intended_url'),
            'Geen challenge nodig voor user zonder 2FA');
    }

    // ============================================================
    // #6: Session fixation — session_id MOET regenereren na login
    // ============================================================

    public function test_session_id_regenerates_after_email_code_login(): void
    {
        $user = User::factory()->create(['email' => 'session-fix@example.com']);
        $code = '777777';
        LoginCode::create([
            'email' => 'session-fix@example.com',
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes(15),
        ]);
        RateLimiter::clear('login-code-verify:session-fix@example.com');

        // Initialize session vóór login (hit ANY route to start session)
        $this->get('/login');
        $sessionIdBefore = session()->getId();
        $this->assertNotEmpty($sessionIdBefore);

        $this->post(route('login.code.verify.submit'), [
            'email' => 'session-fix@example.com',
            'code'  => $code,
        ])->assertRedirect(route('dashboard'));

        $sessionIdAfter = session()->getId();
        $this->assertNotSame(
            $sessionIdBefore,
            $sessionIdAfter,
            'session_id MOET regenereren na login (session-fixation guard)'
        );
    }

    public function test_session_id_regenerates_after_google_login(): void
    {
        $user = User::factory()->create([
            'email' => 'google-fix@example.com',
            'google_id' => 'g-fix-1',
        ]);

        $this->get('/login');
        $sessionIdBefore = session()->getId();

        $this->fakeGoogleUser('g-fix-1', 'google-fix@example.com');

        $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

        $this->assertNotSame($sessionIdBefore, session()->getId());
    }

    // ============================================================
    // Bonus: token-binding na disconnect
    // ============================================================

    public function test_disconnect_invalidates_other_sessions_via_remember_token(): void
    {
        $user = User::factory()->create([
            'password'  => bcrypt('pwd'),
            'google_id' => 'g-disc-1',
            'remember_token' => str_repeat('OLD', 20),
        ]);
        $oldToken = $user->remember_token;

        // Simulate Livewire disconnect call
        \Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Profile\ConnectedAccounts::class)
            ->call('disconnectGoogle');

        $newToken = $user->fresh()->remember_token;
        $this->assertNotSame($oldToken, $newToken);
        $this->assertSame(60, strlen($newToken));
    }
}
