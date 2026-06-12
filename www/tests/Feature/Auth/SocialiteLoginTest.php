<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class SocialiteLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable Google OAuth for these tests (sites without GOOGLE_CLIENT_ID
        // hide the feature entirely — see test_google_routes_404_when_credentials_not_configured).
        config([
            'services.google.client_id'     => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect'      => 'http://localhost/auth/google/callback',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_google_routes_404_when_credentials_not_configured(): void
    {
        config([
            'services.google.client_id'     => null,
            'services.google.client_secret' => null,
        ]);

        $this->get(route('auth.google'))->assertNotFound();
        $this->get(route('auth.google.callback'))->assertNotFound();
    }

    public function test_login_page_hides_google_button_when_disabled(): void
    {
        config([
            'services.google.client_id'     => null,
            'services.google.client_secret' => null,
        ]);

        $response = $this->get('/login');
        $response->assertStatus(200);
        $response->assertDontSee(route('auth.google'), false);
    }

    public function test_redirect_to_google_returns_redirect_response(): void
    {
        // We don't mock the driver here — Socialite will throw without config,
        // so we just stub the redirect call.
        $driver = Mockery::mock();
        $driver->shouldReceive('redirect')->once()->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($driver);

        $this->get(route('auth.google'))
            ->assertRedirect('https://accounts.google.com/o/oauth2/auth');
    }

    public function test_callback_creates_new_user_when_no_match(): void
    {
        $abstractUser = $this->fakeGoogleUser('99999', 'new@example.com', 'New User');
        $this->mockGoogleCallback($abstractUser);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $user = User::where('email', 'new@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('99999', $user->google_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->password);
    }

    public function test_callback_links_existing_email_account(): void
    {
        $existing = User::factory()->create([
            'email'             => 'user@example.com',
            'email_verified_at' => null,
            'google_id'         => null,
        ]);

        $abstractUser = $this->fakeGoogleUser('12345', 'user@example.com', 'Existing User');
        $this->mockGoogleCallback($abstractUser);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($existing);
        $existing->refresh();
        $this->assertSame('12345', $existing->google_id);
        $this->assertNotNull($existing->email_verified_at);
    }

    public function test_callback_rejects_unverified_google_email(): void
    {
        // C1: account-takeover guard. An attacker controlling a Google
        // identity that returns email_verified=false claiming a victim's
        // email must NOT be linked to or logged in as the victim.
        User::factory()->create(['email' => 'victim@example.com']);

        $abstractUser = $this->fakeGoogleUser('attacker-sub', 'victim@example.com', 'Attacker', emailVerified: false);
        $this->mockGoogleCallback($abstractUser);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        // The victim's account must remain un-linked.
        $this->assertDatabaseMissing('users', [
            'email'     => 'victim@example.com',
            'google_id' => 'attacker-sub',
        ]);
    }

    public function test_callback_logs_in_already_linked_account(): void
    {
        $existing = User::factory()->create([
            'email'     => 'linked@example.com',
            'google_id' => '777',
        ]);

        $abstractUser = $this->fakeGoogleUser('777', 'linked@example.com', 'Linked');
        $this->mockGoogleCallback($abstractUser);

        $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($existing);
    }

    public function test_callback_redirects_user_with_2fa_to_challenge(): void
    {
        // M5: 2FA-enabled user logging in via Google must be sent through
        // the 2FA challenge before reaching the dashboard.
        $user = User::factory()->create([
            'email'     => 'u@example.com',
            'google_id' => '999',
        ]);
        // Force hasTwoFactorEnabled() to be true.
        $user->two_factor_secret = 'JBSWY3DPEHPK3PXP';
        $user->two_factor_confirmed_at = now();
        $user->save();

        $abstractUser = $this->fakeGoogleUser('999', 'u@example.com', 'U');
        $this->mockGoogleCallback($abstractUser);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('two-factor.challenge'));
    }

    public function test_avatar_from_non_google_host_is_dropped(): void
    {
        // L3: avatar URL is sanitized to only accept Google-hosted https.
        // Build a fresh mock with a non-Google avatar URL.
        $abstractUser = Mockery::mock(SocialiteUserContract::class);
        $abstractUser->shouldReceive('getId')->andReturn('555');
        $abstractUser->shouldReceive('getEmail')->andReturn('avatar@example.com');
        $abstractUser->shouldReceive('getName')->andReturn('A');
        $abstractUser->shouldReceive('getNickname')->andReturn(null);
        $abstractUser->shouldReceive('getAvatar')->andReturn('javascript:alert(1)');
        $abstractUser->token = 'token';
        $abstractUser->refreshToken = 'refresh';
        $abstractUser->user = ['email_verified' => true];

        $this->mockGoogleCallback($abstractUser);

        $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));

        $user = User::where('email', 'avatar@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->avatar, 'Untrusted avatar URL must be dropped');
    }

    private function fakeGoogleUser(string $id, string $email, string $name, bool $emailVerified = true): SocialiteUserContract
    {
        $abstractUser = Mockery::mock(SocialiteUserContract::class);
        $abstractUser->shouldReceive('getId')->andReturn($id);
        $abstractUser->shouldReceive('getEmail')->andReturn($email);
        $abstractUser->shouldReceive('getName')->andReturn($name);
        $abstractUser->shouldReceive('getNickname')->andReturn(null);
        // Use a googleusercontent.com URL — SocialiteController sanitizes
        // avatars and only accepts Google-hosted https URLs.
        $abstractUser->shouldReceive('getAvatar')->andReturn('https://lh3.googleusercontent.com/a/avatar.png');
        $abstractUser->token = 'token';
        $abstractUser->refreshToken = 'refresh';
        // C1: SocialiteController checks $googleUser->user['email_verified'].
        $abstractUser->user = [
            'sub'            => $id,
            'email'          => $email,
            'email_verified' => $emailVerified,
            'verified_email' => $emailVerified, // Socialite normalises to this too
        ];

        return $abstractUser;
    }

    private function mockGoogleCallback(SocialiteUserContract $abstractUser): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->once()->andReturn($abstractUser);
        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($driver);
    }
}
