<?php

namespace App\Livewire\Profile;

use App\Services\TwoFactorService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

class TwoFactorManager extends Component
{
    public bool $showingQrCode = false;
    public bool $showingRecoveryCodes = false;
    public bool $confirmingDisable = false;
    public bool $confirmingRegenerateCodes = false;
    public bool $confirmingShowCodes = false;

    public string $code = '';
    public string $password = '';
    public ?string $qrCodeSvg = null;
    public array $recoveryCodes = [];

    protected TwoFactorService $twoFactorService;

    public function boot(TwoFactorService $twoFactorService): void
    {
        $this->twoFactorService = $twoFactorService;
    }

    public function mount(): void
    {
        $user = Auth::user();

        if ($user->hasTwoFactorPending()) {
            // Resume pending setup — secret loaded in render(), not stored as public property
            $this->qrCodeSvg = $this->twoFactorService->generateQrCodeSvg($user, $user->two_factor_secret);
            $this->showingQrCode = true;
        }
    }

    /**
     * Start the 2FA setup process.
     */
    public function enableTwoFactor(): void
    {
        $user = Auth::user();

        $secret = $this->twoFactorService->enableTwoFactor($user);
        $this->qrCodeSvg = $this->twoFactorService->generateQrCodeSvg($user, $secret);
        $this->showingQrCode = true;
        $this->code = '';
    }

    /**
     * Confirm the 2FA setup with a valid code.
     */
    public function confirmTwoFactor(): void
    {
        $this->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = Auth::user();

        // Rate limiting
        $key = 'two-factor-confirm:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('code', __('Too many attempts. Please try again later.'));
            return;
        }

        $recoveryCodes = $this->twoFactorService->confirmTwoFactor($user, $this->code);

        if ($recoveryCodes === false) {
            RateLimiter::hit($key, 300);
            $this->addError('code', __('The provided code is invalid.'));
            return;
        }

        RateLimiter::clear($key);
        $this->recoveryCodes = $recoveryCodes;
        $this->showingQrCode = false;
        $this->showingRecoveryCodes = true;
        $this->code = '';

        $this->dispatch('two-factor-enabled');
    }

    /**
     * Cancel the 2FA setup process.
     */
    public function cancelSetup(): void
    {
        $user = Auth::user();

        if ($user->hasTwoFactorPending()) {
            // Don't send notification when canceling setup (not yet enabled)
            $this->twoFactorService->disableTwoFactor($user, sendNotification: false);
        }

        $this->reset(['showingQrCode', 'qrCodeSvg', 'code']);
    }

    /**
     * Show disable confirmation.
     */
    public function confirmDisableTwoFactor(): void
    {
        $this->confirmingDisable = true;
        $this->password = '';
    }

    /**
     * Disable 2FA.
     */
    public function disableTwoFactor(): void
    {
        $this->validate([
            'password' => ['required', 'string'],
        ]);

        $user = Auth::user();

        if (! Hash::check($this->password, $user->password)) {
            $this->addError('password', __('The password is incorrect.'));
            return;
        }

        $this->twoFactorService->disableTwoFactor($user);

        $this->reset(['confirmingDisable', 'password', 'showingRecoveryCodes', 'recoveryCodes']);

        $this->dispatch('two-factor-disabled');
    }

    /**
     * Show regenerate codes confirmation.
     */
    public function confirmRegenerateRecoveryCodes(): void
    {
        $this->confirmingRegenerateCodes = true;
        $this->password = '';
        $this->code = '';
    }

    /**
     * Regenerate recovery codes.
     */
    public function regenerateRecoveryCodes(): void
    {
        $this->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = Auth::user();

        // Rate limiting
        $key = 'two-factor-regenerate:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('code', __('Too many attempts. Please try again later.'));
            return;
        }

        if (! Hash::check($this->password, $user->password)) {
            RateLimiter::hit($key, 300);
            $this->addError('password', __('The password is incorrect.'));
            return;
        }

        if (! $this->twoFactorService->verifyCodeForUser($user, $this->code)) {
            RateLimiter::hit($key, 300);
            $this->addError('code', __('The provided code is invalid.'));
            return;
        }

        RateLimiter::clear($key);
        $this->recoveryCodes = $this->twoFactorService->regenerateRecoveryCodes($user);
        $this->showingRecoveryCodes = true;
        $this->confirmingRegenerateCodes = false;
        $this->password = '';
        $this->code = '';

        $this->dispatch('recovery-codes-regenerated');
    }

    /**
     * Show confirmation for viewing recovery codes.
     */
    public function confirmShowRecoveryCodes(): void
    {
        $this->confirmingShowCodes = true;
        $this->password = '';
    }

    /**
     * Show current recovery codes — requires password confirmation.
     */
    public function showRecoveryCodes(): void
    {
        $this->validate([
            'password' => ['required', 'string'],
        ]);

        $user = Auth::user();

        if (! Hash::check($this->password, $user->password)) {
            $this->addError('password', __('The password is incorrect.'));

            return;
        }

        $this->recoveryCodes = $user->getTwoFactorRecoveryCodes()->toArray();
        $this->showingRecoveryCodes = true;
        $this->confirmingShowCodes = false;
        $this->password = '';
    }

    /**
     * Hide recovery codes.
     */
    public function hideRecoveryCodes(): void
    {
        $this->showingRecoveryCodes = false;
        $this->recoveryCodes = [];
    }

    /**
     * Cancel disable confirmation.
     */
    public function cancelDisable(): void
    {
        $this->confirmingDisable = false;
        $this->password = '';
    }

    /**
     * Cancel regenerate confirmation.
     */
    public function cancelRegenerate(): void
    {
        $this->confirmingRegenerateCodes = false;
        $this->password = '';
        $this->code = '';
    }

    /**
     * Cancel show codes confirmation.
     */
    public function cancelShowCodes(): void
    {
        $this->confirmingShowCodes = false;
        $this->password = '';
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.profile.two-factor-manager', [
            'user' => $user,
            'enabled' => $user->hasTwoFactorEnabled(),
            'pending' => $user->hasTwoFactorPending(),
            // Secret only passed to view when QR code is showing — never stored as public property
            'secretKey' => $this->showingQrCode ? $user->two_factor_secret : null,
        ]);
    }
}
