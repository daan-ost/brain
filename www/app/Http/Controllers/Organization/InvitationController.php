<?php

namespace App\Http\Controllers\Organization;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Mail\OrganizationInvitation;
use App\Models\Invitation;
use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Enum;
use Illuminate\View\View;

class InvitationController extends Controller
{
    /**
     * Send an invitation to join the organization
     */
    public function store(Request $request): RedirectResponse
    {
        $currentUser = $request->user();

        // Validate email
        $request->validate([
            'email' => 'required|email',
            'role'  => ['sometimes', new Enum(OrganizationRole::class)],
        ]);

        $email = $request->input('email');
        $role = $request->input('role', OrganizationRole::Editor->value);

        // Get current user's organizations
        $organizations = $currentUser->organizations()->get();

        if ($organizations->isEmpty()) {
            return redirect()->route('profile.organization.users')->with('error', 'You are not a member of any organization.');
        }

        // Check if current user is admin
        $isAdmin = $organizations->contains(function ($org) {
            return $org->pivot->role === OrganizationRole::Owner;
        });

        if (! $isAdmin) {
            return redirect()->route('profile.organization.users')->with('error', 'You do not have permission to invite users.');
        }

        $currentOrganization = $organizations->first(fn ($org) => $org->pivot->role === OrganizationRole::Owner);

        // Check if email is already a member
        $existingMember = $currentOrganization->users()->where('email', $email)->exists();
        if ($existingMember) {
            return redirect()->route('profile.organization.users')->with('error', 'This user is already a member of the organization.');
        }

        // Check if there's already a pending invitation
        $pendingInvitation = Invitation::where('organization_id', $currentOrganization->id)
            ->where('email', $email)
            ->pending()
            ->first();

        if ($pendingInvitation) {
            return redirect()->route('profile.organization.users')->with('error', 'An invitation has already been sent to this email address.');
        }

        // Create the invitation
        $invitation = Invitation::create([
            'organization_id' => $currentOrganization->id,
            'email' => $email,
            'invited_by' => $currentUser->id,
            'role' => $role,
            'status' => 'pending',
        ]);

        // Send invitation email
        Mail::to($email)->send(new OrganizationInvitation($invitation));

        // Track analytics
        AnalyticsService::log('organization_invitation_sent', [
            'organization_id' => $currentOrganization->id,
            'invited_email' => $email,
            'invited_by_user_id' => $currentUser->id,
            'user_exists' => User::where('email', $email)->exists(),
            'role' => $role,
        ]);

        return redirect()->route('profile.organization.users')->with('status', 'invitation-sent');
    }

    /**
     * Show the invitation accept page (public)
     */
    public function show(string $token): View|RedirectResponse
    {
        $invitation = Invitation::where('token', $token)->first();

        if (! $invitation) {
            abort(404, 'Invitation not found.');
        }

        // Set locale based on invited user's language preference (if they exist)
        $invitedUser = User::where('email', $invitation->email)->first();
        if ($invitedUser && $invitedUser->preferred_language) {
            app()->setLocale($invitedUser->preferred_language);
        }

        // Check if expired
        if ($invitation->isExpired()) {
            return view('invitations.accept', [
                'invitation' => $invitation,
                'error' => 'This invitation has expired.',
            ]);
        }

        // Check if already accepted
        if ($invitation->status === 'accepted') {
            return redirect()->route('profile.organization.users')->with('info', 'This invitation has already been accepted.');
        }

        // Check if revoked
        if ($invitation->status === 'revoked') {
            return view('invitations.accept', [
                'invitation' => $invitation,
                'error' => 'This invitation has been revoked.',
            ]);
        }

        return view('invitations.accept', [
            'invitation' => $invitation,
        ]);
    }

