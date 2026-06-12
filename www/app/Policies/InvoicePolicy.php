<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class InvoicePolicy
{
    use HandlesAuthorization;

    /**
     * Determine if the user can view/download the invoice for this order
     */
    public function download(User $user, Order $order): bool
    {
        // User can download if they are the payer
        if ($order->payer_type === 'user' && $order->payer_id === $user->id) {
            return true;
        }

        // For organization orders, user must be an admin of that organization
        if ($order->payer_type === 'organization') {
            $organization = $order->organizationPayer;

            if ($organization) {
                // Check if user is admin of this organization
                $isAdmin = $organization->users()
                    ->where('users.id', $user->id)
                    ->wherePivot('role', OrganizationRole::Owner)
                    ->exists();

                return $isAdmin;
            }
        }

        return false;
    }

    /**
     * Determine if the user can view the invoices list
     */
    public function viewAny(User $user): bool
    {
        // All authenticated users can view their invoice list
        return true;
    }
}
