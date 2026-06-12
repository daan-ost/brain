<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\CreditLedger;
use App\Models\Invitation;
use App\Models\License;
use App\Models\User;
use App\Models\UserLicense;
use App\Notifications\WelcomeConfirmEmail;
use App\Rules\ValidEmailDomain;
use App\Services\AnalyticsService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportException;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(Request $request): View
    {
        $invitation = null;
        $invitationToken = $request->query('invitation');

        // If invitation token provided, fetch the invitation
        if ($invitationToken) {
            $invitation = Invitation::where('token', $invitationToken)
                ->pending()
                ->with('organization')
                ->first();
        }

        return view('auth.register', [
            'invitation' => $invitation,
            'invitationToken' => $invitationToken,
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email:rfc', 'max:255', 'unique:'.User::class, new ValidEmailDomain],
            'password' => ['required', 'confirmed', Rules\Password::min(12)],
            'terms' => ['required', 'accepted'],
        ]);

        // Validate invitation email match if invitation token provided
        $invitationToken = $request->input('invitation_token');
        $invitation = null;

        if ($invitationToken) {
            $invitation = Invitation::where('token', $invitationToken)
                ->pending()
                ->first();

            if ($invitation && $invitation->email !== $request->email) {
                return back()->withInput()->withErrors([
                    'email' => 'This invitation was sent to '.$invitation->email.'. Please use that email address to register.',
                ]);
            }
        }

        // Detect preferred language from session or default
        $preferredLanguage = session('guest_language', app()->getLocale());

        // For invitation-based registrations, skip email verification
        // The user already proved email ownership by clicking the invitation link
        $isInvitationSignup = $invitation && $invitation->email === $request->email;

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'preferred_language' => $preferredLanguage,
            'pending_license_assignment' => ! $isInvitationSignup, // Assign license immediately for invitations
            'last_confirmation_sent_at' => now(),
            'email_verified_at' => $isInvitationSignup ? now() : null, // Skip verification for invitations
        ]);

        // Log user_added event
        AnalyticsService::log('user_added', [
            'source' => $isInvitationSignup ? 'invitation_signup' : 'signup',
            'locale' => $preferredLanguage,
        ]);

        // For invitation signups, assign license immediately and accept invitation
        if ($isInvitationSignup) {
            // Assign free registration license immediately
            $this->assignFreeRegistrationLicense($user);

            // Accept invitation immediately
            $invitation->organization->users()->attach($user->id, [
                'role' => $invitation->role,
                'joined_at' => now(),
            ]);
            $invitation->markAsAccepted();

            // Track analytics
            AnalyticsService::log('organization_invitation_accepted', [
                'organization_id' => $invitation->organization_id,
                'user_id' => $user->id,
                'days_to_accept' => $invitation->created_at->diffInDays(now()),
                'was_new_user' => true,
                'auto_accepted' => true,
            ]);

            Log::info('Organization invitation auto-accepted during registration', [
                'user_id' => $user->id,
                'invitation_id' => $invitation->id,
                'organization_id' => $invitation->organization_id,
            ]);

            event(new Registered($user));
            Auth::login($user);

            // Transfer guest analytics to user account
            AnalyticsService::transferGuestDataToUser($user->id);
            AnalyticsService::log('signup');

            // Redirect directly to organization page with success message
            return redirect()->route('profile.organization.users')
                ->with('status', 'invitation-accepted');
        }

        // Normal registration flow: send email verification
        try {
            $user->notify(new WelcomeConfirmEmail($user->preferred_language));
        } catch (TransportException $e) {
            // Log the error
            Log::error('Welcome email failed during registration', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            // Delete the user since email can't be sent
            $user->delete();

            // Check if it's an inactive recipient error (Postmark code 406)
            if (str_contains($e->getMessage(), 'inactive') || $e->getCode() === 406) {
                return back()->withInput()->withErrors([
                    'email' => __('auth.email_cannot_receive'),
                ]);
            }

            // Generic email sending error
            return back()->withInput()->withErrors([
                'email' => __('auth.email_send_failed'),
            ]);
        } catch (\Exception $e) {
            // Log unexpected errors
            Log::error('Unexpected error during registration', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            // Delete the user
            $user->delete();

            return back()->withInput()->withErrors([
                'email' => __('auth.unexpected_error'),
            ]);
        }

        event(new Registered($user));

        Auth::login($user);

        // Note: transferGuestDataToUser is called during email confirmation
        // This ensures all guest activity (including pre-confirmation) is captured
        AnalyticsService::log('signup');

        // Redirect to email verification page (user must verify email before using app)
        return redirect()->route('verification.notice');
    }

    /**
     * Display the registration success page
     */
    public function success(): View
    {
        $user = auth()->user();

        return view('auth.registration-success', compact('user'));
    }

    /**
     * Assign free registration license to user
     * (Used for invitation-based signups where email is already verified)
     */
    private function assignFreeRegistrationLicense(User $user): void
    {
        try {
            // Use the same license assignment pattern as AssignMissingRegistrationCredits command
            $config = config('licenses.free_registration');
            $licenseSlug = $config['slug'];

            // Get the license
            $freeRegistrationLicense = License::where('slug', $licenseSlug)
                ->where('active', true)
                ->first();

            if (! $freeRegistrationLicense) {
                Log::warning('Free registration license not found or inactive during invitation signup', [
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

            // Use transaction for atomic operation
            DB::transaction(function () use ($user, $freeRegistrationLicense, $creditsToAssign) {
                $timestamp = now();

                // Create user license record
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

                // Update user's credits
                $user->update([
                    'credits' => $creditsToAssign,
                    'credits_updated_at' => $timestamp,
                ]);

                // Create ledger entry
                CreditLedger::create([
                    'user_id' => $user->id,
                    'batch_id' => null,
                    'workflow_id' => null,
                    'delta' => $creditsToAssign,
                    'reason' => 'purchase',
                    'balance_after' => $creditsToAssign,
                    'meta' => [
                        'license_id' => $freeRegistrationLicense->id,
                        'license_slug' => $freeRegistrationLicense->slug,
                        'license_name' => $freeRegistrationLicense->name,
                        'source' => 'invitation_signup',
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
                'source' => 'invitation_signup',
                'registration_bonus' => true,
            ]);

            Log::info('Free registration license assigned successfully during invitation signup', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'license_id' => $freeRegistrationLicense->id,
                'credits_assigned' => $creditsToAssign,
                'license_slug' => $freeRegistrationLicense->slug,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to assign free registration license during invitation signup', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            // Don't throw the exception - registration should complete even if license assignment fails
        }
    }
}
