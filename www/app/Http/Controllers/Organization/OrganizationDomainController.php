<?php

namespace App\Http\Controllers\Organization;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrganizationDomainController extends Controller
{
    /**
     * Display organization email domains.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        // Get user's organizations
        $organizations = $user->organizations()->get();

        if ($organizations->isEmpty()) {
            return view('profile.organization-domains', [
                'user' => $user,
                'domains' => collect(),
                'organizations' => $organizations,
                'isAdmin' => false,
            ]);
        }

        // Check if user is admin in any organization
        $isAdmin = $organizations->contains(function ($org) {
            return $org->pivot->role === OrganizationRole::Owner;
        });

        if (! $isAdmin) {
            return redirect()->route('profile.organization')->with('error', 'You do not have permission to view organization domains.');
        }

        // Get the first organization's domains
        $currentOrganization = $organizations->first();
        $domains = $currentOrganization->domains()->orderBy('id', 'desc')->get();

        // Add user count for each domain (total members in organization)
        $totalUsers = $currentOrganization->users()->count();
        $domains->each(function ($domain) use ($totalUsers) {
            $domain->user_count = $totalUsers;
        });

        // Track page view
        AnalyticsService::log('organization_domains_view', [
            'organization_id' => $currentOrganization->id,
            'domain_count' => $domains->count(),
        ]);

        return view('profile.organization-domains', [
            'user' => $user,
            'domains' => $domains,
            'organizations' => $organizations,
            'currentOrganization' => $currentOrganization,
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * Store a new email domain for the organization.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Get user's organizations
        $organizations = $user->organizations()->get();

        if ($organizations->isEmpty()) {
            return redirect()->route('profile.organization')->with('error', 'You are not a member of any organization.');
        }

        // Check if user is admin in any organization
        $isAdmin = $organizations->contains(function ($org) {
            return $org->pivot->role === OrganizationRole::Owner;
        });

        if (! $isAdmin) {
            return redirect()->route('profile.organization')->with('error', 'You do not have permission to add domains.');
        }

        $currentOrganization = $organizations->first();

        // Clean domain BEFORE validation: remove http://, https://, www., and trailing slashes
        $cleanDomain = $request->domain;
        $cleanDomain = preg_replace('#^https?://#', '', $cleanDomain);
        $cleanDomain = preg_replace('#^www\.#', '', $cleanDomain);
        $cleanDomain = rtrim($cleanDomain, '/');

        // Temporarily set cleaned domain for validation
        $request->merge(['domain' => $cleanDomain]);

        $request->validate([
            'domain' => 'required|string|max:255|unique:organization_domains,domain|regex:/^[a-zA-Z0-9][a-zA-Z0-9-]{0,61}[a-zA-Z0-9]?(\.[a-zA-Z]{2,})+$/',
            'is_primary' => 'boolean',
            'auto_enroll_with_verified_domain' => 'boolean',
            'max_storage_days' => 'nullable|integer|min:1',
            'support_email' => 'nullable|email|max:255',
            'valid_until' => 'nullable|date|after:today',
        ], [
            'domain.regex' => 'Please enter a valid domain name (e.g., example.com, subdomain.example.com)',
        ]);

        // If this is set as primary, unset other primary domains
        if ($request->boolean('is_primary')) {
            $currentOrganization->domains()->update(['is_primary' => false]);
        }

        // Create the domain
        $domain = $currentOrganization->domains()->create([
            'domain' => $request->domain, // Already cleaned above
            'is_primary' => $request->boolean('is_primary'),
            'auto_enroll_with_verified_domain' => $request->boolean('auto_enroll_with_verified_domain'),
            'max_storage_days' => $request->max_storage_days,
            'support_email' => $request->support_email,
            'valid_until' => $request->valid_until,
            'active' => true,
        ]);

        // Track domain creation
        AnalyticsService::log('organization_domain_created', [
            'organization_id' => $currentOrganization->id,
            'domain' => $request->domain,
            'is_primary' => $request->boolean('is_primary'),
            'auto_enroll' => $request->boolean('auto_enroll_with_verified_domain'),
            'has_expiry' => $request->filled('valid_until'),
        ]);

        return redirect()->route('profile.organization.domains')->with('status', 'domain-added');
    }

    /**
     * Update an existing email domain for the organization.
     */
    public function update(Request $request, $domainId): RedirectResponse
    {
        $user = $request->user();

        // Get user's organizations
        $organizations = $user->organizations()->get();

        if ($organizations->isEmpty()) {
            return redirect()->route('profile.organization')->with('error', 'You are not a member of any organization.');
        }

        // Check if user is admin
        $isAdmin = $organizations->contains(function ($org) {
            return $org->pivot->role === OrganizationRole::Owner;
        });

        if (! $isAdmin) {
            return redirect()->route('profile.organization.domains')->with('error', 'You do not have permission to update domains.');
        }

        $currentOrganization = $organizations->first();

        // Find the domain that belongs to the current organization
        $domain = $currentOrganization->domains()->findOrFail($domainId);

        // Clean domain BEFORE validation: remove http://, https://, www., and trailing slashes
        $cleanDomain = $request->domain;
        $cleanDomain = preg_replace('#^https?://#', '', $cleanDomain);
        $cleanDomain = preg_replace('#^www\.#', '', $cleanDomain);
        $cleanDomain = rtrim($cleanDomain, '/');

        // Temporarily set cleaned domain for validation
        $request->merge(['domain' => $cleanDomain]);

        // Validate - domain cannot be changed if already validated
        if ($domain->validated && $cleanDomain !== $domain->domain) {
            return redirect()->route('profile.organization.domains')
                ->withErrors(['domain' => 'Cannot change a validated domain name.'])
                ->withInput();
        }

        $request->validate([
            'domain' => 'required|string|max:255|unique:organization_domains,domain,'.$domain->id.'|regex:/^[a-zA-Z0-9][a-zA-Z0-9-]{0,61}[a-zA-Z0-9]?(\.[a-zA-Z]{2,})+$/',
            'is_primary' => 'nullable|boolean',
            'auto_enroll_with_verified_domain' => 'nullable|boolean',
            'max_storage_days' => 'nullable|integer|min:1',
            'support_email' => 'nullable|email|max:255',
            'valid_until' => 'nullable|date',
        ], [
            'domain.regex' => 'Please enter a valid domain name (e.g., example.com, subdomain.example.com)',
        ]);

        // If this is set as primary, unset other primary domains
        if ($request->boolean('is_primary')) {
            $currentOrganization->domains()->where('id', '!=', $domain->id)->update(['is_primary' => false]);
        }

        // Update the domain (don't update domain field if validated)
        $updateData = [
            'is_primary' => $request->boolean('is_primary'),
            'auto_enroll_with_verified_domain' => $request->boolean('auto_enroll_with_verified_domain'),
            'max_storage_days' => $request->max_storage_days,
            'support_email' => $request->support_email,
            'valid_until' => $request->valid_until,
        ];

        // Only update domain name if not validated
        if (! $domain->validated) {
            $updateData['domain'] = $request->domain; // Already cleaned above
        }

        $domain->update($updateData);

        // Track domain update
        AnalyticsService::log('organization_domain_updated', [
            'organization_id' => $currentOrganization->id,
            'domain_id' => $domain->id,
            'domain' => $request->domain,
        ]);

        return redirect()->route('profile.organization.domains')->with('status', 'domain-updated');
    }

    /**
     * Delete an email domain from the organization.
     */
    public function destroy(Request $request, $domainId): RedirectResponse
    {
        $user = $request->user();

        // Get user's organizations
        $organizations = $user->organizations()->get();

        if ($organizations->isEmpty()) {
            return redirect()->route('profile.organization')->with('error', 'You are not a member of any organization.');
        }

        // Check if user is admin
        $isAdmin = $organizations->contains(function ($org) {
            return $org->pivot->role === OrganizationRole::Owner;
        });

        if (! $isAdmin) {
            return redirect()->route('profile.organization.domains')->with('error', 'You do not have permission to delete domains.');
        }

        $currentOrganization = $organizations->first();

        // Find the domain that belongs to the current organization
        $domain = $currentOrganization->domains()->findOrFail($domainId);

        // Track domain deletion
        AnalyticsService::log('organization_domain_deleted', [
            'organization_id' => $currentOrganization->id,
            'domain_id' => $domain->id,
            'domain' => $domain->domain,
        ]);

        // Delete the domain
        $domain->delete();

        return redirect()->route('profile.organization.domains')->with('status', 'domain-deleted');
    }
}
