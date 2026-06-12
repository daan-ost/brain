<?php

namespace App\Http\Controllers\Organization\Traits;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Trait for common organization access logic.
 *
 * Provides shared functionality for checking organization membership,
 * admin status, and retrieving the current organization.
 */
trait OrganizationAccess
{
    /**
     * Get organization context for a user.
     *
     * Returns an object with:
     * - organizations: Collection of user's organizations
     * - currentOrganization: The first organization (or null)
     * - isAdmin: Whether user is admin in any organization
     * - isMember: Whether user has any organization
     *
     * @param  array  $with  Relations to eager load on organizations
     */
    protected function getOrganizationContext(User $user, array $with = []): object
    {
        $query = $user->organizations();

        if (! empty($with)) {
            $query->with($with);
        }

        $organizations = $query->get();

        $isAdmin = $organizations->contains(function ($org) {
            return $org->pivot->role === OrganizationRole::Owner;
        });

        return (object) [
            'organizations' => $organizations,
            'currentOrganization' => $organizations->first(),
            'isAdmin' => $isAdmin,
            'isMember' => $organizations->isNotEmpty(),
        ];
    }

    /**
     * Check if user is admin of a specific organization.
     */
    protected function isOrganizationAdmin(User $user, Organization $organization): bool
    {
        $membership = $organization->users()
            ->where('user_id', $user->id)
            ->first();

        return $membership && $membership->pivot->role === OrganizationRole::Owner;
    }

    /**
     * Check if user is member of a specific organization.
     */
    protected function isOrganizationMember(User $user, Organization $organization): bool
    {
        return $organization->users()
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Get the user's role in a specific organization.
     *
     * @return OrganizationRole|null
     */
    protected function getOrganizationRole(User $user, Organization $organization): ?OrganizationRole
    {
        $membership = $organization->users()
            ->where('user_id', $user->id)
            ->first();

        return $membership?->pivot->role;
    }
}
