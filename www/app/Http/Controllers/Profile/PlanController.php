<?php

namespace App\Http\Controllers\Profile;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Models\OrganizationLicense;
use App\Models\UserLicense;
use App\Services\AnalyticsService;
use App\Services\CreditsService;
use App\Services\LicenseRenewalService;
use App\Services\LocaleService;
use App\Services\Payments\StripeSubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function __construct(
        private LicenseRenewalService $renewalService,
        private CreditsService $creditsService
    ) {}

    /**
     * Display the user's plans and licenses.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        // Load user with organizations and their current licenses
        $user->load(['organizations.currentLicenses.license']);

        // Get all active licenses in priority order
        $activeLicenses = $user->getAllActiveLicenses();

        // Get credit summary
        $creditSummary = $user->getCreditSummary();

        // Get previous individual subscriptions (ended licenses)
        $previousSubscriptions = $user->userLicenses()
            ->where('is_current', false)
            ->with('license')
            ->orderBy('ends_at', 'desc')
            ->get();

        // Get pending organizational licenses (awaiting payment)
        $pendingLicenses = collect();
        foreach ($user->organizations as $organization) {
            $pendingOrgLicenses = $organization->organizationLicenses()
                ->pending()
                ->with(['license', 'organization'])
                ->get();
            $pendingLicenses = $pendingLicenses->merge($pendingOrgLicenses);
        }

        // Get non-current organizational licenses (only for admins)
        $previousOrganizationalLicenses = collect();
        foreach ($user->organizations as $organization) {
            $isAdmin = $organization->pivot->role === OrganizationRole::Owner;
            if ($isAdmin) {
                $orgLicenses = $organization->organizationLicenses()
                    ->where('is_current', false)
                    ->with(['license', 'organization'])
                    ->orderBy('ends_at', 'desc')
                    ->get();
                $previousOrganizationalLicenses = $previousOrganizationalLicenses->merge($orgLicenses);
            }
        }

        // Sort all previous licenses by end date
        $previousOrganizationalLicenses = $previousOrganizationalLicenses->sortByDesc('ends_at');

        // Get payment source (for credit display)
        $paymentSource = $this->creditsService->getPaymentSource($user);

        // Determine primary license and its details
        $primaryLicense = $activeLicenses->first();
        $primaryLicenseData = $this->getPrimaryLicenseData($primaryLicense);

        // Determine if user can upgrade (individual users or org admins)
        $canUpgrade = $this->userCanUpgrade($user);

        // Track page view
        AnalyticsService::log('plans_view', [
            'active_license_count' => $activeLicenses->count(),
            'has_organization_license' => $activeLicenses->contains(fn ($l) => isset($l->organization_id)),
            'current_credit_balance' => $creditSummary['total_available'] ?? 0,
        ]);

        return view('profile.plans', [
            'user' => $user,
            'activeLicenses' => $activeLicenses,
            'creditSummary' => $creditSummary,
            'pendingLicenses' => $pendingLicenses,
            'previousSubscriptions' => $previousSubscriptions,
            'previousOrganizationalLicenses' => $previousOrganizationalLicenses,
            'paymentSource' => $paymentSource,
            'primaryLicenseData' => $primaryLicenseData,
            'canUpgrade' => $canUpgrade,
        ]);
    }

    /**
     * Cancel a recurring license renewal
     */
    public function cancelRenewal(Request $request, int $licenseId): RedirectResponse
    {
        $user = $request->user();

        // Find the license
        $result = $this->renewalService->findLicenseForUser($user, $licenseId);

        if (! $result['license']) {
            $error = match ($result['error'] ?? null) {
                'not_admin' => __('profile.not_admin_error'),
                default => __('profile.license_not_found'),
            };

            return back()->with('error', $error);
        }

        $license = $result['license'];
        $licenseType = $result['type'];

        // Validate cancellation is allowed
        $validation = $this->renewalService->validateCancellation($license);
        if (! $validation['valid']) {
            return back()->with('error', $validation['error']);
        }

        // Perform cancellation
        $cancellation = $this->renewalService->cancelRenewal($license, $licenseType);
        if (! $cancellation['success']) {
            return back()->with('error', $cancellation['error']);
        }

        // Track the cancellation
        AnalyticsService::log('license_renewal_canceled', [
            'license_id' => $license->id,
            'license_type' => $licenseType,
            'billing_cycle' => $license->license->billing_cycle,
            'renewal_date' => $cancellation['renewal_date']->toDateString(),
        ]);

        return back()->with('success', __('profile.renewal_canceled_success', [
            'date' => $cancellation['renewal_date']->format('M j, Y'),
        ]));
    }

    /**
     * Redirect to the Stripe Billing Portal for self-service subscription management.
     */
    public function billingPortal(Request $request): RedirectResponse
    {
        $user = $request->user();

        $license = UserLicense::where('user_id', $user->id)
            ->where('payment_provider', 'stripe')
            ->where('status', 'active')
            ->whereNotNull('provider_customer_id')
            ->latest()
            ->first();

        if (! $license) {
            // Check org licenses
            $orgIds = $user->organizations()->pluck('organizations.id');
            $orgLicense = OrganizationLicense::whereIn('organization_id', $orgIds)
                ->where('payment_provider', 'stripe')
                ->where('status', 'active')
                ->whereNotNull('provider_customer_id')
                ->latest()
                ->first();

            if (! $orgLicense) {
                return back()->with('error', 'Geen actief Stripe-abonnement gevonden.');
            }

            $customerId = $orgLicense->provider_customer_id;
        } else {
            $customerId = $license->provider_customer_id;
        }

        try {
            $portalUrl = app(StripeSubscriptionService::class)
                ->createPortalSession($customerId, route('profile.plans'));
        } catch (\Throwable $e) {
            return back()->with('error', 'Kon de beheerportaal niet openen. Probeer het opnieuw.');
        }

        return redirect()->away($portalUrl);
    }

    /**
     * Get primary license data for display
     */
    private function getPrimaryLicenseData($primaryLicense): array
    {
        if (! $primaryLicense) {
            return [
                'exists' => false,
                'name' => null,
                'tier' => 'free',
                'billing_cycle' => null,
                'credits' => 0,
                'price' => null,
                'renewal_date' => null,
                'is_organizational' => false,
                'organization_name' => null,
            ];
        }

        $license = $primaryLicense->license;
        $rawBillingCycle = $license->billing_cycle ?: 'once';
        // Normalize billing cycle (one_time -> once)
        $billingCycle = $rawBillingCycle === 'one_time' ? 'once' : $rawBillingCycle;
        $isOrganizational = $primaryLicense->license_type === 'organizational';
        $creditResetInterval = $license->credit_reset_interval;

        // Calculate credit renewal date (based on credit_reset_interval)
        $creditRenewalDate = null;
        if ($creditResetInterval && $creditResetInterval !== 'none' && $primaryLicense->starts_at) {
            $creditRenewalDate = $this->renewalService->getNextRenewalDate(
                $primaryLicense->starts_at,
                $creditResetInterval
            );
        }

        // Calculate subscription renewal date (based on billing_cycle) - only for recurring
        $subscriptionRenewalDate = null;
        if ($billingCycle && $billingCycle !== 'once' && $primaryLicense->starts_at) {
            $subscriptionRenewalDate = $this->renewalService->getNextRenewalDate(
                $primaryLicense->starts_at,
                $billingCycle
            );
        }

        // For one-time, use ends_at as the date
        $validUntilDate = null;
        if ($billingCycle === 'once' && $license->tier !== 'free') {
            $validUntilDate = $primaryLicense->ends_at;
        }

        // Determine display billing cycle (use credit_reset_interval for credits display)
        $displayBillingCycle = $creditResetInterval ?: $billingCycle;

        // Format price (amount is per month, ex VAT)
        // billing_cycle determines how many months user pays at once
        // Organization: show ex VAT, Personal: show incl VAT (21%)
        $price = null;
        if ($license->tier !== 'free' && $license->amount) {
            $monthlyAmount = $license->amount;
            $currency = $license->currency ?? 'EUR';
            $symbol = LocaleService::getCurrencySymbol($currency);

            // Calculate total amount based on billing_cycle
            if ($billingCycle === 'yearly') {
                $totalAmount = $monthlyAmount * 12;
            } elseif ($billingCycle === '6month') {
                $totalAmount = $monthlyAmount * 6;
            } else {
                $totalAmount = $monthlyAmount;
            }

            if ($isOrganizational) {
                // Organization: show ex VAT
                $vatLabel = ' '.__('profile.ex_vat');
                $displayMonthly = $monthlyAmount;
                $displayTotal = $totalAmount;
            } else {
                // Personal: show incl VAT (21%)
                $vatLabel = ' '.__('profile.incl_vat');
                $displayMonthly = $monthlyAmount * 1.21;
                $displayTotal = $totalAmount * 1.21;
            }

            if ($billingCycle === 'once') {
                $price = $symbol.format_number($displayTotal).$vatLabel;
            } else {
                $price = $symbol.format_number($displayMonthly).'/'.__('profile.month').$vatLabel;
            }
        }

        // Can cancel: only for paid recurring licenses that are not already canceled
        $isRecurring = in_array($billingCycle, ['monthly', 'yearly', '6month']);
        $canCancel = $isRecurring
            && $license->tier !== 'free'
            && in_array($primaryLicense->status, ['active', 'trial'])
            && ! $primaryLicense->ends_at;

        return [
            'exists' => true,
            'id' => $primaryLicense->id,
            'name' => $license->name,
            'tier' => $license->tier,
            'billing_cycle' => $billingCycle,
            'display_billing_cycle' => $displayBillingCycle,
            'credits' => $license->credits ?? 0,
            'price' => $price,
            'credit_renewal_date' => $creditRenewalDate,
            'subscription_renewal_date' => $subscriptionRenewalDate,
            'valid_until_date' => $validUntilDate,
            'ends_at' => $primaryLicense->ends_at,
            'can_cancel' => $canCancel,
            'payment_provider' => $primaryLicense->payment_provider ?? null,
            'is_organizational' => $isOrganizational,
            'organization_name' => $isOrganizational && isset($primaryLicense->source_organization)
                ? $primaryLicense->source_organization->name
                : null,
        ];
    }

    /**
     * Check if user can upgrade (individual users or org admins)
     */
    private function userCanUpgrade($user): bool
    {
        // If user has no organizations, they can always upgrade
        if ($user->organizations->isEmpty()) {
            return true;
        }

        // Check if user is admin of any organization
        foreach ($user->organizations as $organization) {
            if ($organization->pivot->role === OrganizationRole::Owner) {
                return true;
            }
        }

        // User is only a member, not admin
        return false;
    }
}
