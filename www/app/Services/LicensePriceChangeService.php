<?php

namespace App\Services;

use App\Jobs\SendPostmarkTemplateEmail;
use App\Models\License;
use App\Models\OrganizationLicense;
use App\Models\UserLicense;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class LicensePriceChangeService
{
    private MollieSubscriptionService $subscriptionService;

    private \App\Services\Payments\StripeSubscriptionService $stripeSubscriptionService;

    public function __construct(
        MollieSubscriptionService $subscriptionService,
        \App\Services\Payments\StripeSubscriptionService $stripeSubscriptionService
    ) {
        $this->subscriptionService = $subscriptionService;
        $this->stripeSubscriptionService = $stripeSubscriptionService;
    }

    /**
     * Schedule a price change for a license
     */
    public function schedulePriceChange(
        License $license,
        float $newAmount,
        ?int $newCredits,
        Carbon $effectiveFrom
    ): bool {
        $license->update([
            'upcoming_amount' => $newAmount,
            'upcoming_credits' => $newCredits,
            'price_effective_from' => $effectiveFrom,
        ]);

        Log::info('Price change scheduled', [
            'license_id' => $license->id,
            'license_name' => $license->name,
            'current_amount' => $license->amount,
            'upcoming_amount' => $newAmount,
            'current_credits' => $license->credits,
            'upcoming_credits' => $newCredits,
            'effective_from' => $effectiveFrom->toDateString(),
        ]);

        return true;
    }

    /**
     * Apply scheduled price change (when effective date is reached)
     */
    public function applyScheduledPriceChange(License $license): bool
    {
        if (! $license->price_effective_from || $license->price_effective_from->isFuture()) {
            return false;
        }

        if (! $license->upcoming_amount) {
            return false;
        }

        $oldAmount = $license->amount;
        $oldCredits = $license->credits;

        $license->update([
            'amount' => $license->upcoming_amount,
            'credits' => $license->upcoming_credits ?? $license->credits,
            'upcoming_amount' => null,
            'upcoming_credits' => null,
            'price_effective_from' => null,
        ]);

        Log::info('Price change applied', [
            'license_id' => $license->id,
            'old_amount' => $oldAmount,
            'new_amount' => $license->amount,
            'old_credits' => $oldCredits,
            'new_credits' => $license->credits,
        ]);

        return true;
    }

    /**
     * Get impact analysis for a price change
     */
    public function getPriceChangeImpact(License $license): array
    {
        $userLicenses = UserLicense::where('license_id', $license->id)
            ->where('status', 'active')
            ->hasProviderSubscription()
            ->with('user')
            ->get();

        $orgLicenses = OrganizationLicense::where('license_id', $license->id)
            ->where('status', 'active')
            ->hasProviderSubscription()
            ->with('organization')
            ->get();

        $now = now();
        $thirtyDaysFromNow = $now->copy()->addDays(30);
        $sevenDaysFromNow = $now->copy()->addDays(7);

        // Calculate renewal dates based on billing cycle
        $billingCycle = $license->billing_cycle ?? 'yearly';

        $userRenewals = $this->categorizeByRenewalDate($userLicenses, $billingCycle, $sevenDaysFromNow, $thirtyDaysFromNow);
        $orgRenewals = $this->categorizeByRenewalDate($orgLicenses, $billingCycle, $sevenDaysFromNow, $thirtyDaysFromNow);

        return [
            'total_user_licenses' => $userLicenses->count(),
            'total_org_licenses' => $orgLicenses->count(),
            'user_renewals_within_7_days' => $userRenewals['within_7_days'],
            'user_renewals_within_30_days' => $userRenewals['within_30_days'],
            'user_renewals_after_30_days' => $userRenewals['after_30_days'],
            'org_renewals_within_7_days' => $orgRenewals['within_7_days'],
            'org_renewals_within_30_days' => $orgRenewals['within_30_days'],
            'org_renewals_after_30_days' => $orgRenewals['after_30_days'],
        ];
    }

    /**
     * Categorize licenses by their renewal date
     */
    private function categorizeByRenewalDate(
        Collection $licenses,
        string $billingCycle,
        Carbon $sevenDays,
        Carbon $thirtyDays
    ): array {
        $result = [
            'within_7_days' => 0,
            'within_30_days' => 0,
            'after_30_days' => 0,
        ];

        foreach ($licenses as $license) {
            $renewalDate = $this->calculateNextRenewalDate($license, $billingCycle);

            if (! $renewalDate) {
                continue;
            }

            if ($renewalDate->lte($sevenDays)) {
                $result['within_7_days']++;
            } elseif ($renewalDate->lte($thirtyDays)) {
                $result['within_30_days']++;
            } else {
                $result['after_30_days']++;
            }
        }

        return $result;
    }

    /**
     * Calculate next renewal date for a license
     */
    public function calculateNextRenewalDate(UserLicense|OrganizationLicense $license, ?string $billingCycle = null): ?Carbon
    {
        $billingCycle = $billingCycle ?? $license->license?->billing_cycle ?? 'yearly';
        $lastReset = $license->last_credit_reset_at ?? $license->starts_at ?? $license->created_at;

        if (! $lastReset) {
            return null;
        }

        return match ($billingCycle) {
            'monthly' => $lastReset->copy()->addMonth(),
            'yearly' => $lastReset->copy()->addYear(),
            '6month' => $lastReset->copy()->addMonths(6),
            default => $lastReset->copy()->addYear(),
        };
    }

    /**
     * Get all licenses that need price change notification
     * (renewal within 30 days and not yet notified)
     */
    public function getLicensesNeedingNotification(): array
    {
        $licensesWithUpcomingChange = License::whereNotNull('upcoming_amount')
            ->whereNotNull('price_effective_from')
            ->where('tier', 'premium')
            ->get();

        $result = [
            'user_licenses' => collect(),
            'org_licenses' => collect(),
        ];

        foreach ($licensesWithUpcomingChange as $license) {
            $thirtyDaysFromNow = now()->addDays(30);
            $sevenDaysFromNow = now()->addDays(7);

            // Get user licenses needing notification (Mollie + Stripe)
            $userLicenses = UserLicense::where('license_id', $license->id)
                ->where('status', 'active')
                ->hasProviderSubscription()
                ->whereNull('price_change_notified_at')
                ->with(['user', 'license'])
                ->get()
                ->filter(function ($userLicense) use ($license, $thirtyDaysFromNow, $sevenDaysFromNow) {
                    $renewalDate = $this->calculateNextRenewalDate($userLicense);

                    // Skip if renewal is within 7 days (they keep old price)
                    if ($renewalDate && $renewalDate->lte($sevenDaysFromNow)) {
                        return false;
                    }

                    // Only notify if renewal is within 30 days OR effective date is within 30 days
                    return ($renewalDate && $renewalDate->lte($thirtyDaysFromNow))
                        || $license->price_effective_from->lte($thirtyDaysFromNow);
                });

            $result['user_licenses'] = $result['user_licenses']->merge($userLicenses);

            // Get org licenses needing notification (Mollie + Stripe)
            $orgLicenses = OrganizationLicense::where('license_id', $license->id)
                ->where('status', 'active')
                ->hasProviderSubscription()
                ->whereNull('price_change_notified_at')
                ->with(['organization', 'license'])
                ->get()
                ->filter(function ($orgLicense) use ($license, $thirtyDaysFromNow, $sevenDaysFromNow) {
                    $renewalDate = $this->calculateNextRenewalDate($orgLicense);

                    // Skip if renewal is within 7 days (they keep old price)
                    if ($renewalDate && $renewalDate->lte($sevenDaysFromNow)) {
                        return false;
                    }

                    return ($renewalDate && $renewalDate->lte($thirtyDaysFromNow))
                        || $license->price_effective_from->lte($thirtyDaysFromNow);
                });

            $result['org_licenses'] = $result['org_licenses']->merge($orgLicenses);
        }

        return $result;
    }

    /**
     * Send price change notification to a user
     */
    public function sendUserNotification(UserLicense $userLicense): bool
    {
        $user = $userLicense->user;
        $license = $userLicense->license;

        if (! $user || ! $license) {
            return false;
        }

        $renewalDate = $this->calculateNextRenewalDate($userLicense);
        $locale = $user->locale ?? app()->getLocale();

        $templateModel = $this->buildTemplateModel(
            license: $license,
            currentPrice: $userLicense->price_at_purchase ?? $license->amount,
            renewalDate: $renewalDate,
            recipientName: $user->name,
            locale: $locale,
            user: $user
        );

        SendPostmarkTemplateEmail::dispatch(
            templateAlias: 'price-change-notification__'.$locale,
            templateModel: $templateModel,
            to: $user->email,
            toName: $user->name,
            tag: 'price-change-notification',
            messageStream: 'outbound'
        );

        $userLicense->update(['price_change_notified_at' => now()]);

        Log::info('Price change notification sent to user', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'license_id' => $license->id,
            'current_price' => $templateModel['current_price'],
            'new_price' => $templateModel['new_price'],
        ]);

        return true;
    }

    /**
     * Send price change notification to an organization
     */
    public function sendOrganizationNotification(OrganizationLicense $orgLicense): bool
    {
        $organization = $orgLicense->organization;
        $license = $orgLicense->license;

        if (! $organization || ! $license) {
            return false;
        }

        // Get organization owner/admin email
        $owner = $organization->owner ?? $organization->users()->first();
        if (! $owner) {
            Log::warning('No owner found for organization', ['organization_id' => $organization->id]);

            return false;
        }

        $renewalDate = $this->calculateNextRenewalDate($orgLicense);
        $locale = $owner->locale ?? app()->getLocale();

        $templateModel = $this->buildTemplateModel(
            license: $license,
            currentPrice: $orgLicense->price_at_purchase ?? $license->amount,
            renewalDate: $renewalDate,
            recipientName: $organization->name,
            locale: $locale,
            user: $owner
        );

        SendPostmarkTemplateEmail::dispatch(
            templateAlias: 'price-change-notification__'.$locale,
            templateModel: $templateModel,
            to: $owner->email,
            toName: $owner->name,
            tag: 'price-change-notification',
            messageStream: 'outbound'
        );

        $orgLicense->update(['price_change_notified_at' => now()]);

        Log::info('Price change notification sent to organization', [
            'organization_id' => $organization->id,
            'owner_email' => $owner->email,
            'license_id' => $license->id,
        ]);

        return true;
    }

    /**
     * Build template model for price change email.
     *
     * Uses LocaleService for locale-aware formatting of prices and dates.
     * The $user parameter is passed explicitly because this runs in a
     * console command context (no auth user available).
     *
     * Currency symbol is provided separately via $license->currency so
     * the email template can position it appropriately.
     */
    private function buildTemplateModel(
        License $license,
        float $currentPrice,
        ?Carbon $renewalDate,
        string $recipientName,
        string $locale,
        ?\App\Models\User $user = null
    ): array {
        $newPrice = $license->upcoming_amount;
        $newCredits = $license->upcoming_credits ?? $license->credits;
        $effectiveFrom = $license->price_effective_from;

        $priceIncreased = $newPrice > $currentPrice;
        $priceDecreased = $newPrice < $currentPrice;
        $creditsChanged = $newCredits != $license->credits;

        $localeService = app(LocaleService::class);

        return [
            'recipient_name' => $recipientName,
            'license_name' => $license->name,
            'current_price' => $localeService->formatNumber($currentPrice, $user),
            'new_price' => $localeService->formatNumber($newPrice ?? $currentPrice, $user),
            'currency' => $license->currency,
            'current_credits' => $license->credits,
            'new_credits' => $newCredits,
            'has_credits_change' => $creditsChanged,
            'price_increased' => $priceIncreased,
            'price_decreased' => $priceDecreased,
            'effective_date' => $effectiveFrom ? $localeService->formatDate($effectiveFrom, $user) : '',
            'renewal_date' => $renewalDate ? $localeService->formatDate($renewalDate, $user) : '',
            'has_renewal_date' => $renewalDate !== null,
            'billing_cycle' => $this->getBillingCycleLabel($license->billing_cycle, $locale),
            'account_url' => config('app.url').'/profile/account',
        ];
    }

    /**
     * Get localized billing cycle label
     */
    private function getBillingCycleLabel(?string $billingCycle, string $locale): string
    {
        $labels = [
            'en' => [
                'monthly' => 'monthly',
                'yearly' => 'yearly',
                '6month' => 'every 6 months',
            ],
            'nl' => [
                'monthly' => 'maandelijks',
                'yearly' => 'jaarlijks',
                '6month' => 'elke 6 maanden',
            ],
        ];

        return $labels[$locale][$billingCycle] ?? $labels['en'][$billingCycle] ?? 'yearly';
    }

    /**
     * Update subscription amount/price for all active subscriptions across providers.
     * Mollie → updateSubscriptionAmount (cancel + recreate met nieuwe amount).
     * Stripe → updatePrice (switch subscription item naar nieuwe stripe_price_id).
     * Run `php artisan stripe:sync-prices` vóór deze method als upcoming_amount
     * is gewijzigd, anders blijven Stripe subs op de oude price.
     */
    public function updateSubscriptions(License $license): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $newAmount = $license->upcoming_amount ?? $license->amount;

        // Update user subscriptions (beide providers)
        $userLicenses = UserLicense::where('license_id', $license->id)
            ->where('status', 'active')
            ->hasProviderSubscription()
            ->get();

        foreach ($userLicenses as $userLicense) {
            $result = $this->updateSingleSubscription($userLicense, $newAmount, $license);
            $this->updateResultCounts($results, $result, $userLicense);
        }

        // Update organization subscriptions (beide providers)
        $orgLicenses = OrganizationLicense::where('license_id', $license->id)
            ->where('status', 'active')
            ->hasProviderSubscription()
            ->get();

        foreach ($orgLicenses as $orgLicense) {
            $result = $this->updateSingleSubscription($orgLicense, $newAmount, $license);
            $this->updateResultCounts($results, $result, $orgLicense);
        }

        Log::info('Subscriptions updated for license', [
            'license_id' => $license->id,
            'new_amount' => $newAmount,
            'results' => $results,
        ]);

        return $results;
    }

    /**
     * @deprecated 2026-05-21 — gebruik updateSubscriptions(). Behouden voor
     * backward-compat met externe callers.
     */
    public function updateMollieSubscriptions(License $license): array
    {
        return $this->updateSubscriptions($license);
    }

    /**
     * Update een enkele subscription via de juiste provider.
     */
    private function updateSingleSubscription(
        UserLicense|OrganizationLicense $userOrOrgLicense,
        float $newAmount,
        License $license
    ): array {
        // Provider-aware accessors (provider_*_id ?? mollie_*_id)
        $provider = $userOrOrgLicense->payment_provider;
        $customerId = $userOrOrgLicense->provider_customer_id;
        $subscriptionId = $userOrOrgLicense->provider_subscription_id;

        if (! $customerId || ! $subscriptionId) {
            return ['status' => 'skipped', 'reason' => 'Missing customer or subscription ID'];
        }

        if ($provider === 'stripe') {
            return $this->updateStripeSubscription($userOrOrgLicense, $license, $subscriptionId, $newAmount);
        }

        // Mollie (default + legacy)
        $result = $this->subscriptionService->updateSubscriptionAmount(
            $customerId,
            $subscriptionId,
            $newAmount,
            $license->currency
        );

        if ($result['success']) {
            $userOrOrgLicense->update(['price_at_purchase' => $newAmount]);

            return ['status' => 'success'];
        }

        return [
            'status' => 'failed',
            'error' => $result['error'] ?? 'Unknown error',
        ];
    }

    /**
     * Update a single Stripe subscription naar de nieuwe stripe_price_id.
     * Vereist dat License al een nieuwe stripe_price_id heeft (via
     * `php artisan stripe:sync-prices` na upcoming_amount wijziging).
     */
    private function updateStripeSubscription(
        UserLicense|OrganizationLicense $userOrOrgLicense,
        License $license,
        string $subscriptionId,
        float $newAmount
    ): array {
        if (empty($license->stripe_price_id)) {
            Log::warning('Stripe subscription price-change skipped: stripe_price_id ontbreekt op License', [
                'license_id' => $license->id,
                'subscription_id' => $subscriptionId,
                'hint' => 'Run php artisan stripe:sync-prices na upcoming_amount wijziging',
            ]);

            return ['status' => 'skipped', 'reason' => 'License has no stripe_price_id — run stripe:sync-prices first'];
        }

        $result = $this->stripeSubscriptionService->updatePrice($subscriptionId, $license->stripe_price_id);

        if ($result['success']) {
            $userOrOrgLicense->update(['price_at_purchase' => $newAmount]);

            return ['status' => 'success'];
        }

        return [
            'status' => 'failed',
            'error' => $result['error'] ?? 'Unknown error',
        ];
    }

    /**
     * Update result counts
     */
    private function updateResultCounts(array &$results, array $result, $license): void
    {
        switch ($result['status']) {
            case 'success':
                $results['success']++;
                break;
            case 'skipped':
                $results['skipped']++;
                break;
            case 'failed':
                $results['failed']++;
                $results['errors'][] = [
                    'license_type' => $license instanceof UserLicense ? 'user' : 'organization',
                    'license_id' => $license->id,
                    'error' => $result['error'],
                ];
                break;
        }
    }

    /**
     * Cancel a scheduled price change
     */
    public function cancelScheduledPriceChange(License $license): bool
    {
        $license->update([
            'upcoming_amount' => null,
            'upcoming_credits' => null,
            'price_effective_from' => null,
        ]);

        // Reset notification flags so users can be notified again if new change is scheduled
        UserLicense::where('license_id', $license->id)
            ->whereNotNull('price_change_notified_at')
            ->update(['price_change_notified_at' => null]);

        OrganizationLicense::where('license_id', $license->id)
            ->whereNotNull('price_change_notified_at')
            ->update(['price_change_notified_at' => null]);

        Log::info('Scheduled price change canceled', ['license_id' => $license->id]);

        return true;
    }
}
