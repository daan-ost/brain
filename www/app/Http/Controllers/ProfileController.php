<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\AnalyticsService;
use App\Services\EmailChangeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): RedirectResponse
    {
        return redirect()->route('profile.account');
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, EmailChangeService $emailChangeService): RedirectResponse
    {
        $user = $request->user();
        $originalData = $user->toArray();

        // Check if email is being changed
        $emailChanged = false;
        $newEmail = $request->input('email');

        if ($newEmail && $newEmail !== $user->email) {
            // Use EmailChangeService instead of direct update
            $result = $emailChangeService->requestEmailChange($user, $newEmail);

            if (! $result['success']) {
                return back()->withErrors(['email' => $result['message']]);
            }

            $emailChanged = true;

            // Remove email from validated data (don't update directly)
            $validated = $request->safe()->except(['email']);
        } else {
            $validated = $request->validated();
        }

        // Update other fields (excluding email)
        $user->fill($validated);
        $user->save();

        // Track profile update
        AnalyticsService::log('profile_update', [
            'fields_changed' => array_keys($user->getChanges()),
            'email_change_requested' => $emailChanged,
            'pending_email' => $emailChanged ? $newEmail : null,
        ]);

        if ($emailChanged) {
            return Redirect::route('profile.account')->with('status', 'email-change-pending');
        }

        return Redirect::route('profile.account')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // Track account deletion before deleting
        AnalyticsService::log('account_delete', [
            'user_email' => $user->email,
            'account_age_days' => $user->created_at->diffInDays(now()),
            'organizations_count' => $user->organizations()->count(),
        ]);

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    /**
     * Display the user's account information.
     */
    public function account(Request $request): View
    {
        // Track profile page view
        AnalyticsService::log('profile_view', [
            'page_type' => 'account',
            'user_has_organizations' => $request->user()->organizations()->exists(),
        ]);

        return view('profile.account', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Display the user's password form.
     */
    public function password(Request $request): View
    {
        return view('profile.password', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Display the user's API tokens management page.
     */
    public function apiTokens(Request $request): View
    {
        // Track API tokens page view
        AnalyticsService::log('profile_view', [
            'page_type' => 'api_tokens',
            'existing_tokens_count' => $request->user()->tokens()->count(),
        ]);

        return view('profile.api-tokens');
    }

    /**
     * Display the user's webhooks management page.
     */
    public function webhooks(Request $request): View
    {
        AnalyticsService::log('profile_view', [
            'page_type' => 'webhooks',
            'existing_webhooks_count' => $request->user()->webhooks()->count(),
        ]);

        return view('profile.webhooks');
    }

    /**
     * Display the user's organization information.
     *
     * @deprecated This method has been moved to Organization\OrganizationController::show()
     *             Will be removed in next major version.
     */
    public function organization(Request $request): RedirectResponse
    {
        return redirect()->action([Organization\OrganizationController::class, 'show']);
    }
}
