<?php

namespace App\Http\Controllers;

use App\Models\CreditLedger;
use App\Models\Invitation;
use App\Models\License;
use App\Models\User;
use App\Models\UserLicense;
use App\Notifications\SetPasswordEmail;
use App\Notifications\WelcomeConfirmEmail;
use App\Services\AnalyticsService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class EmailConfirmationController extends Controller
{
    public function confirm(Request $request, User $user)
    {
        // Set app locale based on user's preferred language
        if ($user->preferred_language) {
            app()->setLocale($user->preferred_language);
        }

        Log::info('Email confirmation attempt', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'request_hash' => $request->route('hash'),
            'expected_hash' => sha1($user->email),
            'has_valid_signature' => URL::hasValidSignature($request),
            'locale' => app()->getLocale(),
        ]);

        if (! URL::hasValidSignature($request)) {
            Log::warning('Email confirmation failed: Invalid signature', ['user_id' => $user->id]);
            abort(403, 'Invalid or expired confirmation link.');
        }

        $hash = sha1($user->email);
        if ($hash !== $request->route('hash')) {
            Log::warning('Email confirmation failed: Hash mismatch', [
                'user_id' => $user->id,
                'expected' => $hash,
                'received' => $request->route('hash'),
            ]);
            abort(403, 'Invalid confirmation link.');
        }

        if ($user->isEmailConfirmed()) {
            Log::info('Email already confirmed', ['user_id' => $user->id]);

            return redirect()->route('dashboard')->with('message', 'Your email is already confirmed.');
        }

        $user->update([
            'email_verified_at' => now(),
            'email_bounced_at' => null,
            'email_bounce_type' => null,
            'email_bounce_reason' => null,
        ]);

        Log::info('Email confirmed successfully', [
            'user_id' => $user->id,
            'email_verified_at' => $user->fresh()->email_verified_at,
        ]);

        // Login user if not already authenticated (e.g., clicked email link in different browser)
        // This ensures subsequent analytics events have user_id instead of guest_sid
        if (! auth()->check() || auth()->id() !== $user->id) {
            auth()->login($user);
            request()->session()->regenerate();
        }

        // Transfer guest analytics to user account (identity resolution moment)
        // This captures all pre-confirmation activity under the verified user
        AnalyticsService::transferGuestDataToUser($user->id);

        // Log analytics event for email confirmation
        AnalyticsService::log('user_email_confirmed', [
            'user_id' => $user->id,
            'page_type' => 'user_flow',
        ]);

        // Assign free license after email confirmation (if pending)
        if ($user->pending_license_assignment) {
            $this->assignFreeRegistrationLicense($user);
        }

        // NOTE: Pending batches are NOT auto-started here anymore
        // User must click "Start Converting" button to initiate conversion
        // This ensures conversion_started analytics event fires at the right time

        // Auto-accept pending invitation if exists
        $acceptedInvitation = null;
        $invitationToken = session('pending_invitation_token');
        if ($invitationToken) {
            $invitation = Invitation::where('token', $invitationToken)
                ->pending()
                ->first();

            if ($invitation && $invitation->email === $user->email) {
                // Add user to organization
                $invitation->organization->users()->attach($user->id, [
                    'role' => $invitation->role,
                    'joined_at' => now(),
                ]);

                // Mark invitation as accepted
                $invitation->markAsAccepted();

                // Track analytics
                AnalyticsService::log('organization_invitation_accepted', [
                    'organization_id' => $invitation->organization_id,
                    'user_id' => $user->id,
                    'days_to_accept' => $invitation->created_at->diffInDays(now()),
                    'was_new_user' => true,
                    'auto_accepted' => true,
                ]);

                // Store invitation for display to user
                $acceptedInvitation = $invitation;

                // Clear the session
                session()->forget('pending_invitation_token');

                Log::info('Organization invitation auto-accepted after email verification', [
                    'user_id' => $user->id,
                    'invitation_id' => $invitation->id,
                    'organization_id' => $invitation->organization_id,
                ]);
            }
        }

        // Perform auto-enrollment inline (so we can show results to user immediately)
        // This happens AFTER invitation acceptance so invitation role takes priority
        // IMPORTANT: We do this BEFORE firing Verified event to capture the enrollment results
        $enrollmentService = app(\App\Services\OrganizationAutoEnrollmentService::class);
        $autoEnrolledOrganizations = $enrollmentService->enrollUser($user);

        // Fire Verified event (for other listeners that may depend on it)
        // This happens AFTER our enrollment so we already have the data for the view
        if ($user->hasVerifiedEmail()) {
            event(new Verified($user));

            Log::info('Verified event fired', [
                'user_id' => $user->id,
                'email' => $user->email,
                'email_domain' => substr(strrchr($user->email, '@'), 1),
            ]);
        }

        // Send password setup email (if user doesn't have a real password yet)
        if (! $user->last_login_at) {
            $locale = $user->preferred_language ?? app()->getLocale();
            $user->notify(new SetPasswordEmail($locale));

            Log::info('Password setup email sent after email confirmation', [
                'user_id' => $user->id,
                'locale' => $locale,
            ]);
        }

        return view('auth.email-confirmed', [
            'user' => $user->fresh(),
            'acceptedInvitation' => $acceptedInvitation,
            'autoEnrolledOrganizations' => $autoEnrolledOrganizations,
        ]);
    }

    public function resend(Request $request)
    {
        $user = $request->user();

        if ($user->isEmailConfirmed()) {
            return back()->with('message', 'Your email is already confirmed.');
        }

        if (! $user->canResendConfirmation()) {
            throw ValidationException::withMessages([
                'email' => 'Please wait at least 1 minute before requesting another confirmation email.',
            ]);
        }

        $locale = app()->getLocale();

        $user->notify(new WelcomeConfirmEmail($locale));

        $user->update([
            'last_confirmation_sent_at' => now(),
        ]);

        return back()->with('message', 'Confirmation email sent! Please check your inbox.');
    }

    public function changeEmail(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        $user->update([
            'email' => $request->email,
            'email_verified_at' => null,
            'email_bounced_at' => null,
            'email_bounce_type' => null,
            'email_bounce_reason' => null,
            'last_confirmation_sent_at' => now(),
        ]);

        $locale = app()->getLocale();
        $user->notify(new WelcomeConfirmEmail($locale));

        return back()->with('message', 'Email updated! Please check your new email address for confirmation.');
    }

    /**
     * Assign free registration license to user after email confirmation
     */
    private function assignFreeRegistrationLicense(User $user): void
    {
        try {
            // Use the same license assignment pattern as AssignMissingRegistrationCredits command
            $config = config('licenses.free_registration');
            $licenseSlug = $config['slug'];

            // Get the license using the same pattern as the command
            $freeRegistrationLicense = License::where('slug', $licenseSlug)
                ->where('active', true)
                ->first();

            if (! $freeRegistrationLicense) {
                Log::warning('Free registration license not found or inactive during email confirmation', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'license_slug' => $licenseSlug,
                ]);

                return;
            }

            // Check if user already has this license to avoid duplicates
            $existingLicense = UserLicense::where('user_id', $user->id)
                ->where('license_id', $freeRegistrationLicense->id)
                ->where('source', 'system_signup')
                ->exists();

            if ($existingLicense) {
                Log::info('User already has free registration license', [
                    'user_id' => $user->id,
                    'license_slug' => $licenseSlug,
                ]);

                return;
            }

            $creditsToAssign = (int) $freeRegistrationLicense->credits;

            if ($creditsToAssign <= 0) {
                Log::info('Free registration license has no credits to assign', [
                    'user_id' => $user->id,
                    'license_credits' => $creditsToAssign,
                ]);

                return;
            }

            // Use exact same transaction pattern as the command
            DB::transaction(function () use ($user, $freeRegistrationLicense, $creditsToAssign) {
                $timestamp = now();

                // Create user license record (same as command)
                UserLicense::create([
                    'user_id' => $user->id,
                    'license_id' => $freeRegistrationLicense->id,
                    'status' => 'active',
                    'starts_at' => $timestamp,
                    'ends_at' => $timestamp->copy()->addDays(15), // Set 15-day expiration
                    'source' => 'system_signup',
                    'external_ref' => null,
                    'is_current' => true,
                ]);

                // Update user's credits (same as command)
                $user->update([
                    'credits' => $creditsToAssign,
                    'credits_updated_at' => $timestamp,
                    'pending_license_assignment' => false, // Clear the flag
                ]);

                // Create ledger entry (same as command)
                CreditLedger::create([
                    'user_id' => $user->id,
                    'delta' => $creditsToAssign,
                    'reason' => 'purchase',
                    'balance_after' => $creditsToAssign,
                    'meta' => [
                        'license_id' => $freeRegistrationLicense->id,
                        'license_slug' => $freeRegistrationLicense->slug,
                        'license_name' => $freeRegistrationLicense->name,
                        'source' => 'email_confirmation',
                        'registration_bonus' => true,
                    ],
                    'created_at' => $timestamp,
                ]);
            });

            // Log analytics event for free credits assignment
            AnalyticsService::log('free_credits_assigned', [
                'user_id' => $user->id,
                'credits_assigned' => $creditsToAssign,
                'license_id' => $freeRegistrationLicense->id,
                'license_slug' => $freeRegistrationLicense->slug,
                'source' => 'email_confirmation',
                'registration_bonus' => true,
            ]);

            Log::info('Free registration license assigned successfully after email confirmation', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'license_id' => $freeRegistrationLicense->id,
                'credits_assigned' => $creditsToAssign,
                'license_slug' => $freeRegistrationLicense->slug,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to assign free registration license during email confirmation', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            // Don't throw the exception - email confirmation should complete even if license assignment fails
        }
    }

    /**
     * Show password setup form
     */
    public function showPasswordSetup(Request $request, User $user)
    {
        Log::info('Password setup page accessed', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'request_hash' => $request->route('hash'),
            'expected_hash' => sha1($user->email),
            'has_valid_signature' => URL::hasValidSignature($request),
        ]);

        if (! URL::hasValidSignature($request)) {
            Log::warning('Password setup failed: Invalid signature', ['user_id' => $user->id]);
            abort(403, 'Invalid or expired password setup link.');
        }

        $hash = sha1($user->email);
        if ($hash !== $request->route('hash')) {
            Log::warning('Password setup failed: Hash mismatch', [
                'user_id' => $user->id,
                'expected' => $hash,
                'received' => $request->route('hash'),
            ]);
            abort(403, 'Invalid password setup link.');
        }

        // Check if user already has a real password (not random)
        // We can't directly check if password is random, so we check if they've logged in before
        if ($user->last_login_at) {
            Log::info('User already has password set', ['user_id' => $user->id]);

            return redirect()->route('login')->with('message', 'Your password is already set. Please log in.');
        }

        // Log analytics event for password setup page view
        AnalyticsService::log('user_password_setup_viewed', [
            'user_id' => $user->id,
            'page_type' => 'user_flow',
        ]);

        return view('auth.setup-password', ['user' => $user]);
    }

    /**
     * Store new password for user
     */
    public function storePassword(Request $request, User $user)
    {
        Log::info('Password setup attempt', [
            'user_id' => $user->id,
            'user_email' => $user->email,
        ]);

        if (! URL::hasValidSignature($request)) {
            Log::warning('Password setup store failed: Invalid signature', ['user_id' => $user->id]);
            abort(403, 'Invalid or expired password setup link.');
        }

        $hash = sha1($user->email);
        if ($hash !== $request->route('hash')) {
            Log::warning('Password setup store failed: Hash mismatch', ['user_id' => $user->id]);
            abort(403, 'Invalid password setup link.');
        }

        $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // Update user password
        $user->update([
            'password' => Hash::make($request->password),
        ]);

        Log::info('Password set successfully for user', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        // Log analytics event for password setup completion
        AnalyticsService::log('user_password_setup_completed', [
            'user_id' => $user->id,
            'page_type' => 'user_flow',
        ]);

        // Log the user in automatically
        auth()->login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('message', 'Your password has been set successfully! Welcome to ' . config('app.name') . '.');
    }
}
