<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\ValidatesRedirectUrl;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class SocialiteController extends Controller
{
    use ValidatesRedirectUrl;

    /**
     * Whether Google OAuth is configured for this site.
     * If GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET are empty, the feature is
     * fully hidden: routes 404, buttons disappear, profile section hides.
     */
    public static function googleEnabled(): bool
    {
        return ! empty(config('services.google.client_id'))
            && ! empty(config('services.google.client_secret'));
    }

    /**
     * Redirect the user to the Google OAuth consent screen.
     */
    public function redirectToGoogle(Request $request): RedirectResponse
    {
        abort_unless(static::googleEnabled(), 404);

        // Persist a redirect target across the OAuth round-trip.
        if ($request->filled('redirect')) {
            $request->session()->put('url.intended', $this->validateRedirectUrl($request->input('redirect')));
        }

        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle the callback from Google.
     */
    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        abort_unless(static::googleEnabled(), 404);

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            // L2: don't log full exception message — may contain ?state/?code
            // query params from the OAuth handshake.
            Log::warning('Google OAuth callback failed', [
                'exception' => $e::class,
            ]);

            return redirect()->route('login')->withErrors([
                'email' => __('auth.google_oauth_failed'),
            ]);
        }

        // C1 (CRITICAL): only auto-link / auto-register when Google has
        // verified the email. Without this check, anyone returning a token
        // for `email_verified=false` claiming victim@example.com would be
        // logged in as that victim.
        if (! $this->isGoogleEmailVerified($googleUser)) {
            Log::warning('Google OAuth: unverified email rejected', [
                'sub' => $googleUser->getId(),
            ]);

            return redirect()->route('login')->withErrors([
                'email' => __('auth.google_oauth_email_not_verified'),
            ]);
        }

        // Scenario 2: previously linked via google_id → straight login.
        $user = User::where('google_id', $googleUser->getId())->first();

        if (! $user) {
            // Scenario 1: existing email account → link Google.
            $user = User::where('email', $googleUser->getEmail())->first();

            if ($user) {
                $this->assignGoogleData($user, $googleUser, preserveExistingAvatar: true);
                $user->email_verified_at = $user->email_verified_at ?? now();
                $user->save();

                AnalyticsService::log('user_google_linked', [
                    'user_id' => $user->id,
                ]);
            } else {
                // Scenario 3: brand-new account via Google.
                $user = new User();
                $user->name              = $googleUser->getName() ?? $googleUser->getNickname() ?? $googleUser->getEmail();
                $user->email             = $googleUser->getEmail();
                $user->email_verified_at = now();
                $user->preferred_language = app()->getLocale();
                $this->assignGoogleData($user, $googleUser, preserveExistingAvatar: false);
                $user->save();

                AnalyticsService::log('user_registered', [
                    'method'  => 'google',
                    'user_id' => $user->id,
                ]);
            }
        } else {
            // Refresh tokens on every login — they may have been rotated.
            $this->assignGoogleData($user, $googleUser, preserveExistingAvatar: true);
            $user->save();
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        AnalyticsService::transferGuestDataToUser($user->id);
        AnalyticsService::log('user_logged_in', [
            'method'  => 'google',
            'user_id' => $user->id,
        ]);

        $redirectUrl = $request->session()->pull('url.intended', route('dashboard'));
        $redirectUrl = $this->validateRedirectUrl($redirectUrl);

        // M5: passwordless logins must complete the 2FA challenge before
        // hitting any protected page. Don't rely on per-route middleware to
        // catch it after the fact.
        if (method_exists($user, 'hasTwoFactorEnabled') && $user->hasTwoFactorEnabled()) {
            $request->session()->put('two_factor_intended_url', $redirectUrl);
            return redirect()->route('two-factor.challenge');
        }

        return redirect($redirectUrl);
    }

    /**
     * Determine whether Google has verified the user's email address.
     * Google's userinfo endpoint returns `email_verified`; Socialite normalises
     * it to `verified_email`. Either may appear depending on Socialite version.
     */
    private function isGoogleEmailVerified($googleUser): bool
    {
        $raw = is_array($googleUser->user ?? null) ? $googleUser->user : [];

        $verified = $raw['email_verified']
            ?? $raw['verified_email']
            ?? null;

        // Accept boolean true OR the string "true" (some IdPs serialise it).
        return $verified === true || $verified === 'true' || $verified === 1 || $verified === '1';
    }

    /**
     * Assign Google OAuth data to the user via direct property assignment
     * (M3: avoids mass-assignment exposure of google_id / google_token).
     */
    private function assignGoogleData(User $user, $googleUser, bool $preserveExistingAvatar): void
    {
        $user->google_id            = $googleUser->getId();
        $user->google_token         = $googleUser->token;
        $user->google_refresh_token = $googleUser->refreshToken;

        $avatar = $this->sanitizeAvatarUrl($googleUser->getAvatar());
        $user->avatar = $preserveExistingAvatar
            ? ($user->avatar ?: $avatar)
            : $avatar;
    }

    /**
     * L3: only allow https URLs from Google's expected avatar host.
     * Anything else is dropped to null.
     */
    private function sanitizeAvatarUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        if (! preg_match('#^https://[a-z0-9.\-]+\.(googleusercontent|google)\.com/#i', $url)) {
            return null;
        }

        return $url;
    }
}