    /**
     * Accept the invitation (authenticated user)
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $currentUser = $request->user();
        $invitation = Invitation::where('token', $token)->first();

        if (! $invitation) {
            abort(404, 'Invitation not found.');
        }

        // Check if expired
        if ($invitation->isExpired()) {
            return redirect()->route('invitations.accept.show', $token)->with('error', 'This invitation has expired.');
        }

        // Check if already accepted
        if ($invitation->status === 'accepted') {
            return redirect()->route('profile.organization.users')->with('info', 'This invitation has already been accepted.');
        }

        // Check if revoked
        if ($invitation->status === 'revoked') {
            return redirect()->route('invitations.accept.show', $token)->with('error', 'This invitation has been revoked.');
        }

        // Verify email matches
        if ($currentUser->email !== $invitation->email) {
            return redirect()->route('invitations.accept.show', $token)->with('error', 'This invitation was sent to a different email address.');
        }

        // Add user to organization — gebruik altijd de string-waarde zodat dit
        // ook correct werkt als Invitation->role ooit naar enum-cast migreert.
        $role = $invitation->role instanceof OrganizationRole
            ? $invitation->role->value
            : $invitation->role;

        $invitation->organization->users()->attach($currentUser->id, [
            'role'      => $role,
            'joined_at' => now(),
        ]);

        // Mark invitation as accepted
        $invitation->markAsAccepted();

        // Track analytics
        AnalyticsService::log('organization_invitation_accepted', [
            'organization_id' => $invitation->organization_id,
            'user_id' => $currentUser->id,
            'days_to_accept' => $invitation->created_at->diffInDays(now()),
            'was_new_user' => $currentUser->created_at->diffInMinutes(now()) < 30,
        ]);

        return redirect()->route('profile.organization.users')->with('status', 'invitation-accepted');
    }

    /**
     * Revoke an invitation
     */
    public function revoke(Request $request, Invitation $invitation): RedirectResponse
    {
        $currentUser = $request->user();

        // Get current user's organizations
        $organizations = $currentUser->organizations()->get();

        // Check if current user is admin in the invitation's organization
        $isAdmin = $organizations->contains(function ($org) use ($invitation) {
            return $org->id === $invitation->organization_id && $org->pivot->role === OrganizationRole::Owner;
        });

        if (! $isAdmin) {
            return redirect()->route('profile.organization.users')->with('error', 'You do not have permission to revoke invitations.');
        }

        // Mark as revoked
        $invitation->markAsRevoked();

        // Track analytics
        AnalyticsService::log('organization_invitation_revoked', [
            'organization_id' => $invitation->organization_id,
            'invited_email' => $invitation->email,
            'revoked_by_user_id' => $currentUser->id,
        ]);

        return redirect()->route('profile.organization.users')->with('status', 'invitation-revoked');
    }

    /**
     * Resend an invitation email
     */
    public function resend(Request $request, Invitation $invitation): RedirectResponse
    {
        $currentUser = $request->user();

        // Get current user's organizations
        $organizations = $currentUser->organizations()->get();

        // Check if current user is admin in the invitation's organization
        $isAdmin = $organizations->contains(function ($org) use ($invitation) {
            return $org->id === $invitation->organization_id && $org->pivot->role === OrganizationRole::Owner;
        });

        if (! $isAdmin) {
            return redirect()->route('profile.organization.users')->with('error', 'You do not have permission to resend invitations.');
        }

        // Check if invitation is still pending
        if (! $invitation->isPending()) {
            return redirect()->route('profile.organization.users')->with('error', 'This invitation is no longer pending.');
        }

        // Extend expiration
        $invitation->extendExpiration(7);

        // Resend email
        Mail::to($invitation->email)->send(new OrganizationInvitation($invitation));

        // Track analytics
        AnalyticsService::log('organization_invitation_resent', [
            'organization_id' => $invitation->organization_id,
            'invited_email' => $invitation->email,
            'resent_by_user_id' => $currentUser->id,
        ]);

        return redirect()->route('profile.organization.users')->with('status', 'invitation-resent');
    }
}
