<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\ValidatesRedirectUrl;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Invitation;
use App\Services\AnalyticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    use ValidatesRedirectUrl;

    /**
     * Display the login view.
     */
    public function create(): View
    {
        // Log analytics event for login page view
        AnalyticsService::log('user_login_page_viewed', [
            'page_type' => 'auth_flow',
        ]);

        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        AnalyticsService::transferGuestDataToUser(Auth::id());
        AnalyticsService::log('user_logged_in', [
            'page_type' => 'auth_flow',
        ]);

        // Handle invitation auto-accept after login
        $invitationToken = $request->input('invitation_token');
        if ($invitationToken) {
            $invitation = Invitation::where('token', $invitationToken)
                ->pending()
                ->first();

            if ($invitation && $invitation->email === Auth::user()->email) {
                // Check if user is not already a member
                $alreadyMember = $invitation->organization->users()
                    ->where('user_id', Auth::id())
                    ->exists();

                if (! $alreadyMember) {
                    // Add user to organization immediately
                    $invitation->organization->users()->attach(Auth::id(), [
                        'role' => $invitation->role,
                        'joined_at' => now(),
                    ]);

                    // Mark invitation as accepted
                    $invitation->markAsAccepted();

                    // Track analytics
                    AnalyticsService::log('organization_invitation_accepted', [
                        'organization_id' => $invitation->organization_id,
                        'user_id' => Auth::id(),
                        'days_to_accept' => $invitation->created_at->diffInDays(now()),
                        'was_new_user' => false,
                        'via' => 'login',
                    ]);

                    Log::info('Organization invitation auto-accepted after login', [
                        'user_id' => Auth::id(),
                        'invitation_id' => $invitation->id,
                        'organization_id' => $invitation->organization_id,
                    ]);

                    return redirect()->route('profile.organization.users')
                        ->with('status', 'invitation-accepted');
                }
            }
        }

        // Check for redirect parameter from login link
        $redirectUrl = $request->input('redirect') ?: $request->session()->get('url.intended', route('dashboard'));

        // Validate redirect URL to prevent open redirect attacks
        $redirectUrl = $this->validateRedirectUrl($redirectUrl);

        return redirect($redirectUrl);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        AnalyticsService::log('user_logged_out', [
            'page_type' => 'auth_flow',
        ]);

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }

}
