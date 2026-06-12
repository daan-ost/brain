<?php

namespace App\Services;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Service for handling automatic organization enrollment based on verified email domains
 *
 * This service handles the business logic for auto-enrolling users into organizations
 * when their email domain matches a validated organization domain.
 *
 * Used by:
 * - EmailConfirmationController: To immediately enroll and show results to user
 * - AutoEnrollUserInOrganization listener: For other enrollment scenarios
 */
class OrganizationAutoEnrollmentService
{
    /**
     * Public domain blacklist
     * These domains should never trigger auto-enrollment
     */
    private const PUBLIC_DOMAINS = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk',
        'hotmail.com', 'hotmail.co.uk', 'outlook.com', 'outlook.co.uk',
        'live.com', 'live.co.uk', 'msn.com', 'icloud.com',
        'me.com', 'mac.com', 'protonmail.com', 'protonmail.ch',
        'aol.com', 'mail.com', 'zoho.com', 'yandex.com', 'yandex.ru',
        'gmx.com', 'gmx.de', 'mail.ru',
    ];

    /**
     * Enroll user in organizations based on their verified email domain
     *
     * @param  User  $user  The user to enroll
     * @return Collection Collection of Organization models the user was enrolled in
     */
    public function enrollUser(User $user): Collection
    {
        $enrolledOrganizations = collect();

        // Extract email domain (e.g., "user@interus.nl" -> "interus.nl")
        $emailDomain = $this->extractDomain($user->email);

        if (! $emailDomain) {
            Log::warning('AutoEnrollService: Could not extract domain from email', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return $enrolledOrganizations;
        }

        // Skip public domains (security)
        if ($this->isPublicDomain($emailDomain)) {
            Log::info('AutoEnrollService: Skipped public domain', [
                'user_id' => $user->id,
                'email' => $user->email,
                'domain' => $emailDomain,
            ]);

            return $enrolledOrganizations;
        }

        // Find matching organization domains
        $matchingDomains = OrganizationDomain::where('domain', $emailDomain)
            ->where('validated', true) // Only manually validated domains
            ->where('auto_enroll_with_verified_domain', true) // Must be enabled
            ->where('active', true) // Must be active
            ->with('organization') // Eager load organization
            ->get();

        if ($matchingDomains->isEmpty()) {
            Log::debug('AutoEnrollService: No matching domains found', [
                'user_id' => $user->id,
                'email' => $user->email,
                'domain' => $emailDomain,
            ]);

            return $enrolledOrganizations;
        }

        // Enroll user in ALL matching organizations
        foreach ($matchingDomains as $domain) {
            $organization = $domain->organization;

            // Skip if organization relation is null (orphaned domain)
            if (! $organization) {
                Log::warning('AutoEnrollService: Domain has no organization', [
                    'domain_id' => $domain->id,
                    'domain' => $domain->domain,
                ]);

                continue;
            }

            // Skip if user is already a member
            if ($organization->users()->where('user_id', $user->id)->exists()) {
                Log::info('AutoEnrollService: User already member', [
                    'user_id' => $user->id,
                    'organization_id' => $organization->id,
                    'domain' => $emailDomain,
                ]);

                continue;
            }

            // Add user to organization as member
            $organization->users()->attach($user->id, [
                'role' => OrganizationRole::Editor,
                'joined_at' => now(),
            ]);

            $enrolledOrganizations->push($organization);

            // Log analytics event
            AnalyticsService::log('user_auto_enrolled', [
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'domain' => $emailDomain,
                'email' => $user->email,
            ]);

            Log::info('AutoEnrollService: User enrolled', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'organization_id' => $organization->id,
                'organization_name' => $organization->name,
                'domain' => $emailDomain,
            ]);
        }

        if ($enrolledOrganizations->isNotEmpty()) {
            Log::info('AutoEnrollService: Enrollment complete', [
                'user_id' => $user->id,
                'email' => $user->email,
                'domain' => $emailDomain,
                'enrolled_count' => $enrolledOrganizations->count(),
                'organization_ids' => $enrolledOrganizations->pluck('id')->toArray(),
            ]);
        }

        return $enrolledOrganizations;
    }

    /**
     * Extract domain from email address
     *
     * @return string|null Domain (e.g., "interus.nl") or null if invalid
     */
    private function extractDomain(string $email): ?string
    {
        // Get everything after the @ symbol
        $parts = explode('@', $email);

        if (count($parts) !== 2) {
            return null;
        }

        $domain = strtolower(trim($parts[1]));

        // Basic validation: domain must have at least one dot
        if (! str_contains($domain, '.')) {
            return null;
        }

        return $domain;
    }

    /**
     * Check if domain is a public domain (blacklisted)
     *
     * @return bool True if domain is public/blacklisted
     */
    private function isPublicDomain(string $domain): bool
    {
        return in_array(strtolower($domain), self::PUBLIC_DOMAINS, true);
    }
}
