<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Allow admin users (is_admin flag) or users with appropriate roles
        if ($user->is_admin) {
            return true;
        }

        try {
            return $user->hasRole('superadmin') || $user->hasRole('admin');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        // Admin users can view any user
        if ($user->is_admin) {
            return true;
        }

        try {
            // Superadmin can view any user
            if ($user->hasRole('superadmin') || $user->hasRole('admin')) {
                return true;
            }

            // Org-admin can view users in their organizations
            if ($user->hasRole('org-admin')) {
                return $this->shareOrganization($user, $model);
            }
        } catch (\Exception $e) {
            // Roles not available
        }

        // Users can view their own profile
        return $user->id === $model->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Admin users can create users
        if ($user->is_admin) {
            return true;
        }

        try {
            return $user->hasRole('superadmin') || $user->hasRole('admin');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        // Admin users can update any user
        if ($user->is_admin) {
            return true;
        }

        try {
            // Superadmin can update any user
            if ($user->hasRole('superadmin') || $user->hasRole('admin')) {
                return true;
            }

            // Org-admin can update users in their organizations (except sensitive fields)
            if ($user->hasRole('org-admin')) {
                return $this->shareOrganization($user, $model);
            }
        } catch (\Exception $e) {
            // Roles not available
        }

        // Users can update their own profile
        return $user->id === $model->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        // Can't delete yourself
        if ($user->id === $model->id) {
            return false;
        }

        // Admin users can delete other users
        if ($user->is_admin) {
            return true;
        }

        try {
            return $user->hasRole('superadmin') || $user->hasRole('admin');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        if ($user->is_admin) {
            return true;
        }

        try {
            return $user->hasRole('superadmin') || $user->hasRole('admin');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        // Can't force delete yourself
        if ($user->id === $model->id) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        try {
            return $user->hasRole('superadmin') || $user->hasRole('admin');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Determine whether the user can export user data.
     */
    public function export(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        try {
            return $user->hasRole('superadmin') || $user->hasRole('admin') || $user->hasRole('org-admin');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Determine whether the user can perform bulk actions.
     */
    public function bulkUpdate(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        try {
            return $user->hasRole('superadmin') || $user->hasRole('admin');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if two users share at least one organization
     */
    private function shareOrganization(User $user, User $model): bool
    {
        return $user->organizations()->whereIn('organizations.id',
            $model->organizations()->pluck('organizations.id')
        )->exists();
    }
}
