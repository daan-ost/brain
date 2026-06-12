<?php

namespace App\Traits;

use App\Models\TwoFactorRememberToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

trait TwoFactorAuthenticatable
{
    /**
     * Get the user's two factor authentication secret.
     */
    public function getTwoFactorSecret(): ?string
    {
        return $this->two_factor_secret;
    }

    /**
     * Get the user's two factor recovery codes.
     */
    public function getTwoFactorRecoveryCodes(): Collection
    {
        if (! $this->two_factor_recovery_codes) {
            return collect();
        }

        return collect($this->two_factor_recovery_codes);
    }

    /**
     * Determine if the user has enabled two factor authentication.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return ! is_null($this->two_factor_secret) && ! is_null($this->two_factor_confirmed_at);
    }

    /**
     * Determine if two factor is pending confirmation (secret set but not confirmed).
     */
    public function hasTwoFactorPending(): bool
    {
        return ! is_null($this->two_factor_secret) && is_null($this->two_factor_confirmed_at);
    }

    /**
     * Generate a new two factor secret.
     */
    public function generateTwoFactorSecret(): string
    {
        $google2fa = new Google2FA();

        return $google2fa->generateSecretKey();
    }

    /**
     * Enable two factor authentication for the user.
     */
    public function enableTwoFactor(string $secret): void
    {
        $this->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();
    }

    /**
     * Confirm two factor authentication for the user.
     */
    public function confirmTwoFactor(): array
    {
        $recoveryCodes = $this->generateRecoveryCodes();

        $this->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodes,
        ])->save();

        return $recoveryCodes;
    }

    /**
     * Disable two factor authentication for the user.
     */
    public function disableTwoFactor(): void
    {
        $this->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        // Remove all remember tokens for this user
        $this->twoFactorRememberTokens()->delete();
    }

    /**
     * Generate new recovery codes.
     * Uses only unambiguous characters (no 0/O, 1/I/l confusion).
     */
    public function generateRecoveryCodes(): array
    {
        // Exclude ambiguous characters: 0, O, 1, I, L
        $characters = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $codes = [];

        for ($i = 0; $i < 8; $i++) {
            $part1 = '';
            $part2 = '';

            for ($j = 0; $j < 4; $j++) {
                $part1 .= $characters[random_int(0, strlen($characters) - 1)];
                $part2 .= $characters[random_int(0, strlen($characters) - 1)];
            }

            $codes[] = $part1 . '-' . $part2;
        }

        return $codes;
    }

    /**
     * Regenerate recovery codes.
     */
    public function regenerateRecoveryCodes(): array
    {
        $codes = $this->generateRecoveryCodes();

        $this->forceFill([
            'two_factor_recovery_codes' => $codes,
        ])->save();

        return $codes;
    }

    /**
     * Verify a two factor code.
     */
    public function verifyTwoFactorCode(string $code): bool
    {
        if (! $this->hasTwoFactorEnabled()) {
            return false;
        }

        $google2fa = new Google2FA();

        return $google2fa->verifyKey($this->two_factor_secret, $code);
    }

    /**
     * Use a recovery code.
     */
    public function useRecoveryCode(string $code): bool
    {
        $codes = $this->getTwoFactorRecoveryCodes();

        if (! $codes->contains($code)) {
            return false;
        }

        // Remove the used code
        $this->forceFill([
            'two_factor_recovery_codes' => $codes->reject(fn ($c) => $c === $code)->values()->toArray(),
        ])->save();

        return true;
    }

    /**
     * Get the QR code URL for the user.
     */
    public function getTwoFactorQrCodeUrl(): string
    {
        $google2fa = new Google2FA();

        return $google2fa->getQRCodeUrl(
            config('app.name'),
            $this->email,
            $this->two_factor_secret
        );
    }

    /**
     * Get the two factor remember tokens for the user.
     */
    public function twoFactorRememberTokens()
    {
        return $this->hasMany(TwoFactorRememberToken::class);
    }

    /**
     * Create a remember token for this device.
     */
    public function createTwoFactorRememberToken(string $guard = 'web', ?string $userAgent = null, ?string $ipAddress = null): string
    {
        $token = Str::random(64);

        $this->twoFactorRememberTokens()->create([
            'token' => hash('sha256', $token),
            'guard' => $guard,
            'user_agent' => $userAgent,
            'ip_address' => $ipAddress,
            'expires_at' => now()->addDays(30),
        ]);

        return $token;
    }

    /**
     * Check if a remember token is valid.
     */
    public function hasValidTwoFactorRememberToken(string $token, string $guard = 'web'): bool
    {
        return $this->twoFactorRememberTokens()
            ->where('token', hash('sha256', $token))
            ->where('guard', $guard)
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * Remove expired remember tokens.
     */
    public function cleanupExpiredRememberTokens(): int
    {
        return $this->twoFactorRememberTokens()
            ->where('expires_at', '<', now())
            ->delete();
    }

    /**
     * Reset two factor authentication (admin action).
     */
    public function resetTwoFactor(?string $reason = null): void
    {
        $this->disableTwoFactor();
    }
}
