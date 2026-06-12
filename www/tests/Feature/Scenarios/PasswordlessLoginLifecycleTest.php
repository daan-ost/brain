<?php

/**
 * Passwordless Login Lifecycle Tests
 *
 * End-to-end scenarios voor de twee passwordless login methoden:
 * - Email-code login: request → verify → log in (incl. error paths)
 * - Google OAuth: nieuw / link existing / refused unverified email
 * - Profile: koppel/ontkoppel via ConnectedAccounts
 * - Cross-flow: rate-limit + 2FA forced challenge
 *
 * Deze suite dekt het complete flow-perspectief — niet de individuele
 * controllers (zie Feature/Auth/) en niet de geïsoleerde models (Unit/).
 */

use App\Livewire\Profile\ConnectedAccounts;
use App\Models\LoginCode;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;
use Mockery;

beforeEach(function () {
    // Sites zonder OAuth-config zijn default off — enable hier.
    config([
        'services.google.client_id'     => 'test-client-id',
        'services.google.client_secret' => 'test-client-secret',
        'services.google.redirect'      => 'http://localhost/auth/google/callback',
    ]);
});

afterEach(function () {
    Mockery::close();
});

function fakeGoogleUser(string $id, string $email, string $name = 'Test', bool $emailVerified = true): SocialiteUserContract
{
    $u = Mockery::mock(SocialiteUserContract::class);
    $u->shouldReceive('getId')->andReturn($id);
    $u->shouldReceive('getEmail')->andReturn($email);
    $u->shouldReceive('getName')->andReturn($name);
    $u->shouldReceive('getNickname')->andReturn(null);
    $u->shouldReceive('getAvatar')->andReturn('https://lh3.googleusercontent.com/a/avatar.png');
    $u->token = 'access-token';
    $u->refreshToken = 'refresh-token';
    $u->user = ['email_verified' => $emailVerified];
    return $u;
}

function mockGoogleCallback(SocialiteUserContract $user): void
{
    $driver = Mockery::mock();
    $driver->shouldReceive('user')->once()->andReturn($user);
    Socialite::shouldReceive('driver')->with('google')->once()->andReturn($driver);
}

// ============================================================
// SCENARIO 1: Email-code lifecycle voor bestaande user
// ============================================================

test('email-code lifecycle: request → verify → log in → email verified', function () {
    Notification::fake();

    $email = 'lifecycle-1@example.com';
    $user = User::factory()->create([
        'email'             => $email,
        'email_verified_at' => null,
        'preferred_language' => 'nl',
    ]);
    RateLimiter::clear('login-code-send:'.$email);
    RateLimiter::clear('login-code-send-ip:127.0.0.1');

    // Stap 1: request
    $this->post(route('login.code.send'), ['email' => $email])
        ->assertRedirect(route('login.code.verify', ['email' => $email]))
        ->assertSessionHas('status', 'login-code-sent');

    Notification::assertSentTo($user, LoginCodeNotification::class);

    // Stap 2: pak de code en force een bekende waarde voor verificatie
    $code = '424242';
    LoginCode::where('email', $email)->whereNull('used_at')->latest()->first()
        ->update(['code' => Hash::make($code)]);

    // Stap 3: verify met juiste code → login + dashboard
    $this->post(route('login.code.verify.submit'), ['email' => $email, 'code' => $code])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->email_verified_at)->not->toBeNull('Code-login zet email_verified_at');
    expect(LoginCode::where('email', $email)->whereNotNull('used_at')->count())->toBe(1);
});

test('email-code lifecycle: rate-limit blokkeert na 3 sends per email', function () {
    Notification::fake();

    $email = 'lifecycle-rl@example.com';
    User::factory()->create(['email' => $email]);
    RateLimiter::clear('login-code-send:'.$email);
    RateLimiter::clear('login-code-send-ip:127.0.0.1');

    // 3 succesvolle sends
    for ($i = 1; $i <= 3; $i++) {
        $this->post(route('login.code.send'), ['email' => $email])
            ->assertRedirect(route('login.code.verify', ['email' => $email]));
    }

    // 4e wordt geblokkeerd
    $this->post(route('login.code.send'), ['email' => $email])
        ->assertSessionHasErrors('email');

    Notification::assertSentTimes(LoginCodeNotification::class, 3);
});

