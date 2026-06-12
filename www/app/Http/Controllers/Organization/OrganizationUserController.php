<?php

namespace App\Http\Controllers\Organization;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Organization\Traits\OrganizationAccess;
use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrganizationUserController extends Controller
{
    use OrganizationAccess;

    /**
     * Display organization team members.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $context = $this->getOrganizationContext($user);

        if (! $context->isMember) {
            return view('profile.organization-users', [
                'user' => $user,
                'organizationUsers' => collect(),
                'organizations' => $context->organizations,
                'isAdmin' => false,
            ]);
        }

        if (! $context->isAdmin) {
            return redirect()->route('profile.organization')->with('error', 'You do not have permission to view organization users.');
        }

        $organizationUsers = $context->currentOrganization->users()->get();
        $pendingInvitations = $context->currentOrganization->invitations()
            ->pending()
            ->with('invitedBy')
            ->get();

        AnalyticsService::log('organization_members_view', [
            'organization_id' => $context->currentOrganization->id,
            'is_admin' => $context->isAdmin,
            'member_count' => $organizationUsers->count(),
            'pending_invitations_count' => $pendingInvitations->count(),
        ]);

        return view('profile.organization-users', [
            'user' => $user,
            'organizationUsers' => $organizationUsers,
            'organizations' => $context->organizations,
            'currentOrganization' => $context->currentOrganization,
            'isAdmin' => $context->isAdmin,
            'pendingInvitations' => $pendingInvitations,
        ]);
    }

    /**
     * Make a user admin in the organization.
     */
    public function makeAdmin(Request $request, User $user): RedirectResponse
    {
        $currentUser = $request->user();
        $context = $this->getOrganizationContext($currentUser);

        if (! $context->isMember) {
            return redirect()->route('profile.organization')->with('error', 'You are not a member of any organization.');
        }

        if (! $context->isAdmin) {
            return redirect()->route('profile.organization')->with('error', 'You do not have permission to manage user roles.');
        }

        if ($user->id === $currentUser->id) {
            return redirect()->route('profile.organization.users')->with('error', 'You cannot change your own role.');
        }

        if (! $this->isOrganizationMember($user, $context->currentOrganization)) {
            return redirect()->route('profile.organization.users')->with('error', 'User is not a member of this organization.');
        }

        $context->currentOrganization->users()->updateExistingPivot($user->id, [
            'role' => OrganizationRole::Owner,
        ]);

        AnalyticsService::log('organization_member_promoted', [
            'organization_id' => $context->currentOrganization->id,
            'promoted_user_id' => $user->id,
            'promoted_by_user_id' => $currentUser->id,
            'new_role' => OrganizationRole::Owner->value,
        ]);

        return redirect()->route('profile.organization.users')->with('status', 'user-made-admin');
    }

    /**
     * Demote a user from admin to member in the organization.
     */
    public function demoteToMember(Request $request, User $user): RedirectResponse
    {
        $currentUser = $request->user();
        $context = $this->getOrganizationContext($currentUser);

        if (! $context->isMember) {
            return redirect()->route('profile.organization')->with('error', 'You are not a member of any organization.');
        }

        if (! $context->isAdmin) {
            return redirect()->route('profile.organization')->with('error', 'You do not have permission to manage user roles.');
        }

        if ($user->id === $currentUser->id) {
            return redirect()->route('profile.organization.users')->with('error', 'You cannot change your own role.');
        }

        if (! $this->isOrganizationMember($user, $context->currentOrganization)) {
            return redirect()->route('profile.organization.users')->with('error', 'User is not a member of this organization.');
        }

        $context->currentOrganization->users()->updateExistingPivot($user->id, [
            'role' => OrganizationRole::Editor,
        ]);

        AnalyticsService::log('organization_member_demoted', [
            'organization_id' => $context->currentOrganization->id,
            'demoted_user_id' => $user->id,
            'demoted_by_user_id' => $currentUser->id,
            'new_role' => OrganizationRole::Editor->value,
        ]);

        return redirect()->route('profile.organization.users')->with('status', 'user-made-member');
    }

    /**
     * Remove a user from the organization.
     */
    public function removeUser(Request $request, User $user): RedirectResponse
    {
        $currentUser = $request->user();
        $context = $this->getOrganizationContext($currentUser);

        if (! $context->isMember) {
            return redirect()->route('profile.organization')->with('error', 'You are not a member of any organization.');
        }

        if (! $context->isAdmin) {
            return redirect()->route('profile.organization')->with('error', 'You do not have permission to remove users.');
        }

        if ($user->id === $currentUser->id) {
            return redirect()->route('profile.organization.users')->with('error', 'You cannot remove yourself from the organization.');
        }

        if (! $this->isOrganizationMember($user, $context->currentOrganization)) {
            return redirect()->route('profile.organization.users')->with('error', 'User is not a member of this organization.');
        }

        $userRole = $this->getOrganizationRole($user, $context->currentOrganization);

        if ($userRole === OrganizationRole::Owner) {
            $adminCount = $context->currentOrganization->users()
                ->wherePivot('role', OrganizationRole::Owner)
                ->count();

            if ($adminCount <= 1) {
                return redirect()->route('profile.organization.users')
                    ->with('error', 'Cannot remove the last admin. Promote another member to admin first.');
            }
        }

        AnalyticsService::log('organization_member_removed', [
            'organization_id' => $context->currentOrganization->id,
            'removed_user_id' => $user->id,
            'removed_by_user_id' => $currentUser->id,
            'removed_user_role' => $userRole,
        ]);

        $context->currentOrganization->users()->detach($user->id);

        return redirect()->route('profile.organization.users')->with('status', 'user-removed');
    }
}
