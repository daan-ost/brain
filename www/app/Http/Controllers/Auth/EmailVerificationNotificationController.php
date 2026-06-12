<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportException;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended('/');
        }

        try {
            $request->user()->sendEmailVerificationNotification();

            AnalyticsService::log('email_verification_resent', [
                'user_id' => $request->user()->id,
            ]);

            return back()->with('status', 'verification-link-sent');
        } catch (TransportException $e) {
            // Log the error for debugging
            Log::error('Email verification notification failed', [
                'user_id' => $request->user()->id,
                'email' => $request->user()->email,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            // Check if it's an inactive recipient error (Postmark code 406)
            if (str_contains($e->getMessage(), 'inactive') || $e->getCode() === 406) {
                return back()->withErrors([
                    'email' => __('auth.email_cannot_receive'),
                ]);
            }

            // Generic email sending error
            return back()->withErrors([
                'email' => __('auth.email_send_failed'),
            ]);
        } catch (\Exception $e) {
            // Catch any other unexpected errors
            Log::error('Unexpected error sending verification email', [
                'user_id' => $request->user()->id,
                'email' => $request->user()->email,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors([
                'email' => __('auth.unexpected_error'),
            ]);
        }
    }
}
