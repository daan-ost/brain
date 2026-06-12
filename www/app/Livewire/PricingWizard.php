<?php

namespace App\Livewire;

use App\Models\License;
use App\Models\Organization;
use App\Models\User;
use App\Services\CountryContextResolver;
use App\Services\IPRegistryService;
use App\Services\PricingCalculatorService;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class PricingWizard extends Component
{
    // Step management
    public int $currentStep = 1;

    public string $status = 'ready';

    // Product selection
    public ?int $selectedLicenseId = null;

    public ?array $selectedLicense = null;

    public string $selectedTier = '';

    public ?int $selectedPackage = null;

    public ?int $selectedPremium = null;

    // Payment method selection (for organization licenses)
    public string $paymentMethod = 'mollie'; // 'mollie' or 'invoice'

    // Location and pricing
    public string $userCountry = 'NL';

    public string $currency = 'EUR';

    public array $availableLicenses = [];

    public array $licensesByTier = [];

    // Currency toggle
    public bool $showCurrencyToggle = true;

    // User context
    public ?array $userOrganizations = null;

    public ?array $currentLicenses = null;

    public ?array $countryContext = null;

    // Payer context for country resolution
    public string $payerType = 'user';

    public ?int $payerId = null;

    protected PricingCalculatorService $pricingCalculator;

    protected IPRegistryService $ipRegistry;

    protected CountryContextResolver $countryContextResolver;

    public function boot(
        PricingCalculatorService $pricingCalculator,
        IPRegistryService $ipRegistry,
        CountryContextResolver $countryContextResolver
    ) {
        $this->pricingCalculator = $pricingCalculator;
        $this->ipRegistry = $ipRegistry;
        $this->countryContextResolver = $countryContextResolver;
    }

    public function mount()
    {
        $this->loadUserContext();
        $this->determineCurrency();
        $this->resolveCountryContext();
        $this->loadAvailableLicenses();
    }

    /**
     * Determine currency based on priority:
     * 1. Organization currency_preference (if org context)
     * 2. URL parameter (user override)
     * 3. IP-based detection via resolveCountryContext (default)
     */
    /**
     * Determine which currency to show on the pricing page.
     *
     * Priority order:
     * 1. Organization's currency_preference (locked, no toggle)
     * 2. URL parameter (?currency=USD) — explicit user override
     * 3. Logged-in user's currency_preference from localization settings
     * 4. IP-based country detection via resolveCountryContext()
     */
    protected function determineCurrency(): void
    {
        // Priority 1: Organization context - use organization's currency_preference
        if ($this->payerType === 'organization' && $this->payerId) {
            $org = Organization::find($this->payerId);
            if ($org && $org->currency_preference) {
                $this->currency = $org->currency_preference;
                $this->showCurrencyToggle = false; // NO toggle for organizations

                Log::info('Currency determined from organization preference', [
                    'organization_id' => $org->id,
                    'currency' => $this->currency,
                    'toggle_hidden' => true,
                ]);

                return;
            }
        }

        // Priority 2: URL parameter (user override)
        $urlCurrency = request('currency');
        if (in_array(strtoupper($urlCurrency ?? ''), ['EUR', 'USD'])) {
            $this->currency = strtoupper($urlCurrency);
            $this->showCurrencyToggle = true;

            Log::info('Currency determined from URL parameter', [
                'currency' => $this->currency,
                'toggle_shown' => true,
            ]);

            return;
        }

        // Priority 3: Logged-in user's currency preference
        $user = auth()->user();
        if ($user && $user->currency_preference) {
            $this->currency = $user->currency_preference;
            $this->showCurrencyToggle = true;

            Log::info('Currency determined from user preference', [
                'user_id' => $user->id,
                'currency' => $this->currency,
                'toggle_shown' => true,
            ]);

            return;
        }

        // Priority 4: Will be set by resolveCountryContext() - IP-based detection
        $this->showCurrencyToggle = true;

        Log::info('Currency will be determined from IP detection', [
            'toggle_shown' => true,
        ]);
    }

    /**
     * Resolve country context using cached data
     */
    protected function resolveCountryContext(): void
    {
        if ($this->payerId) {
            $this->countryContext = $this->countryContextResolver->resolveContext($this->payerType, $this->payerId);
            $this->userCountry = $this->countryContext['country'];

            // Only set currency from context if not already set by determineCurrency()
            if (! $this->currency || $this->currency === 'EUR') {
                $this->currency = $this->countryContext['currency'];
            }
        } else {
            // Fallback for unauthenticated users
            $this->userCountry = 'NL';

            // Only set default EUR if currency not already set by URL parameter
            if (! $this->currency || $this->currency === 'EUR') {
                $this->currency = 'EUR';
            }

            $this->countryContext = [
                'country' => 'NL',
                'currency' => $this->currency, // Use determined currency
                'vat_number' => null,
                'vat_valid' => false,
            ];
        }

        Log::info('Country context resolved for pricing', [
            'payer_type' => $this->payerType,
            'payer_id' => $this->payerId,
            'country' => $this->userCountry,
            'currency' => $this->currency,
            'from_cache' => $this->payerId !== null,
        ]);
    }

    /**
     * Load user organizations and current licenses, set default payer
     */
    protected function loadUserContext(): void
    {
        if (! auth()->check()) {
            $this->payerType = 'user';
            $this->payerId = null;

            return;
        }

        $user = auth()->user();

        // Load user organizations
        $this->userOrganizations = $user->organizations()
            ->select('organizations.id', 'organizations.name')
            ->get()
            ->map(function ($org) {
                return [
                    'id' => $org->id,
                    'name' => $org->name,
                ];
            })
            ->toArray();

        // Set default payer context (organization preferred if available)
        if (! empty($this->userOrganizations)) {
            $this->payerType = 'organization';
            $this->payerId = $this->userOrganizations[0]['id'];
        } else {
            $this->payerType = 'user';
            $this->payerId = $user->id;
        }

        // Load current licenses for badge display
        $this->loadCurrentLicenses();
    }

    /**
     * Load current active licenses for the user and organizations
     */
    protected function loadCurrentLicenses(): void
    {
        if (! auth()->check()) {
            return;
        }

        $user = auth()->user();
        $currentLicenses = [];

        // Get user's current licenses
        $userLicenses = $user->userLicenses()
            ->active()
            ->current()
            ->with('license')
            ->get();

        foreach ($userLicenses as $userLicense) {
            $tier = $userLicense->license->tier;
            $currentLicenses['user'][$tier] = [
                'id' => $userLicense->license->id,
                'name' => $userLicense->license->name,
                'ends_at' => $userLicense->ends_at,
            ];
        }

        // Get organization licenses if user has organizations
        if ($this->userOrganizations) {
            foreach ($this->userOrganizations as $org) {
                $orgLicenses = Organization::find($org['id'])
                    ->organizationLicenses()
                    ->active()
                    ->current()
                    ->with('license')
                    ->get();

                foreach ($orgLicenses as $orgLicense) {
                    $tier = $orgLicense->license->tier;
                    $currentLicenses['organizations'][$org['id']][$tier] = [
                        'id' => $orgLicense->license->id,
                        'name' => $orgLicense->license->name,
                        'ends_at' => $orgLicense->ends_at,
                    ];
                }
            }
        }

        $this->currentLicenses = $currentLicenses;
    }

    /**
     * Load available licenses for the detected currency
     */
    protected function loadAvailableLicenses(): void
    {
        $this->licensesByTier = $this->pricingCalculator->getLicenseCatalog($this->currency);

        // Flatten for easier access
        $this->availableLicenses = [];
        foreach ($this->licensesByTier as $tier => $licenses) {
            foreach ($licenses as $license) {
                $this->availableLicenses[$license->id] = [
                    'id' => $license->id,
                    'name' => $license->name,
                    'tier' => $license->tier,
                    'amount' => $license->amount,
                    'currency' => $license->currency,
                    'credits' => $license->credits,
                    'billing_cycle' => $license->billing_cycle,
                    'credit_reset_interval' => $license->credit_reset_interval ?? 'none',
                    'period' => $license->period,
                    'restrictions' => $license->json_restrictions,
                ];
            }
        }

        // Auto-select first license in each tier
        foreach ($this->licensesByTier as $tier => $licenses) {
            if (! empty($licenses)) {
                // Convert to collection and sort by ordering
                $sortedLicenses = collect($licenses)->sortBy('ordering');
                $firstLicense = $sortedLicenses->first();

                if ($tier === 'onetime' && ! $this->selectedPackage) {
                    $this->selectedPackage = $firstLicense->id;
                } elseif ($tier === 'premium' && ! $this->selectedPremium) {
                    $this->selectedPremium = $firstLicense->id;
                }
            }
        }

        Log::info('Loaded available licenses for pricing wizard', [
            'currency' => $this->currency,
            'license_count' => count($this->availableLicenses),
            'tiers' => array_keys($this->licensesByTier),
            'selected_package' => $this->selectedPackage,
            'selected_premium' => $this->selectedPremium,
        ]);
    }

    /**
     * Check if user can proceed to checkout (auth + email verified)
     */
    public function canProceedToCheckout(): array
    {
        $result = [
            'can_proceed' => false,
            'is_authenticated' => false,
            'is_verified' => false,
            'reason' => null,
        ];

        // Check authentication
        if (! auth()->check()) {
            $result['reason'] = 'not_authenticated';

            return $result;
        }

        $result['is_authenticated'] = true;

        // Check email verification
        if (! auth()->user()->hasVerifiedEmail()) {
            $result['reason'] = 'not_verified';

            return $result;
        }

        $result['is_verified'] = true;
        $result['can_proceed'] = true;

        return $result;
    }

    /**
     * Show authentication required modal
     */
    public function showAuthRequiredModal(): void
    {
        $this->dispatch('show-auth-required-modal');
    }

    /**
     * Show email verification required modal
     */
    public function showVerificationRequiredModal(): void
    {
        $this->dispatch('show-verification-required-modal');
    }

    /**
     * Select a license and prepare for checkout
     *
     * @param  string  $paymentMethod  'mollie' (default) or 'invoice'
     */
    public function selectLicense(int $licenseId, string $tier, string $paymentMethod = 'mollie'): void
    {
        $checkoutCheck = $this->canProceedToCheckout();

        if (! $checkoutCheck['can_proceed']) {
            $this->handleCheckoutBlocker($checkoutCheck['reason']);

            return;
        }

        // Invoice payment requires organization context
        if ($paymentMethod === 'invoice' && $this->payerType !== 'organization') {
            $this->addError('selection', 'Invoice payment is only available for organizations');

            return;
        }

        if (! isset($this->availableLicenses[$licenseId])) {
            $this->addError('selection', 'Invalid license selected');

            return;
        }

        $this->selectedLicenseId = $licenseId;
        $this->selectedLicense = $this->availableLicenses[$licenseId];
        $this->selectedTier = $tier;
        $this->paymentMethod = $paymentMethod;

        // Calculate pricing with VAT
        $license = License::find($licenseId);
        $this->selectedLicense['pricing'] = $this->pricingCalculator->calculatePricing(
            $license,
            $this->userCountry
        );

        // Store session data based on payment method
        if ($paymentMethod === 'invoice') {
            session([
                'selected_license_id' => $licenseId,
                'payment_method' => 'invoice',
                'payer_type' => $this->payerType,
                'payer_id' => $this->payerId,
            ]);
        } else {
            session()->forget(['payment_method', 'payer_type', 'payer_id']);
            session(['selected_license_id' => $licenseId]);
        }

        Log::debug('License selected in pricing wizard', [
            'license_id' => $licenseId,
            'tier' => $tier,
            'payment_method' => $paymentMethod,
            'organization_id' => $paymentMethod === 'invoice' ? $this->payerId : null,
            'country' => $this->userCountry,
        ]);

        $this->redirectToCheckout();
    }

    /**
     * Select a license with invoice payment method (organization only)
     * @deprecated Use selectLicense() with paymentMethod='invoice' instead
     */
    public function selectLicenseWithInvoice(int $licenseId, string $tier): void
    {
        $this->selectLicense($licenseId, $tier, 'invoice');
    }

    /**
     * Handle checkout blockers (auth/verification)
     */
    private function handleCheckoutBlocker(?string $reason): void
    {
        match ($reason) {
            'not_authenticated' => $this->dispatch('show-auth-required-modal'),
            'not_verified' => $this->dispatch('show-verification-required-modal'),
            default => null,
        };
    }

    /**
     * Switch payer context and refresh pricing
     */
    public function switchPayer(string $type, int $id): void
    {
        $this->payerType = $type;
        $this->payerId = $id;

        // Re-resolve country context for new payer
        $this->resolveCountryContext();

        // Refresh available licenses in case currency changed
        $this->loadAvailableLicenses();

        Log::info('Payer switched in pricing wizard', [
            'payer_type' => $this->payerType,
            'payer_id' => $this->payerId,
            'country' => $this->userCountry,
            'currency' => $this->currency,
        ]);
    }

    /**
     * Select free plan
     */
    public function selectFreePlan()
    {
        // For free plan, redirect to registration or dashboard
        if (auth()->check()) {
            return redirect()->route('uploads')->with('info', 'You already have access to free features!');
        } else {
            return redirect()->route('register')->with('info', 'Create your free account to get started!');
        }
    }

    /**
     * Contact sales for enterprise
     */
    public function contactSales()
    {
        return redirect()->route('contact');
    }

    /**
     * Redirect to checkout with selected license
     */
    protected function redirectToCheckout()
    {
        if (! $this->selectedLicenseId) {
            $this->addError('selection', 'No license selected');

            return;
        }

        $params = [
            'license' => $this->selectedLicenseId,
            'tier' => $this->selectedTier,
            'currency' => $this->currency, // Pass currency to checkout
        ];

        // Add payment method to URL if invoice was selected
        if ($this->paymentMethod === 'invoice') {
            $params['payment_method'] = 'invoice';
        }

        Log::info('Redirecting to checkout with currency', [
            'license_id' => $this->selectedLicenseId,
            'currency' => $this->currency,
            'params' => $params,
        ]);

        return redirect()->route('checkout', $params);
    }

    /**
     * Check if user/organization has current license for tier
     *
     * Special case: Free tier is currency-agnostic (0 EUR = 0 USD)
     * Users with free EUR license will see "Current" badge on both EUR and USD free cards
     */
    public function hasCurrentLicense(string $tier, ?int $organizationId = null): bool
    {
        if (! $this->currentLicenses) {
            return false;
        }

        // Special case: Free tier is currency-agnostic
        // If user has free EUR, show "Current" on both EUR and USD free cards
        if ($tier === 'free') {
            if ($organizationId) {
                return isset($this->currentLicenses['organizations'][$organizationId]['free']);
            } else {
                return isset($this->currentLicenses['user']['free']);
            }
        }

        // Standard tier check for paid licenses (currency-specific)
        // EUR premium != USD premium
        if ($organizationId) {
            return isset($this->currentLicenses['organizations'][$organizationId][$tier]);
        } else {
            return isset($this->currentLicenses['user'][$tier]);
        }
    }

    /**
     * Get current license info for display
     */
    public function getCurrentLicenseInfo(string $tier, ?int $organizationId = null): ?array
    {
        if (! $this->hasCurrentLicense($tier, $organizationId)) {
            return null;
        }

        if ($organizationId) {
            return $this->currentLicenses['organizations'][$organizationId][$tier];
        } else {
            return $this->currentLicenses['user'][$tier];
        }
    }

    /**
     * Format price for display (NET amount - will be updated to show gross)
     */
    public function formatPrice(float $amount, string $currency): string
    {
        return $this->pricingCalculator->formatAmount($amount, $currency);
    }

    /**
     * Calculate pricing with VAT for a license using cached context
     */
    public function calculateLicensePricing(License $license): array
    {
        $vatId = $this->countryContext['vat_number'] ?? null;
        $isValidVat = $this->countryContext['vat_valid'] ?? false;

        return $this->pricingCalculator->calculatePricing(
            $license,
            $this->userCountry,
            $isValidVat ? $vatId : null,
            ! empty($vatId) // Assume company if VAT number provided
        );
    }

    /**
     * Format price with VAT information for display
     */
    public function formatPriceWithVat(License $license): array
    {
        $pricing = $this->calculateLicensePricing($license);

        // Determine if we should show "ex VAT" (net amount) or include VAT (gross amount)
        // Show ex VAT when:
        // 1. Currency is USD (always ex VAT for USD)
        // 2. EUR + EU organization + valid VAT number
        $showExVat = false;
        $isEuWithValidVat = false;

        if ($pricing['currency'] === 'USD') {
            $showExVat = true;
        } elseif ($pricing['currency'] === 'EUR') {
            // Check if it's an EU organization with valid VAT
            $isEuWithValidVat = $this->pricingCalculator->isEuCountry($this->userCountry)
                && ($this->countryContext['vat_valid'] ?? false);
            $showExVat = $isEuWithValidVat;
        }

        $result = [
            'gross_formatted' => $this->pricingCalculator->formatAmount($pricing['gross_amount'], $pricing['currency'], false),
            'net_formatted' => $this->pricingCalculator->formatAmount($pricing['net_amount'], $pricing['currency'], true),
            'vat_formatted' => $this->pricingCalculator->formatAmount($pricing['tax_amount'], $pricing['currency'], false),
            'vat_rate' => $pricing['vat_rate'],
            'vat_applicable' => $pricing['vat_applicable'],
            'vat_reverse_charge' => $pricing['vat_reverse_charge'],
            'show_ex_vat' => $showExVat,
            'display_amount' => $showExVat ? $pricing['net_amount'] : $pricing['gross_amount'],
            'display_formatted' => $showExVat
                ? $this->pricingCalculator->formatAmount($pricing['net_amount'], $pricing['currency'], true)
                : $this->pricingCalculator->formatAmount($pricing['gross_amount'], $pricing['currency'], false),
            'pricing' => $pricing,
        ];

        // Create display text for VAT information
        // Only show label when "ex VAT" applies (USD or EU with valid VAT)
        if ($showExVat) {
            // Show "ex VAT" / "ex BTW"
            $result['vat_text'] = __('pricing.excl_vat');
            $result['secondary_text'] = '';
        } else {
            // No label shown for regular prices (includes VAT by default)
            $result['vat_text'] = '';
            $result['secondary_text'] = '';
        }

        return $result;
    }

    /**
     * Get validity text for onetime licenses
     */
    public function getValidityText(?int $period): string
    {
        return License::formatValidityPeriod($period);
    }

    /**
     * Get license restrictions in a readable format
     */
    public function getLicenseRestrictions(License $license): array
    {
        $restrictions = $license->json_restrictions ?? [];

        $maxFiles = $restrictions['upload_limits']['global']['max_files'] ?? null;
        $maxFileSize = $restrictions['upload_limits']['global']['max_file_size'] ?? null;

        // Convert bytes to MB and round
        $maxFileSizeMB = $maxFileSize ? round($maxFileSize / 1024 / 1024) : null;

        return [
            'max_files' => $maxFiles,
            'max_file_size_mb' => $maxFileSizeMB,
        ];
    }

    /**
     * Check if current payer is an organization
     */
    public function isOrganizationPayer(): bool
    {
        return $this->payerType === 'organization' && $this->payerId;
    }

    /**
     * Check if invoice payment requirements are met
     */
    public function canPayByInvoice(): array
    {
        $result = [
            'can_pay' => false,
            'has_organization' => false,
            'has_vat_number' => false,
            'vat_validated' => false,
            'is_eu_country' => false,
            'missing_requirements' => [],
        ];

        // Check if user has organization and is admin
        if (! $this->isOrganizationPayer()) {
            $result['missing_requirements'][] = 'organization';

            return $result;
        }

        $result['has_organization'] = true;

        // Get organization data
        $organization = Organization::find($this->payerId);
        if (! $organization) {
            $result['missing_requirements'][] = 'organization';

            return $result;
        }

        // Check if organization has VAT number
        $hasVatNumber = ! empty($organization->vat_number);
        $result['has_vat_number'] = $hasVatNumber;

        if (! $hasVatNumber) {
            $result['missing_requirements'][] = 'vat_number';
        }

        // Check if country is EU
        $isEuCountry = $this->pricingCalculator->isEuCountry($this->userCountry);
        $result['is_eu_country'] = $isEuCountry;

        // For EU countries, VAT must be validated
        if ($isEuCountry && $hasVatNumber) {
            // Check if VAT is validated via country context
            $vatValidated = $this->countryContext['vat_valid'] ?? false;
            $result['vat_validated'] = $vatValidated;

            if (! $vatValidated) {
                $result['missing_requirements'][] = 'vat_validation';
            }
        }

        // Determine if can pay by invoice
        if ($isEuCountry) {
            // EU: needs organization + VAT number + VAT validated
            $result['can_pay'] = $result['has_organization'] && $result['has_vat_number'] && $result['vat_validated'];
        } else {
            // Non-EU: needs organization + VAT number (no validation required)
            $result['can_pay'] = $result['has_organization'] && $result['has_vat_number'];
        }

        return $result;
    }

    /**
     * Show invoice requirements modal
     */
    public function showInvoiceRequirements(): void
    {
        $this->dispatch('show-invoice-requirements-modal');
    }

    public function render()
    {
        return view('livewire.pricing-wizard');
    }
}
