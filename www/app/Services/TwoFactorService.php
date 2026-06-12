<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\TwoFactorDisabledNotification;
use App\Notifications\TwoFactorEnabledNotification;
use App\Notifications\TwoFactorResetByAdminNotification;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cookie;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    protected Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    /**
     * Generate a new secret key.
     */
    public function generateSecretKey(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * Get the QR code URL for setting up 2FA.
     */
    public function getQrCodeUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret
        );
    }

    /**
     * Generate a QR code SVG for the given user and secret.
     */
    public function generateQrCodeSvg(User $user, string $secret): string
    {
        $url = $this->getQrCodeUrl($user, $secret);

        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd()
        );

        $writer = new Writer($renderer);

        return $writer->writeString($url);
    }

    /**
     * Verify a TOTP code (no replay protection — use verifyCodeForUser when a User is available).
     */
    public function verifyCode(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, $code);
    }

    /**
     * Verify a TOTP code with replay protection.
     * Uses the stored timestamp to reject codes already used in the same 30-second window.
     * Updates two_factor_code_timestamp on success.
     *
     * NOTE: verifyKeyNewer returns `true` (not an integer) when $oldTimestamp is null,
     * because there is no lower-bound constraint. We normalise this to the actual current
     * TOTP period so subsequent calls can correctly reject replayed codes.
     */
    public function verifyCodeForUser(User $user, string $code): bool
    {
        $oldTimestamp = $user->two_factor_code_timestamp;

        $newTimestamp = $this->google2fa->verifyKeyNewer($user->two_factor_secret, $code, $oldTimestamp);

        if ($newTimestamp === false) {
            return false;
        }

        // Google2FA returns `true` instead of an integer when $oldTimestamp is null.
        // Normalise to the actual TOTP period counter (floor(time / 30)) so that
        // the stored value is a meaningful lower bound for the next verification.
        if ($newTimestamp === true) {
            $newTimestamp = (int) floor(time() / $this->google2fa->getKeyRegeneration());
        }

        $user->forceFill(['two_factor_code_timestamp' => $newTimestamp])->save();

        return true;
    }

    /**
     * Enable 2FA for a user (step 1: generate secret).
     */
    public function enableTwoFactor(User $user): string
    {
        $secret = $this->generateSecretKey();

        $user->enableTwoFactor($secret);

        return $secret;
    }

    /**
     * Confirm 2FA setup with a valid code (step 2: verify and activate).
     */
    public function confirmTwoFactor(User $user, string $code): array|false
    {
        if (! $user->hasTwoFactorPending()) {
            return false;
        }

        if (! $this->verifyCodeForUser($user, $code)) {
            return false;
        }

        $recoveryCodes = $user->confirmTwoFactor();

        // Send notification
        $user->notify(new TwoFactorEnabledNotification());

        return $recoveryCodes;
    }

    /**
     * Disable 2FA for a user.
     */
    public function disableTwoFactor(User $user, bool $sendNotification = true): void
    {
        $user->disableTwoFactor();

        if ($sendNotification) {
            $user->notify(new TwoFactorDisabledNotification());
        }
    }

    /**
     * Verify a login attempt with 2FA.
     */
    public function verifyLoginCode(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return true; // No 2FA enabled, allow
        }

        return $this->verifyCodeForUser($user, $code);
    }

    /**
     * Verify a recovery code.
     */
    public function verifyRecoveryCode(User $user, string $code): bool
    {
        // Normalize the code (uppercase, trim)
        $code = strtoupper(trim($code));

        return $user->useRecoveryCode($code);
    }

    /**
     * Create a remember token cookie.
     */
    public function createRememberCookie(User $user, string $guard = 'web'): void
    {
        $token = $user->createTwoFactorRememberToken(
            $guard,
            request()->userAgent(),
            request()->ip()
        );

        $cookieName = $this->getRememberCookieName($guard);

        Cookie::queue(
            $cookieName,
            $token,
            60 * 24 * 30, // 30 days
            '/',
            null,
            true, // secure
            true  // httpOnly
        );
    }

    /**
     * Check if user has a valid remember token.
     */
    public function hasValidRememberToken(User $user, string $guard = 'web'): bool
    {
        $cookieName = $this->getRememberCookieName($guard);
        $token = request()->cookie($cookieName);

        if (! $token) {
            return false;
        }

        return $user->hasValidTwoFactorRememberToken($token, $guard);
    }

    /**
     * Clear the remember cookie.
     */
    public function clearRememberCookie(string $guard = 'web'): void
    {
        $cookieName = $this->getRememberCookieName($guard);
        Cookie::queue(Cookie::forget($cookieName));
    }

    /**
     * Get the cookie name for remember token.
     */
    protected function getRememberCookieName(string $guard): string
    {
        return 'two_factor_remember_' . $guard;
    }

    /**
     * Reset 2FA for a user (admin action).
     */
    public function resetTwoFactor(User $user, ?User $admin = null, ?string $reason = null): void
    {
        $user->resetTwoFactor($reason);

        // Send notification to user
        $user->notify(new TwoFactorResetByAdminNotification($reason));
    }

    /**
     * Regenerate recovery codes.
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        return $user->regenerateRecoveryCodes();
    }
}
