<?php

namespace App\Http\Controllers;

use App\Notifications\TwoFactorRecoveryCodeUsedNotification;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class TwoFactorChallengeController extends Controller
{
    public function __construct(
        protected TwoFactorService $twoFactorService
    ) {}

    /**
     * Show the two factor challenge page.
     */
    public function create(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            return redirect()->intended(route('dashboard'));
        }

        // Already verified
        if ($request->session()->get('two_factor_verified_web')) {
            return redirect()->route('dashboard');
        }

        return view('auth.two-factor-challenge');
    }

    /**
     * Verify the two factor code.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            return redirect()->intended(route('dashboard'));
        }

        // Rate limiting
        $key = 'two-factor-challenge:' . $user->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'code' => [__('Too many attempts. Please try again in :seconds seconds.', ['seconds' => $seconds])],
            ]);
        }

        $request->validate([
            'code' => ['nullable', 'string', 'max:6'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $code = $request->input('code');
        $recoveryCode = $request->input('recovery_code');

        // Require at least one code
        if (empty($code) && empty($recoveryCode)) {
            throw ValidationException::withMessages([
                'code' => [__('Please enter a verification code or recovery code.')],
            ]);
        }

        $verified = false;
        $usedRecoveryCode = false;

        // Try TOTP code first
        if ($code) {
            $verified = $this->twoFactorService->verifyLoginCode($user, $code);
        }

        // Try recovery code if TOTP failed or wasn't provided
        if (! $verified && $recoveryCode) {
            $verified = $this->twoFactorService->verifyRecoveryCode($user, $recoveryCode);
            $usedRecoveryCode = $verified;
        }

        if (! $verified) {
            RateLimiter::hit($key, 300); // 5 minutes

            throw ValidationException::withMessages([
                'code' => [__('The provided two factor code is invalid.')],
            ]);
        }

        // Clear rate limiter on success
        RateLimiter::clear($key);

        $intendedUrl = $request->session()->pull('two_factor_intended_url', route('dashboard'));

        // Mark as verified
        $request->session()->put('two_factor_verified_web', true);
        $request->session()->put('two_factor_verified_at', now());

        // Create remember token if requested
        if ($request->boolean('remember')) {
            $this->twoFactorService->createRememberCookie($user, 'web');
        }

        // Notify user if recovery code was used
        if ($usedRecoveryCode) {
            $user->notify(new TwoFactorRecoveryCodeUsedNotification(
                $request->ip(),
                $request->userAgent()
            ));
        }

        return redirect($intendedUrl);
    }
}
