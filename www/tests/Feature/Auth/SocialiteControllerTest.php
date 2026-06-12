<?php

/**
 * Detail-level Socialite tests die de bredere SocialiteLoginTest aanvullen
 * met scenario-specifieke checks: googleEnabled() unit-coverage,
 * email_verified flag-variaties (string vs bool, alternative key names),
 * avatar preserve/refresh logic, redirect-URL handling, en exception logging.
 *
 * Mock-pattern: $googleUser->user is een ARRAY (basewebsite-conventie),
 * niet $googleUser->getRaw() (workmyagent-conventie). Beide bevatten
 * `email_verified` of `verified_email` met dezelfde semantiek.
 */

use App\Http\Controllers\Auth\SocialiteController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

/**
 * Bouwt een SocialiteUserContract mock met overrides voor specifieke scenario's.
 */
function makeMockGoogleUser(array $overrides = []): SocialiteUserContract
{
    $defaults = [
        'id'           => 'google_uid_123',
        'name'         => 'Test Gebruiker',
        'email'        => 'test@gmail.com',
        'avatar'       => 'https://lh3.googleusercontent.com/photo.jpg',
        'token'        => 'access_token_xyz',
        'refreshToken' => 'refresh_token_xyz',
        'user'         => ['email_verified' => true],
    ];
    $data = array_merge($defaults, $overrides);

    $mock = Mockery::mock(SocialiteUserContract::class);
    $mock->shouldReceive('getId')->andReturn($data['id']);
    $mock->shouldReceive('getName')->andReturn($data['name']);
    $mock->shouldReceive('getNickname')->andReturn(null);
    $mock->shouldReceive('getEmail')->andReturn($data['email']);
    $mock->shouldReceive('getAvatar')->andReturn($data['avatar']);
    $mock->token        = $data['token'];
    $mock->refreshToken = $data['refreshToken'];
    $mock->user         = $data['user']; // Basewebsite leest deze property direct

    return $mock;
}

function mockSocialiteCallback(SocialiteUserContract $googleUser): void
{
    $driver = Mockery::mock();
    $driver->shouldReceive('user')->andReturn($googleUser);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
}

beforeEach(function () {
    config([
        'services.google.client_id'     => 'test_client_id',
        'services.google.client_secret' => 'test_client_secret',
    ]);
});

afterEach(function () {
    Mockery::close();
});

// ============================================================
// SocialiteController::googleEnabled() — unit-level config check
// ============================================================

describe('SocialiteController::googleEnabled()', function () {
    it('geeft true terug als client_id en client_secret geconfigureerd zijn', function () {
        config([
            'services.google.client_id'     => 'test_client_id',
            'services.google.client_secret' => 'test_client_secret',
        ]);

        expect(SocialiteController::googleEnabled())->toBeTrue();
    });

    it('geeft false terug als client_id ontbreekt', function () {
        config([
            'services.google.client_id'     => '',
            'services.google.client_secret' => 'test_client_secret',
        ]);

        expect(SocialiteController::googleEnabled())->toBeFalse();
    });

    it('geeft false terug als client_secret ontbreekt', function () {
        config([
            'services.google.client_id'     => 'test_client_id',
            'services.google.client_secret' => null,
        ]);

        expect(SocialiteController::googleEnabled())->toBeFalse();
    });

    it('geeft false terug als beide ontbreken', function () {
        config([
            'services.google.client_id'     => null,
            'services.google.client_secret' => null,
        ]);

        expect(SocialiteController::googleEnabled())->toBeFalse();
    });
});

// ============================================================
// redirectToGoogle() — open-redirect guard via url.intended
// ============================================================

