<?php

namespace App\Http\Controllers;

use App\Services\EmailChangeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmailChangeController extends Controller
{
    public function __construct(
        protected EmailChangeService $emailChangeService
    ) {}

    /**
     * Verify email change via token
     */
    public function verify(Request $request, string $token): RedirectResponse
    {
        $user = Auth::user();

        if (! $user) {
            return redirect()->route('login')
                ->with('error', 'Please log in to verify email change.');
        }

        $result = $this->emailChangeService->verifyEmailChange($user, $token);

        if ($result['success']) {
            return redirect()->route('profile.account')
                ->with('status', 'email-changed')
                ->with('message', $result['message']);
        }

        return redirect()->route('profile.account')
            ->with('error', $result['message']);
    }

    /**
     * Cancel pending email change
     */
    public function cancel(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if ($this->emailChangeService->cancelEmailChange($user)) {
            return redirect()->route('profile.account')
                ->with('status', 'email-change-cancelled');
        }

        // Graceful handling: no pending change (already completed or cancelled)
        return redirect()->route('profile.account')
            ->with('info', 'No pending email change to cancel.');
    }

    /**
     * Resend verification email
     */
    public function resend(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->hasPendingEmailChange()) {
            return back()->with('error', 'No pending email change found.');
        }

        // Re-request email change with the pending email
        $result = $this->emailChangeService->requestEmailChange(
            $user,
            $user->pending_email
        );

        if ($result['success']) {
            return back()->with('status', 'verification-resent');
        }

        return back()->with('error', $result['message']);
    }
}
