<?php

namespace App\Http\Controllers\Traits;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

trait AuthorizesOrganizationPayments
{
    /**
     * Authorize that the current user can make payments for an organization.
     * Returns null if authorized, or an error response if not.
     *
     * @param  string  $responseType  'json' or 'redirect'
     */
    protected function authorizeOrganizationPayment(
        string $payerType,
        ?int $payerId,
        string $responseType = 'json'
    ): JsonResponse|RedirectResponse|null {
        if ($payerType !== 'organization') {
            return null; // Not an organization payment, no authorization needed
        }

        // Validate organization ID is provided
        if (! $payerId) {
            return $this->authorizationError(
                'Organization ID required for organization payments.',
                400,
                $responseType
            );
        }

        // Find the organization
        $organization = Organization::find($payerId);
        if (! $organization) {
            return $this->authorizationError(
                'Organization not found.',
                404,
                $responseType
            );
        }

        // Check user is authenticated
        $user = auth()->user();
        if (! $user) {
            return $this->authorizationError(
                'Authentication required for organization payments.',
                401,
                $responseType,
                'login'
            );
        }

        // Check user is a member of the organization
        $membership = $organization->users()->where('users.id', $user->id)->first();
        if (! $membership) {
            return $this->authorizationError(
                'You are not a member of this organization.',
                403,
                $responseType
            );
        }

        // Check user has admin role
        if ($membership->pivot->role !== OrganizationRole::Owner) {
            return $this->authorizationError(
                'Only organization admins can make payments for the organization.',
                403,
                $responseType
            );
        }

        // Log successful authorization
        Log::info('Organization payment authorized', [
            'organization_id' => $organization->id,
            'organization_name' => $organization->name,
            'user_id' => $user->id,
            'user_role' => $membership->pivot->role,
        ]);

        return null; // Authorized
    }

    /**
     * Create an authorization error response.
     */
    private function authorizationError(
        string $message,
        int $statusCode,
        string $responseType,
        ?string $redirectRoute = null
    ): JsonResponse|RedirectResponse {
        if ($responseType === 'json') {
            return response()->json([
                'success' => false,
                'error' => $message,
            ], $statusCode);
        }

        $route = $redirectRoute ?? 'checkout';

        return redirect()->route($route)->with('error', $message);
    }
}