describe('redirectToGoogle()', function () {
    it('slaat een geldige redirect-url op in de sessie als url.intended', function () {
        $driver = Mockery::mock();
        $driver->shouldReceive('redirect')->andReturn(redirect('/'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $this->get(route('auth.google').'?redirect=/dashboard')
            ->assertSessionHas('url.intended', '/dashboard');
    });

    it('weigert een open-redirect url en valt terug op /', function () {
        $driver = Mockery::mock();
        $driver->shouldReceive('redirect')->andReturn(redirect('/'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        // Dankzij ValidatesRedirectUrl trait wordt evil.com afgewezen → '/'
        $this->get(route('auth.google').'?redirect=https://evil.com/steal')
            ->assertSessionHas('url.intended', '/');
    });
});

// ============================================================
// Scenario 1: al gekoppeld via google_id — token refresh
// ============================================================

describe('handleGoogleCallback() — scenario 1: al gekoppeld via google_id', function () {
    it('logt de gebruiker in en refresht de tokens', function () {
        $user = User::factory()->create([
            'google_id'    => 'google_uid_123',
            'google_token' => 'oud_token',
            'avatar'       => 'https://lh3.googleusercontent.com/bestaand.jpg',
        ]);

        mockSocialiteCallback(makeMockGoogleUser());

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        $user->refresh();
        expect($user->google_token)->toBe('access_token_xyz');
        expect($user->google_refresh_token)->toBe('refresh_token_xyz');
        expect($user->avatar)->toBe('https://lh3.googleusercontent.com/bestaand.jpg', 'Bestaande avatar moet behouden blijven');
    });
});

// ============================================================
// Scenario 2: bestaand email-account — link Google + avatar preserve
// ============================================================

describe('handleGoogleCallback() — scenario 2: bestaand email-account', function () {
    it('koppelt Google aan bestaand account en behoudt de bestaande avatar', function () {
        $user = User::factory()->create([
            'email'             => 'test@gmail.com',
            'google_id'         => null,
            'avatar'            => 'https://lh3.googleusercontent.com/mijn-foto.jpg',
            'email_verified_at' => now(),
        ]);

        mockSocialiteCallback(makeMockGoogleUser());

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        $user->refresh();
        expect($user->google_id)->toBe('google_uid_123');
        expect($user->avatar)->toBe('https://lh3.googleusercontent.com/mijn-foto.jpg');
    });

    it('markeert e-mail als geverifieerd als dat nog niet het geval was', function () {
        $user = User::factory()->create([
            'email'             => 'test@gmail.com',
            'google_id'         => null,
            'email_verified_at' => null,
        ]);

        mockSocialiteCallback(makeMockGoogleUser());

        $this->get(route('auth.google.callback'));

        $user->refresh();
        expect($user->email_verified_at)->not->toBeNull();
    });
});

// ============================================================
// Scenario 3: nieuw account — avatar logic
// ============================================================

describe('handleGoogleCallback() — scenario 3: nieuw account', function () {
    it('gebruikt Google avatar bij een nieuw account', function () {
        mockSocialiteCallback(makeMockGoogleUser(['email' => 'avatar@gmail.com']));

        $this->get(route('auth.google.callback'));

        $user = User::where('email', 'avatar@gmail.com')->first();
        expect($user)->not->toBeNull();
        expect($user->avatar)->toBe('https://lh3.googleusercontent.com/photo.jpg');
    });

    it('sanitiseert een ongeldige avatar-url naar null (L3)', function () {
        mockSocialiteCallback(makeMockGoogleUser([
            'email'  => 'bad-avatar@gmail.com',
            'avatar' => 'https://evil.com/steal.jpg',
        ]));

        $this->get(route('auth.google.callback'));

        $user = User::where('email', 'bad-avatar@gmail.com')->first();
        expect($user)->not->toBeNull();
        expect($user->avatar)->toBeNull('Non-Google avatar URL moet null worden');
    });
});

// ============================================================
// C1: email_verified flag-variaties
// ============================================================

describe('handleGoogleCallback() — email_verified flag varianten (C1)', function () {
    it('weigert aanmelding als email_verified expliciet false is', function () {
        mockSocialiteCallback(makeMockGoogleUser([
            'user' => ['email_verified' => false],
        ]));

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    });

    it('accepteert email_verified als string "true"', function () {
        // Sommige IdPs serialiseren booleans als strings
        mockSocialiteCallback(makeMockGoogleUser([
            'email' => 'strtrue@gmail.com',
            'user'  => ['email_verified' => 'true'],
        ]));

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    });

    it('accepteert verified_email als boolean true (alternative key)', function () {
        // Socialite normaliseert soms naar verified_email i.p.v. email_verified
        mockSocialiteCallback(makeMockGoogleUser([
            'email' => 'veralias@gmail.com',
            'user'  => ['verified_email' => true],
        ]));

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    });

    it('weigert verified_email met waarde null', function () {
        mockSocialiteCallback(makeMockGoogleUser([
            'user' => ['verified_email' => null],
        ]));

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    });

    it('weigert wanneer raw user-data geheel ontbreekt', function () {
        mockSocialiteCallback(makeMockGoogleUser([
            'user' => [], // geen email_verified, geen verified_email
        ]));

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    });
});

// ============================================================
// L2: exception logging zonder gevoelige content
// ============================================================

describe('handleGoogleCallback() — exception handling (L2)', function () {
    it('logt alleen de exception class, niet het bericht met OAuth state/code', function () {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $msg, array $ctx) {
                return str_contains($msg, 'Google OAuth callback failed')
                    && isset($ctx['exception'])
                    && ! isset($ctx['message'])
                    && ! str_contains(json_encode($ctx), 'gevoelige_oauth_state_token');
            });

        $driver = Mockery::mock();
        $driver->shouldReceive('user')->andThrow(new \Exception('gevoelige_oauth_state_token'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    });
});

// ============================================================
// M5: 2FA challenge + url.intended pull
// ============================================================

describe('handleGoogleCallback() — 2FA challenge + intended redirect', function () {
    it('stuurt gebruiker naar 2FA challenge als dat ingeschakeld is (M5)', function () {
        $user = User::factory()->create([
            'google_id'              => 'google_uid_123',
            'two_factor_secret'      => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ]);

        mockSocialiteCallback(makeMockGoogleUser());

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('two-factor.challenge'));

        expect(session('two_factor_intended_url'))->toBe(route('dashboard'));
    });

    it('stuurt terug naar de opgeslagen url.intended na succesvolle login', function () {
        $user = User::factory()->create(['google_id' => 'google_uid_123']);

        $this->withSession(['url.intended' => '/mijn-pagina']);

        mockSocialiteCallback(makeMockGoogleUser());

        $this->get(route('auth.google.callback'))
            ->assertRedirect('/mijn-pagina');

        // url.intended is gepulld na gebruik
        expect(session()->has('url.intended'))->toBeFalse();
    });
});
