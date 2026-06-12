<?php

namespace App\Http\Controllers\Organization;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    /**
     * Display the organization overview (show current organization).
     */
    public function show(Request $request): View
    {
        $user = $request->user();

        // Get user's organizations with their role and credit pool
        $organizations = $user->organizations()->with(['creditPool', 'currentLicenses'])->get();

        // Check if user is admin in any organization
        $isAdmin = $organizations->contains(function ($org) {
            return $org->pivot->role === OrganizationRole::Owner;
        });

        // Get the first organization
        $organization = $organizations->first();

        // Track organization view
        AnalyticsService::log('organization_view', [
            'organization_count' => $organizations->count(),
            'is_admin' => $isAdmin,
            'has_organization' => $organization !== null,
            'organization_id' => $organization?->id,
        ]);

        // Return different views based on role
        if ($isAdmin) {
            // Admin view - shows full management capabilities
            return view('profile.organization-admin', [
                'user' => $user,
                'organizations' => $organizations,
                'isAdmin' => $isAdmin,
                'currentOrganization' => $organization,
            ]);
        }

        // Member view - shows simplified organization info
        $admins = $organization ? $organization->admins()->get() : collect();
        $memberCount = $organization ? $organization->users()->count() : 0;
        $usesOrganizationCredits = $organization && $organization->creditPool && $organization->creditPool->balance_credits > 0;

        return view('profile.organization-member', [
            'user' => $user,
            'organization' => $organization,
            'admins' => $admins,
            'memberCount' => $memberCount,
            'usesOrganizationCredits' => $usesOrganizationCredits,
        ]);
    }

    /**
     * Update organization information (admin only).
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Get user's organizations
        $organizations = $user->organizations()->get();

        // Check if user has an organization
        if ($organizations->isEmpty()) {
            return redirect()->route('profile.organization')->with('error', 'You are not a member of any organization.');
        }

        $currentOrganization = $organizations->first();

        // Check if user is admin of the organization
        $isAdmin = $currentOrganization->pivot->role === OrganizationRole::Owner;
        if (! $isAdmin) {
            return redirect()->route('profile.organization')->with('error', 'You do not have permission to update organization details.');
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'billing_country_code' => 'required|string|size:2',
            'currency_preference' => 'required|in:EUR,USD',
            'vat_number' => 'nullable|string|max:50',
        ]);

        // If VAT number changed, validate it
        if ($request->vat_number !== $currentOrganization->vat_number) {
            if (! empty($request->vat_number)) {
                $viesService = app(\App\Services\VIESValidationService::class);
                $validation = $viesService->validateVatId($request->vat_number);

                if ($validation['valid']) {
                    $currentOrganization->update([
                        'name' => $request->name,
                        'billing_country_code' => $request->billing_country_code,
                        'currency_preference' => $request->currency_preference,
                        'vat_number' => $request->vat_number,
                        'vat_validated_at' => now(),
                    ]);

                    // Track organization update
                    AnalyticsService::log('organization_update', [
                        'organization_id' => $currentOrganization->id,
                        'fields_changed' => ['name', 'billing_country_code', 'currency_preference', 'vat_number'],
                        'vat_validated' => true,
                    ]);

                    return redirect()->route('profile.organization')->with('status', 'organization-updated');
                } else {
                    return redirect()->route('profile.organization')->with('error', 'Invalid VAT number. Please check and try again.');
                }
            } else {
                // VAT number removed
                $currentOrganization->update([
                    'name' => $request->name,
                    'billing_country_code' => $request->billing_country_code,
                    'currency_preference' => $request->currency_preference,
                    'vat_number' => null,
                    'vat_validated_at' => null,
                ]);

                // Track organization update
                AnalyticsService::log('organization_update', [
                    'organization_id' => $currentOrganization->id,
                    'fields_changed' => ['name', 'billing_country_code', 'currency_preference'],
                    'vat_number_removed' => true,
                ]);

                return redirect()->route('profile.organization')->with('status', 'organization-updated');
            }
        } else {
            // No VAT number change
            $currentOrganization->update([
                'name' => $request->name,
                'billing_country_code' => $request->billing_country_code,
                'currency_preference' => $request->currency_preference,
            ]);

            // Track organization update
            AnalyticsService::log('organization_update', [
                'organization_id' => $currentOrganization->id,
                'fields_changed' => ['name', 'billing_country_code', 'currency_preference'],
                'vat_number_changed' => false,
            ]);

            return redirect()->route('profile.organization')->with('status', 'organization-updated');
        }
    }

    /**
     * Create a new organization (user becomes admin).
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Check if user has verified their email
        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('profile.organization')->with('error', 'You must verify your email address before creating an organization.');
        }

        // Check if user already has an organization
        if ($user->organizations()->count() > 0) {
            return redirect()->route('profile.organization')->with('error', 'You are already a member of an organization.');
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'billing_country_code' => 'required|string|size:2',
            'currency_preference' => 'required|in:EUR,USD',
            'vat_number' => 'nullable|string|max:50',
        ]);

        // Create the organization
        $organization = \App\Models\Organization::create([
            'name' => $request->name,
            'billing_country_code' => $request->billing_country_code,
            'currency_preference' => $request->currency_preference,
            'vat_number' => $request->vat_number,
        ]);

        // If VAT number provided, validate it
        if (! empty($request->vat_number)) {
            $viesService = app(\App\Services\VIESValidationService::class);
            $validation = $viesService->validateVatId($request->vat_number);

            if ($validation['valid']) {
                $organization->update(['vat_validated_at' => now()]);
            }
        }

        // Add user as admin to the organization
        $organization->users()->attach($user->id, [
            'role' => OrganizationRole::Owner,
            'joined_at' => now(),
        ]);

        // Create organization credit pool with initial balance of 0
        \App\Models\OrganizationCreditPool::create([
            'organization_id' => $organization->id,
            'balance_credits' => 0,
        ]);

        // Track organization creation
        AnalyticsService::log('organization_create', [
            'organization_name' => $request->name,
            'billing_country' => $request->billing_country_code,
            'currency' => $request->currency_preference,
            'has_vat_number' => ! empty($request->vat_number),
            'vat_validated' => ! empty($request->vat_number) && isset($validation) && $validation['valid'],
        ]);

        return redirect()->route('profile.organization')->with('status', 'organization-created');
    }
}
