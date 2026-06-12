<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\ValidatesRedirectUrl;
use App\Http\Controllers\Controller;
use App\Models\LoginCode;
use App\Models\User;
use App\Notifications\LoginCodeNotification;
use App\Services\AnalyticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class LoginCodeController extends Controller
{
    use ValidatesRedirectUrl;

    /**
     * Show the form to request a one-time login code by email.
     */
    public function showRequestForm(Request $request): View
    {
        if ($request->filled('redirect')) {
            $request->session()->put('url.intended', $this->validateRedirectUrl($request->input('redirect')));
        }

        return view('auth.login-code-request');
    }

    /**
     * Generate + send a 6-digit login code to the requested email (if it
     * matches an existing account). Always returns a success page so as
     * not to disclose whether an email is registered.
     */
    public function sendCode(Request $request): RedirectResponse
    {
        $request->validate(['email' => 'required|email']);

        $email = strtolower(trim($request->email));
        $emailKey = 'login-code-send:'.$email;
        $ipKey = 'login-code-send-ip:'.$request->ip();

        // M2: per-email rate limit (3 / 15 min) PLUS per-IP rate limit
        // (30 / hour) to defeat distributed mailbomb-style enumeration.
        if (RateLimiter::tooManyAttempts($emailKey, 3)) {
            $seconds = RateLimiter::availableIn($emailKey);

            return back()
                ->withInput($request->only('email'))
                ->withErrors([
                    'email' => __('auth.login_code_too_many_requests', ['seconds' => $seconds]),
                ]);
        }

        if (RateLimiter::tooManyAttempts($ipKey, 30)) {
            Log::warning('login-code-send: IP rate limit exceeded', [
                'ip' => $request->ip(),
            ]);

            return back()
                ->withInput($request->only('email'))
                ->withErrors([
                    'email' => __('auth.login_code_too_many_attempts'),
                ]);
        }

        RateLimiter::hit($emailKey, 900); // 15 min decay
        RateLimiter::hit($ipKey, 3600);   // 1 hour decay

        $user = User::where('email', $email)->first();

        if ($user) {
            // Invalidate any outstanding (unused) codes for this email.
            LoginCode::where('email', $email)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            LoginCode::create([
                'email'      => $email,
                'code'       => Hash::make($code),
                'expires_at' => now()->addMinutes(15),
            ]);

            $user->notify(new LoginCodeNotification($code));

            AnalyticsService::log('login_code_requested', [
                'user_id' => $user->id,
            ]);
        } else {
            // L1: equivalent dummy work for unknown emails so response
            // timing does not reveal whether the address is registered.
            Hash::make((string) random_int(0, 999999));
        }

        // Always return success — do not reveal whether the email exists.
        return redirect()->route('login.code.verify', ['email' => $email])
            ->with('status', 'login-code-sent');
    }

    /**
     * Show the form for entering the received 6-digit login code.
     */
    public function showVerifyForm(Request $request): View
    {
        return view('auth.login-code-verify', [
            'email' => $request->query('email', ''),
        ]);
    }

    /**
     * Verify the entered code and log the user in.
     */
    public function verifyCode(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
            'code'  => 'required|digits:6',
        ]);

        $email = strtolower(trim($request->email));
        $key = 'login-code-verify:'.$email;
        $ipKey = 'login-code-verify-ip:'.$request->ip();

        // Verification rate limit: 5/email/15min plus 50/IP/hour.
        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ipKey, 50)) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors([
                    'code' => __('auth.login_code_too_many_attempts'),
                ]);
        }

        // M1 (TOCTOU): wrap the SELECT + UPDATE in a transaction with a row
        // lock so two parallel requests with the same code can't both pass.
        $verifiedCode = DB::transaction(function () use ($email, $request) {
            $loginCode = LoginCode::where('email', $email)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->latest()
                ->lockForUpdate()
                ->first();

            if (! $loginCode || ! Hash::check($request->code, $loginCode->code)) {
                return null;
            }

            // Re-check used_at after lock (defence-in-depth) and mark as used.
            if ($loginCode->used_at !== null) {
                return null;
            }

            $loginCode->update(['used_at' => now()]);

            return $loginCode;
        });

        if (! $verifiedCode) {
            RateLimiter::hit($key, 900);
            RateLimiter::hit($ipKey, 3600);

            return back()
                ->withInput($request->only('email'))
                ->withErrors([
                    'code' => __('auth.login_code_invalid'),
                ]);
        }

        RateLimiter::clear($key);
        RateLimiter::clear('login-code-send:'.$email);

        $user = User::where('email', $email)->first();

        if (! $user) {
            return back()->withErrors(['code' => __('auth.login_code_invalid')]);
        }

        // Email-code login implicitly verifies the email address.
        if (! $user->email_verified_at) {
            $user->update(['email_verified_at' => now()]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        AnalyticsService::transferGuestDataToUser($user->id);
        AnalyticsService::log('user_logged_in', [
            'method'  => 'email_code',
            'user_id' => $user->id,
        ]);

        $redirectUrl = $request->session()->pull('url.intended', route('dashboard'));
        $redirectUrl = $this->validateRedirectUrl($redirectUrl);

        // M5: force 2FA challenge for users with 2FA enabled. Don't rely on
        // per-route middleware to catch it after this passwordless login.
        if (method_exists($user, 'hasTwoFactorEnabled') && $user->hasTwoFactorEnabled()) {
            $request->session()->put('two_factor_intended_url', $redirectUrl);
            return redirect()->route('two-factor.challenge');
        }

        return redirect($redirectUrl);
    }
}