test('email-code lifecycle: code reuse rejected', function () {
    Notification::fake();

    $email = 'lifecycle-reuse@example.com';
    $user = User::factory()->create(['email' => $email]);
    RateLimiter::clear('login-code-send:'.$email);
    RateLimiter::clear('login-code-verify:'.$email);

    $code = '111222';
    LoginCode::create([
        'email'      => $email,
        'code'       => Hash::make($code),
        'expires_at' => now()->addMinutes(15),
    ]);

    // 1e verify werkt
    $this->post(route('login.code.verify.submit'), ['email' => $email, 'code' => $code])
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);

    // logout + 2e verify met dezelfde code moet falen (used_at gezet)
    $this->post(route('logout'));
    RateLimiter::clear('login-code-verify:'.$email);

    $this->from(route('login.code.verify', ['email' => $email]))
        ->post(route('login.code.verify.submit'), ['email' => $email, 'code' => $code])
        ->assertSessionHasErrors('code');
    $this->assertGuest();
});

// ============================================================
// SCENARIO 2: Google OAuth lifecycle
// ============================================================

test('google lifecycle: nieuw account aangemaakt en ingelogd', function () {
    mockGoogleCallback(fakeGoogleUser('new-sub-1', 'newuser@example.com', 'New User'));

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('dashboard'));

    $user = User::where('email', 'newuser@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->google_id)->toBe('new-sub-1');
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->password)->toBeNull('Google-only account heeft geen wachtwoord');
    $this->assertAuthenticatedAs($user);
});

test('google lifecycle: bestaand email account wordt automatisch gekoppeld', function () {
    $existing = User::factory()->create([
        'email'     => 'existing@example.com',
        'google_id' => null,
    ]);

    mockGoogleCallback(fakeGoogleUser('link-sub-1', 'existing@example.com', 'Existing'));

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('dashboard'));

    $existing->refresh();
    expect($existing->google_id)->toBe('link-sub-1');
    $this->assertAuthenticatedAs($existing);
});

test('google lifecycle: ongeverifieerd email wordt afgewezen (account-takeover guard)', function () {
    $victim = User::factory()->create(['email' => 'victim@example.com']);

    mockGoogleCallback(fakeGoogleUser('attacker-sub', 'victim@example.com', 'Attacker', emailVerified: false));

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect($victim->fresh()->google_id)->toBeNull('Slachtoffer-account mag NIET zijn gekoppeld');
});

// ============================================================
// SCENARIO 3: Profile management lifecycle
// ============================================================

test('profile lifecycle: user koppelt Google → kan ontkoppelen → token gecycled', function () {
    $user = User::factory()->create([
        'password' => bcrypt('s3cret'),
    ]);

    // Stap 1: koppel via OAuth callback
    mockGoogleCallback(fakeGoogleUser('profile-sub', $user->email, $user->name));
    $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));
    $user->refresh();
    expect($user->hasGoogleLinked())->toBeTrue();

    // Stap 2: ontkoppel via ConnectedAccounts component
    $oldRemember = $user->remember_token;

    \Livewire\Livewire::actingAs($user)
        ->test(ConnectedAccounts::class)
        ->call('disconnectGoogle')
        ->assertHasNoErrors()
        ->assertDispatched('google-disconnected');

    $user->refresh();
    expect($user->hasGoogleLinked())->toBeFalse();
    expect($user->google_token)->toBeNull();
    expect($user->remember_token)->not->toBe($oldRemember, 'M4: remember_token cycled');
});

test('profile lifecycle: Google-only user kan NIET ontkoppelen zonder password', function () {
    // User die alleen via Google is geregistreerd (geen password)
    $user = User::factory()->create(['password' => null]);
    mockGoogleCallback(fakeGoogleUser('locked-sub', $user->email));
    $this->get(route('auth.google.callback'));
    $user->refresh();
    expect($user->hasPassword())->toBeFalse();
    expect($user->hasGoogleLinked())->toBeTrue();

    \Livewire\Livewire::actingAs($user)
        ->test(ConnectedAccounts::class)
        ->call('disconnectGoogle')
        ->assertHasErrors('google');

    expect($user->fresh()->hasGoogleLinked())->toBeTrue('Mag NIET ontkoppelen zonder password');
});

// ============================================================
// SCENARIO 4: Graceful degradation (Google config disabled)
// ============================================================

test('graceful degradation: Google routes 404 als config leeg, code-login werkt wel', function () {
    config([
        'services.google.client_id'     => null,
        'services.google.client_secret' => null,
    ]);

    // Google routes geven 404
    $this->get(route('auth.google'))->assertNotFound();
    $this->get(route('auth.google.callback'))->assertNotFound();

    // Code-login routes werken normaal
    $this->get(route('login.code'))->assertOk();
    $this->get(route('login.code.verify', ['email' => 'a@b.com']))->assertOk();
});

test('graceful degradation: login-pagina toont geen Google-knop bij disabled config', function () {
    config([
        'services.google.client_id'     => null,
        'services.google.client_secret' => null,
    ]);

    $this->get('/login')
        ->assertOk()
        ->assertDontSee(route('auth.google'), false);
});
