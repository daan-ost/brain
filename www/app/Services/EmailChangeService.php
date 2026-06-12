<?php

namespace App\Services;

use App\Jobs\SendPostmarkTemplateEmail;
use App\Models\User;
use Illuminate\Support\Str;

class EmailChangeService
{
    /**
     * Initiate email change request
     */
    public function requestEmailChange(User $user, string $newEmail): array
    {
        // Validation: Rate limiting
        if (! $user->canRequestEmailChange()) {
            return [
                'success' => false,
                'message' => 'Please wait before requesting another email change.',
            ];
        }

        // Check if new email is already in use
        if (User::where('email', $newEmail)->where('id', '!=', $user->id)->exists()) {
            return [
                'success' => false,
                'message' => 'This email address is already in use.',
            ];
        }

        // Check if new email is same as current
        if ($user->email === $newEmail) {
            return [
                'success' => false,
                'message' => 'New email is the same as current email.',
            ];
        }

        // Generate secure token
        $token = Str::random(64);
        $expiresAt = now()->addHours(24);

        // Save pending change
        $user->update([
            'pending_email' => $newEmail,
            'email_change_token' => hash('sha256', $token),
            'email_change_requested_at' => now(),
            'email_change_token_expires_at' => $expiresAt,
            'last_email_change_request_at' => now(),
        ]);

        // Get user's locale
        $locale = $user->preferred_language ?? app()->getLocale();
        if (! in_array($locale, ['en', 'nl'])) {
            $locale = 'en';
        }

        // Send verification email to NEW address
        $verificationUrl = route('email.change.verify', ['token' => $token]);

        SendPostmarkTemplateEmail::dispatch(
            templateAlias: "email-change-verification__{$locale}",
            templateModel: [
                'user_name' => $user->name,
                'verification_url' => $verificationUrl,
                'expires_at' => $expiresAt->format('F j, Y \a\t g:i A'),
                'current_email' => $user->email,
            ],
            to: $newEmail,
            toName: $user->name,
            tag: 'email-change-verification',
            messageStream: 'outbound'
        );

        // Send notification to OLD address (security alert)
        $cancelUrl = route('email.change.cancel');

        SendPostmarkTemplateEmail::dispatch(
            templateAlias: "email-change-notification__{$locale}",
            templateModel: [
                'user_name' => $user->name,
                'current_email' => $user->email,
                'new_email' => $newEmail,
                'cancel_url' => $cancelUrl,
            ],
            to: $user->email,
            toName: $user->name,
            tag: 'email-change-notification',
            messageStream: 'outbound'
        );

        // Analytics
        AnalyticsService::log('email_change_requested', [
            'old_email' => $user->email,
            'new_email_domain' => substr($newEmail, strpos($newEmail, '@')),
        ]);

        return [
            'success' => true,
            'message' => 'Verification email sent to new address.',
            'pending_email' => $newEmail,
        ];
    }

    /**
     * Verify and complete email change
     */
    public function verifyEmailChange(User $user, string $token): array
    {
        // Validate pending change exists
        if (! $user->hasPendingEmailChange()) {
            return [
                'success' => false,
                'message' => 'No pending email change found.',
            ];
        }

        // Validate token
        if ($user->email_change_token !== hash('sha256', $token)) {
            return [
                'success' => false,
                'message' => 'Invalid verification token.',
            ];
        }

        // Check if pending email is still available (race condition check)
        if (User::where('email', $user->pending_email)->where('id', '!=', $user->id)->exists()) {
            $user->cancelPendingEmailChange();

            return [
                'success' => false,
                'message' => 'Email address is no longer available.',
            ];
        }

        // Execute email change
        $oldEmail = $user->email;
        $newEmail = $user->pending_email;

        $user->update([
            'email' => $newEmail,
            'email_verified_at' => now(),
            'pending_email' => null,
            'email_change_token' => null,
            'email_change_requested_at' => null,
            'email_change_token_expires_at' => null,
        ]);

        // Send confirmation to OLD email address
        $locale = $user->preferred_language ?? app()->getLocale();
        if (! in_array($locale, ['en', 'nl'])) {
            $locale = 'en';
        }

        $profileUrl = route('profile.account');

        SendPostmarkTemplateEmail::dispatch(
            templateAlias: "email-change-completed__{$locale}",
            templateModel: [
                'user_name' => $user->name,
                'old_email' => $oldEmail,
                'new_email' => $newEmail,
                'profile_url' => $profileUrl,
                'completed_at' => now()->format('F j, Y \a\t g:i A'),
            ],
            to: $oldEmail,
            toName: $user->name,
            tag: 'email-change-completed',
            messageStream: 'outbound'
        );

        // Analytics
        AnalyticsService::log('email_change_completed', [
            'old_email' => $oldEmail,
            'new_email' => $newEmail,
        ]);

        return [
            'success' => true,
            'message' => 'Email successfully changed and verified.',
            'old_email' => $oldEmail,
            'new_email' => $newEmail,
        ];
    }

    /**
     * Cancel pending email change
     */
    public function cancelEmailChange(User $user): bool
    {
        if (! $user->hasPendingEmailChange()) {
            return false;
        }

        $pendingEmail = $user->pending_email;

        $user->cancelPendingEmailChange();

        // Analytics
        AnalyticsService::log('email_change_cancelled', [
            'pending_email' => $pendingEmail,
        ]);

        return true;
    }
}
