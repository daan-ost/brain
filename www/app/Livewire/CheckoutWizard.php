<?php

namespace App\Livewire;

use App\Enums\OrganizationRole;
use App\Enums\OrderStatus;
use App\Models\License;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationLicense;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\CountryContextResolver;
use App\Services\IPRegistryService;
use App\Services\MolliePaymentService;
use App\Services\PaymentFulfillmentService;
use App\Services\PricingCalculatorService;
use App\Services\VIESValidationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

class CheckoutWizard extends Component
{
    // Step management
    public int $currentStep = 2;

    public string $status = 'ready';

    // Selected license
    public ?int $licenseId = null;

    public ?array $licenseData = null;

    public ?array $pricingData = null;

    // Order management
    public ?string $orderId = null;

    public ?array $orderData = null;

    // Payer selection
    public ?string $payerType = null; // 'user' or 'organization' - null until user selects

    public ?int $payerId = null;

    public array $availableOrganizations = [];

    public bool $isTrustedOrganization = false;

    // Location and billing
    public string $country = 'NL';

    public string $currency = 'EUR';

    public string $buyerType = 'individual'; // 'individual' or 'company'

    // Billing form data
    public array $billingData = [
        'full_name' => '',
        'company_name' => '',
        'vat_id' => '',
        'company_id' => '',
        'internal_reference' => '',
        'street' => '',
        'postal_code' => '',
        'city' => '',
        'state' => '',
        'email' => '',
    ];

    // VAT validation
    public ?array $vatValidation = null;

    public bool $vatValidating = false;

    // Payment methods
    public array $paymentMethods = [];

    public ?string $selectedPaymentMethod = null;

    public ?string $preselectedPaymentMethod = null; // For invoice flow

    /**
     * Handle payment method selection updates for reactive UI
     */
    public function updatedSelectedPaymentMethod($value)
    {
        // This method triggers Livewire re-render when payment method changes
        // Ensures visual feedback works properly
    }

    // Services
    protected PricingCalculatorService $pricingCalculator;

    protected IPRegistryService $ipRegistry;

    protected VIESValidationService $viesValidator;

    protected MolliePaymentService $molliePayment;

    protected CountryContextResolver $countryContextResolver;

    protected \App\Services\PaymentProviderManager $providers;

    public function boot(
        PricingCalculatorService $pricingCalculator,
        IPRegistryService $ipRegistry,
        VIESValidationService $viesValidator,
        MolliePaymentService $molliePayment,
        CountryContextResolver $countryContextResolver,
        \App\Services\PaymentProviderManager $providers
    ) {
        $this->pricingCalculator = $pricingCalculator;
        $this->ipRegistry = $ipRegistry;
        $this->viesValidator = $viesValidator;
        $this->molliePayment = $molliePayment;
        $this->countryContextResolver = $countryContextResolver;
        $this->providers = $providers;
    }

    public function mount()
    {
        // Get license from URL parameters or session
        $this->licenseId = request('license') ?? Session::get('selected_license_id');

        if (! $this->licenseId) {
            return redirect()->route('pricing')->with('error', 'Please select a license first.');
        }

        // Check authentication
        if (! auth()->check()) {
            return redirect()->route('login')
                ->with('error', __('checkout.login_required'))
                ->with('intended', route('checkout', ['license' => $this->licenseId]));
        }

        // Check email verification
        if (! auth()->user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice')
                ->with('error', __('checkout.verification_required'));
        }

        // NEW: Accept currency from URL parameter (passed from pricing page)
        $urlCurrency = request('currency');
        if (in_array(strtoupper($urlCurrency ?? ''), ['EUR', 'USD'])) {
            $this->currency = strtoupper($urlCurrency);

            Log::info('Currency parameter received in checkout', [
                'currency' => $this->currency,
                'license_id' => $this->licenseId,
            ]);
        }

        // Load license data first to check tier
        $this->loadLicenseData();

        // Check for payment method - ONLY use URL parameter, ignore session for payment_method
        $urlPaymentMethod = request('payment_method');

        // Determine payment flow based ONLY on URL parameter
        if ($urlPaymentMethod === 'invoice') {
            $this->preselectedPaymentMethod = 'invoice';

            Log::info('CheckoutWizard mount - Invoice payment detected via URL', [
                'url_payment_method' => $urlPaymentMethod,
                'license_tier' => $this->licenseData['tier'] ?? null,
                'license_id' => $this->licenseId,
            ]);
        } else {
            // Default to online payment (Mollie)
            $this->preselectedPaymentMethod = null;

            // Clean any leftover session data from previous invoice flows
            Session::forget(['payment_method', 'payer_type', 'payer_id']);

            Log::info('CheckoutWizard mount - Online payment flow (session cleaned)', [
                'url_payment_method' => $urlPaymentMethod ?? 'none',
                'preselected_payment_method' => 'null (online/Mollie)',
                'session_cleaned' => true,
                'license_tier' => $this->licenseData['tier'] ?? null,
                'license_id' => $this->licenseId,
            ]);
        }

        $this->loadUserContext();
        $this->resolveCountryContext();

        // Automatically set buyer type based on payer type
        if ($this->payerType) {
            $this->determineBuyerType();
        }

        // For invoice payments with preselected organization, populate billing data
        if ($this->preselectedPaymentMethod === 'invoice' && $this->payerType === 'organization' && $this->payerId) {
            $this->populateBillingFromPayer();
        }

        $this->calculateInitialPricing();
        $this->loadPaymentMethods();

        // Final debug log to confirm payment flow
        Log::info('CheckoutWizard mount complete - payment flow determined', [
            'final_preselected_payment_method' => $this->preselectedPaymentMethod,
            'will_show_screen' => $this->preselectedPaymentMethod === 'invoice' ? 'Invoice screen (Betalen per factuur)' : 'Mollie options (Online betalen)',
            'payer_type' => $this->payerType,
            'payer_id' => $this->payerId,
            'license_id' => $this->licenseId,
            'license_tier' => $this->licenseData['tier'] ?? null,
            'payment_methods_loaded' => count($this->paymentMethods ?? []),
        ]);

        // Log checkout started event for analytics
        if ($this->licenseData) {
            AnalyticsService::log('checkout_started', [
                'license_id' => $this->licenseId,
                'license_slug' => $this->licenseData['slug'] ?? null,
                'license_tier' => $this->licenseData['tier'] ?? null,
                'payment_flow' => $this->preselectedPaymentMethod ?? 'online',
                'currency' => $this->currency,
                'payer_type' => $this->payerType,
                'payer_id' => $this->payerId,
                'is_trusted' => $this->isTrustedOrganization,
            ]);
        }
    }

    /**
     * Load selected license data
     */
    protected function loadLicenseData(): void
    {
        $license = License::find($this->licenseId);

        if (! $license || ! $license->isActive()) {
            session()->flash('error', 'Selected license is not available.');

            return;
        }

        $this->licenseData = [
            'id' => $license->id,
            'slug' => $license->slug,
            'name' => $license->name,
            'tier' => $license->tier,
            'amount' => $license->amount,
            'currency' => $license->currency,
            'credits' => $license->credits,
            'billing_cycle' => $license->billing_cycle,
            'credit_reset_interval' => $license->credit_reset_interval ?? 'none',
            'period' => $license->period,
        ];

        Log::info('License data loaded for checkout', [
            'license_id' => $this->licenseId,
            'license_data' => $this->licenseData,
        ]);
    }

    /**
     * Load user organizations and set default payer
     */
    protected function loadUserContext(): void
    {
        if (! auth()->check()) {
            // For guest users, set a default payer configuration
            $this->payerType = 'user';
            $this->payerId = null; // Will be handled as guest purchase

            return;
        }

        $user = auth()->user();

        // Try to prefill from most recent paid order first
        $this->prefillFromPreviousOrder($user);

        // Fallback to user data if no previous order found
        if (empty($this->billingData['full_name'])) {
            $this->billingData['full_name'] = $user->name ?? '';
        }
        if (empty($this->billingData['email'])) {
            $this->billingData['email'] = $user->email ?? '';
        }

        // Load user organizations
        $this->availableOrganizations = $user->organizations()
            ->select('organizations.id', 'organizations.name')
            ->get()
            ->map(function ($org) {
                return [
                    'id' => $org->id,
                    'name' => $org->name,
                ];
            })
            ->toArray();

        // Check if payer context was set by pricing wizard (for invoice payments)
        $sessionPayerType = Session::get('payer_type');
        $sessionPayerId = Session::get('payer_id');

        if ($sessionPayerType && $sessionPayerId && $this->preselectedPaymentMethod === 'invoice') {
            // Use the payer from pricing wizard
            $this->payerType = $sessionPayerType;
            $this->payerId = $sessionPayerId;
            $this->updateTrustedStatus();
            Log::info('Using payer from pricing wizard session', [
                'payer_type' => $this->payerType,
                'payer_id' => $this->payerId,
                'is_trusted' => $this->isTrustedOrganization,
            ]);
        } elseif ($this->preselectedPaymentMethod === 'invoice' && ! empty($this->availableOrganizations)) {
            // For invoice payments, auto-select first organization
            $this->payerType = 'organization';
            $this->payerId = $this->availableOrganizations[0]['id'];
            $this->updateTrustedStatus();
            Log::info('Auto-selected first organization for invoice payment', [
                'payer_type' => $this->payerType,
                'payer_id' => $this->payerId,
                'is_trusted' => $this->isTrustedOrganization,
            ]);
        } else {
            // Check if user has organizations to choose from
            if (empty($this->availableOrganizations)) {
                // No organizations available - auto-select personal purchase
                $this->payerType = 'user';
                $this->payerId = auth()->id();
            } else {
                // User has organizations - let them choose
                $this->payerType = null;
                $this->payerId = null;
            }
        }

        Log::info('User context loaded for checkout', [
            'user_id' => $user->id,
            'organizations_count' => count($this->availableOrganizations),
            'requires_payer_selection' => true,
        ]);
    }

    /**
     * Prefill billing data from user's most recent paid order
     */
    protected function prefillFromPreviousOrder(User $user): void
    {
        // Get most recent paid order for this user (as direct payer or via organization)
        $recentOrder = Order::where(function ($query) use ($user) {
            $query->where(function ($q) use ($user) {
                // User as direct payer
                $q->where('payer_type', 'user')
                    ->where('payer_id', $user->id);
            })->orWhere(function ($q) use ($user) {
                // Organization payer where user is member
                $organizationIds = $user->organizations()->pluck('organizations.id')->toArray();
                if (! empty($organizationIds)) {
                    $q->where('payer_type', 'organization')
                        ->whereIn('payer_id', $organizationIds);
                }
            });
        })
            ->whereIn('status', ['Paid', 'paid'])
            ->whereNotNull('billing_snapshot')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($recentOrder && ! empty($recentOrder->billing_snapshot)) {
            $snapshot = $recentOrder->billing_snapshot;

            // Prefill billing data from previous order
            $this->billingData['full_name'] = $snapshot['full_name'] ?? '';
            $this->billingData['company_name'] = $snapshot['company_name'] ?? '';
            $this->billingData['vat_id'] = $snapshot['vat_id'] ?? '';
            $this->billingData['company_id'] = $snapshot['company_id'] ?? '';
            $this->billingData['internal_reference'] = $snapshot['internal_reference'] ?? '';
            $this->billingData['street'] = $snapshot['street'] ?? '';
            $this->billingData['postal_code'] = $snapshot['postal_code'] ?? '';
            $this->billingData['city'] = $snapshot['city'] ?? '';
            $this->billingData['state'] = $snapshot['state'] ?? '';
            $this->billingData['email'] = $snapshot['email'] ?? '';

            // Also set country if available in the order
            if (! empty($recentOrder->country)) {
                $this->country = $recentOrder->country;
            }

            // Automatically set buyer type based on previous order
            if (! empty($snapshot['company_name'])) {
                $this->buyerType = 'company';
            } else {
                $this->buyerType = 'individual';
            }

            Log::info('Prefilled billing data from previous order', [
                'order_id' => $recentOrder->id,
                'order_date' => $recentOrder->created_at,
                'buyer_type' => $this->buyerType,
                'has_company_name' => ! empty($snapshot['company_name']),
                'country' => $this->country,
            ]);
        } else {
            Log::info('No previous paid order found for prefill', [
                'user_id' => $user->id,
            ]);
        }
    }

    /**
     * Resolve country context from cached payer data
     */
    protected function resolveCountryContext(): void
    {
        if ($this->payerId && $this->payerType) {
            $context = $this->countryContextResolver->resolveContext($this->payerType, $this->payerId);

            $this->country = $context['country'];
            $this->currency = $context['currency'];

            // Pre-fill VAT data if available
            if ($context['vat_number']) {
                $this->billingData['vat_id'] = $context['vat_number'];

                if ($context['vat_valid']) {
                    $this->vatValidation = [
                        'valid' => true,
                        'validated_at' => $context['vat_validated_at'],
                    ];
                }
            }

            Log::info('Country context resolved for checkout', [
                'payer_type' => $this->payerType,
                'payer_id' => $this->payerId,
                'country' => $this->country,
                'currency' => $this->currency,
                'has_cached_vat' => ! empty($context['vat_number']),
            ]);
        } else {
            // Fallback for edge cases
            $this->country = 'NL';
            $this->currency = 'EUR';
        }
    }

    /**
     * Calculate initial pricing based on location
     */
    protected function calculateInitialPricing(): void
    {
        if (! $this->licenseData) {
            return;
        }

        $license = License::find($this->licenseId);

        // Use calculateBillingAmount (includes billing cycle multiplier)
        $pricing = $this->pricingCalculator->calculateBillingAmount(
            $license,
            $this->country,
            $this->billingData['vat_id'] ?? null,
            $this->buyerType === 'company'
        );

        $this->pricingData = $pricing;

        Log::info('Initial pricing calculated with VAT', [
            'license_id' => $this->licenseId,
            'country' => $this->country,
            'buyer_type' => $this->buyerType,
            'vat_rate' => $pricing['vat_rate'],
            'net_amount' => $pricing['net_amount'],
            'vat_amount' => $pricing['tax_amount'],
            'gross_amount' => $pricing['gross_amount'],
            'pricing' => $pricing,
        ]);
    }

    /**
     * Load available payment methods from Mollie
     */
    protected function loadPaymentMethods(): void
    {
        if (! $this->pricingData) {
            return;
        }

        try {
            $locale = $this->determineLocale();
            $isSubscription = $this->licenseData['tier'] === 'premium';

            // Reset before loading so a failed API call never leaves a stale incompatible selection.
            $this->selectedPaymentMethod = null;

            $result = $this->molliePayment->getPaymentMethods(
                $this->pricingData['gross_amount'],
                $this->pricingData['currency'],
                $locale,
                $isSubscription // Only show recurring-compatible methods for subscriptions
            );

            if ($result['success']) {
                $this->paymentMethods = $result['methods'];

                // Auto-select first payment method
                if (! empty($this->paymentMethods)) {
                    $this->selectedPaymentMethod = $this->paymentMethods[0]['id'];
                }
            }

        } catch (\Exception $e) {
            Log::error('Failed to load payment methods', [
                'error' => $e->getMessage(),
                'gross_amount' => $this->pricingData['gross_amount'] ?? 0,
                'currency' => $this->pricingData['currency'] ?? 'EUR',
            ]);
        }
    }

    /**
     * Handle country change - persist and recalculate pricing
     */
    public function updatedCountry($value): void
    {
        $this->country = strtoupper($value);
        $this->currency = $this->pricingCalculator->determineCurrency($this->country);

        // Persist country change to payer
        if ($this->payerId) {
            $this->countryContextResolver->updateContext($this->payerType, $this->payerId, [
                'country' => $this->country,
            ]);
        }

        $this->calculatePricing();
        $this->loadPaymentMethods();
    }

    /**
     * Automatically determine buyer type based on payer
     */
    protected function determineBuyerType(): void
    {
        if ($this->payerType === 'organization') {
            $this->buyerType = 'company';
        } else {
            $this->buyerType = 'individual';
        }
    }

    /**
     * Handle VAT ID change - persist and validate if company and EU
     */
    public function updatedBillingDataVatId($value): void
    {
        $this->billingData['vat_id'] = strtoupper(trim($value));

        // Persist VAT number change to payer
        if ($this->payerId) {
            $updatedContext = $this->countryContextResolver->updateContext($this->payerType, $this->payerId, [
                'vat_number' => $this->billingData['vat_id'],
            ]);

            // Update local validation state from resolver
            if ($updatedContext['vat_valid']) {
                $this->vatValidation = [
                    'valid' => true,
                    'validated_at' => $updatedContext['vat_validated_at'],
                ];
            } else {
                $this->vatValidation = [
                    'valid' => false,
                    'error' => 'VAT ID not valid',
                ];
            }
        } else {
            // Fallback to direct validation if no payer context
            $this->validateVatId();
        }

        $this->calculatePricing();
    }

    /**
     * Validate VAT ID via VIES (fallback method)
     */
    public function validateVatId(): void
    {
        $vatId = $this->billingData['vat_id'] ?? '';

        if (empty($vatId) || $this->buyerType !== 'company' || ! $this->pricingCalculator->isEuCountry($this->country)) {
            $this->vatValidation = null;

            return;
        }

        $this->vatValidating = true;

        try {
            $this->vatValidation = $this->viesValidator->validateVatId($vatId);

        } catch (\Exception $e) {
            Log::error('VAT validation failed', [
                'vat_id' => $vatId,
                'error' => $e->getMessage(),
            ]);

            $this->vatValidation = [
                'valid' => false,
                'error' => 'Validation service unavailable',
            ];
        } finally {
            $this->vatValidating = false;
        }
    }

    /**
     * Calculate pricing with current form data
     */
    protected function calculatePricing(): void
    {
        if (! $this->licenseData) {
            return;
        }

        $license = License::find($this->licenseId);

        // Use calculateBillingAmount for checkout (includes billing cycle multiplier)
        $pricing = $this->pricingCalculator->calculateBillingAmount(
            $license,
            $this->country,
            $this->billingData['vat_id'] ?? null,
            $this->buyerType === 'company'
        );

        $this->pricingData = $pricing;

        Log::debug('Pricing recalculated in checkout', [
            'country' => $this->country,
            'buyer_type' => $this->buyerType,
            'has_vat_id' => ! empty($this->billingData['vat_id']),
            'vat_rate' => $pricing['vat_rate'],
            'gross_amount' => $pricing['gross_amount'],
        ]);
    }

    /**
     * Switch payer (user vs organization)
     */
    public function switchPayer(string $type, ?int $id = null): void
    {
        $this->payerType = $type;
        $this->payerId = $id ?? auth()->id();

        // Automatically set buyer type based on payer type
        $this->determineBuyerType();

        // Update trusted status for organizations
        $this->updateTrustedStatus();

        // Pre-populate billing information based on payer type
        $this->populateBillingFromPayer();

        // Resolve country context for the new payer
        $this->resolveCountryContext();
        $this->calculatePricing();

        Log::info('Payer switched in checkout', [
            'payer_type' => $this->payerType,
            'payer_id' => $this->payerId,
            'buyer_type' => $this->buyerType,
            'is_trusted' => $this->isTrustedOrganization,
        ]);

        // Log payer selection for analytics
        AnalyticsService::log('checkout_payer_selected', [
            'license_id' => $this->licenseId,
            'license_slug' => $this->licenseData['slug'] ?? null,
            'payer_type' => $this->payerType,
            'payer_id' => $this->payerId,
            'is_trusted' => $this->isTrustedOrganization,
            'buyer_type' => $this->buyerType,
        ]);
    }

    /**
     * Update trusted organization status
     */
    protected function updateTrustedStatus(): void
    {
        if ($this->payerType === 'organization' && $this->payerId) {
            $organization = Organization::find($this->payerId);
            $this->isTrustedOrganization = $organization && $organization->is_trusted;
        } else {
            $this->isTrustedOrganization = false;
        }
    }

    /**
     * Populate billing information based on selected payer
     */
    protected function populateBillingFromPayer(): void
    {
        if ($this->payerType === 'organization' && $this->payerId) {
            $organization = Organization::find($this->payerId);

            if ($organization) {
                // Get organization billing info from settings or direct fields
                $settings = $organization->settings ?? [];

                // Pre-populate with organization data, but use prefilled data as fallback
                $this->billingData['company_name'] = $organization->name;
                $this->billingData['full_name'] = $settings['billing_contact_name'] ?? $this->billingData['full_name'] ?? '';
                $this->billingData['email'] = $settings['billing_email'] ?? auth()->user()->email ?? $this->billingData['email'] ?? '';
                $this->billingData['vat_id'] = $organization->vat_number ?? $this->billingData['vat_id'] ?? '';
                $this->billingData['street'] = $settings['billing_street'] ?? $this->billingData['street'] ?? '';
                $this->billingData['postal_code'] = $settings['billing_postal_code'] ?? $this->billingData['postal_code'] ?? '';
                $this->billingData['city'] = $settings['billing_city'] ?? $this->billingData['city'] ?? '';
                $this->billingData['state'] = $settings['billing_state'] ?? $this->billingData['state'] ?? '';
                $this->billingData['company_id'] = $settings['company_registration'] ?? $this->billingData['company_id'] ?? '';
                $this->billingData['internal_reference'] = $this->billingData['internal_reference'] ?? '';

                Log::info('Pre-populated billing data from organization', [
                    'organization_id' => $this->payerId,
                    'has_vat_number' => ! empty($organization->vat_number),
                    'has_billing_address' => ! empty($settings['billing_street']),
                    'used_prefill_fallback' => empty($settings['billing_street']),
                ]);
            }
        } elseif ($this->payerType === 'user') {
            // For user payer, keep prefilled data if available
            $user = auth()->user();

            if ($user) {
                // Only reset if no prefilled data exists
                if (empty($this->billingData['full_name'])) {
                    $this->billingData['full_name'] = $user->name ?? '';
                }
                if (empty($this->billingData['email'])) {
                    $this->billingData['email'] = $user->email ?? '';
                }
                // Don't reset other fields - keep prefilled data from previous order

                Log::info('User payer - preserved prefilled billing data', [
                    'user_id' => $user->id,
                    'has_prefilled_address' => ! empty($this->billingData['street']),
                ]);
            }
        }
    }

    /**
     * Create order and immediately proceed to payment (single-step process)
     */
    public function createOrder(): void
    {
        // Check if this is an invoice payment BEFORE validation
        if ($this->preselectedPaymentMethod === 'invoice') {
            $this->selectedPaymentMethod = 'invoice';
            Log::info('Invoice payment detected before validation, setting selectedPaymentMethod to invoice');
        }

        Log::info('CheckoutWizard createOrder - validation data', [
            'payerType' => $this->payerType,
            'payerId' => $this->payerId,
            'selectedPaymentMethod' => $this->selectedPaymentMethod,
            'preselectedPaymentMethod' => $this->preselectedPaymentMethod,
        ]);

        try {
            // State is required for US, CA, AU
            $stateRequired = in_array($this->country, ['US', 'CA', 'AU']);

            $this->validate([
                'payerType' => 'required|in:user,organization',
                'payerId' => 'required|integer',
                'billingData.email' => 'required|email',
                'billingData.full_name' => $this->buyerType === 'individual' ? 'required|string' : 'nullable',
                'billingData.company_name' => $this->buyerType === 'company' ? 'required|string' : 'nullable',
                'billingData.street' => 'required|string',
                'billingData.postal_code' => 'required|string',
                'billingData.city' => 'required|string',
                'billingData.state' => $stateRequired ? 'required|string' : 'nullable',
                'country' => 'required|string|size:2',
                'selectedPaymentMethod' => 'nullable|string',
            ], [
                'billingData.email.required' => __('checkout.validation.email_required'),
                'billingData.email.email' => __('checkout.validation.email_invalid'),
                'billingData.full_name.required' => __('checkout.validation.full_name_required'),
                'billingData.company_name.required' => __('checkout.validation.company_name_required'),
                'billingData.street.required' => __('checkout.validation.street_required'),
                'billingData.postal_code.required' => __('checkout.validation.postal_code_required'),
                'billingData.city.required' => __('checkout.validation.city_required'),
                'billingData.state.required' => __('checkout.validation.state_required'),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('CheckoutWizard validation failed', [
                'errors' => $e->errors(),
                'payerType' => $this->payerType,
                'payerId' => $this->payerId,
            ]);
            throw $e;
        }

        try {
            Log::info('CheckoutWizard createOrder - debug', [
                'preselected_payment_method' => $this->preselectedPaymentMethod,
                'selected_payment_method' => $this->selectedPaymentMethod,
                'payer_type' => $this->payerType,
                'payer_id' => $this->payerId,
            ]);

            // Log payment initiated event for analytics (after validation passed)
            AnalyticsService::log('checkout_payment_initiated', [
                'license_id' => $this->licenseId,
                'license_slug' => $this->licenseData['slug'] ?? null,
                'license_tier' => $this->licenseData['tier'] ?? null,
                'payment_method' => $this->selectedPaymentMethod ?? $this->preselectedPaymentMethod ?? 'online',
                'payer_type' => $this->payerType,
                'payer_id' => $this->payerId,
                'is_trusted' => $this->isTrustedOrganization,
                'country' => $this->country,
                'currency' => $this->currency,
                'gross_amount' => $this->pricingData['gross_amount'] ?? null,
                'buyer_type' => $this->buyerType,
            ]);

            // Check if this is an invoice payment (from pricing wizard)
            if ($this->preselectedPaymentMethod === 'invoice') {
                // For invoice payments, handle directly in Livewire
                $this->selectedPaymentMethod = 'invoice';
                Log::info('Invoice payment detected, handling directly');
                $this->handleInvoicePaymentDirect();

                return;
            }

            // For regular payments, use the API call
            $this->callStartPaymentApi();

        } catch (\Exception $e) {
            Log::error('Payment start failed in checkout wizard', [
                'error' => $e->getMessage(),
                'license_id' => $this->licenseId,
                'payer_type' => $this->payerType,
            ]);

            $this->addError('checkout', 'Failed to start payment. Please try again.');
        }
    }

    /**
     * Process payment with Mollie
     */
    public function processPayment()
    {
        if (! $this->orderId) {
            $this->addError('payment', 'Please create order first');

            return;
        }

        try {
            $response = $this->callCreatePaymentApi();

            if ($response['success']) {
                // Redirect to Mollie checkout
                return redirect($response['checkout_url']);
            } else {
                $this->addError('payment', $response['error'] ?? 'Payment creation failed');
            }

        } catch (\Exception $e) {
            Log::error('Payment creation failed in checkout wizard', [
                'error' => $e->getMessage(),
                'order_id' => $this->orderId,
            ]);

            $this->addError('payment', 'Failed to create payment. Please try again.');
        }
    }

    /**
     * Handle Mollie payment directly in Livewire (avoids redirect interception issues)
     */
    private function callStartPaymentApi(): void
    {
        Log::info('CallStartPaymentApi - starting direct Mollie payment flow', [
            'license_id' => $this->licenseId,
            'payer_type' => $this->payerType,
            'payer_id' => $this->payerId,
            'payment_method' => $this->selectedPaymentMethod,
        ]);

        try {
            // Authorize organization payments
            if (! $this->authorizeOrganizationPayment()) {
                return;
            }

            $license = License::find($this->licenseId);
            if (! $license) {
                $this->addError('checkout', __('checkout.errors.license_not_found'));

                return;
            }

            // Calculate billing amount
            $pricing = $this->pricingCalculator->calculateBillingAmount(
                $license,
                $this->country,
                $this->billingData['vat_id'] ?? null,
                $this->buyerType === 'company'
            );

            // Include country in billing snapshot for Mollie address formatting
            $billingSnapshot = array_merge($this->billingData, ['country' => $this->country]);

            // Resolve provider voor deze license — voorheen hardcoded Mollie, nu
            // routeert dit naar Stripe als License::payment_provider='stripe' is.
            $provider = $this->providers->for($license);

            // Create order and payment within transaction
            $order = \Illuminate\Support\Facades\DB::transaction(function () use ($license, $pricing, $billingSnapshot, $provider) {
                $order = Order::create([
                    'payer_type' => $this->payerType,
                    'payer_id' => $this->payerId,
                    'license_id' => $license->id,
                    'type' => $license->tier === 'premium' ? 'subscription' : 'onetime',
                    'currency' => $pricing['currency'],
                    'net_amount' => $pricing['net_amount'],
                    'tax_amount' => $pricing['tax_amount'],
                    'gross_amount' => $pricing['gross_amount'],
                    'country' => $this->country,
                    'vat_id' => $this->billingData['vat_id'] ?? null,
                    'status' => OrderStatus::Initiated,
                    'payment_provider' => $provider->name(),
                    'billing_snapshot' => $billingSnapshot,
                    'meta' => [
                        'license_code' => $license->code,
                        'credits_amount' => $license->credits,
                        'pricing_calculation' => $pricing,
                        'created_at' => now()->toISOString(),
                    ],
                ]);

                Log::info('Order created via provider', [
                    'order_id' => $order->id,
                    'provider' => $provider->name(),
                ]);

                return $order;
            });

            // Create payment via provider — Mollie OF Stripe afhankelijk van License config
            $result = $order->type === 'subscription'
                ? $provider->createSubscriptionCheckout($order, $order->billing_snapshot, $this->selectedPaymentMethod)
                : $provider->createCheckout($order, $order->billing_snapshot, $this->selectedPaymentMethod);

            if (! empty($result['success']) || ! empty($result['checkout_url'])) {
                $checkoutUrl = $result['checkout_url'] ?? null;

                if (! $checkoutUrl) {
                    $order->update(['status' => OrderStatus::Failed]);
                    Log::error('Provider checkout missing URL', [
                        'provider' => $provider->name(),
                        'order_id' => $order->id,
                    ]);
                    $this->addError('checkout', __('checkout.errors.payment_failed'));

                    return;
                }

                // Update order met provider IDs + Mollie backward-compat zodat
                // bestaande webhook handlers blijven werken.
                $updateData = [
                    'provider_payment_id' => $result['provider_payment_id'] ?? null,
                    'provider_customer_id' => $result['provider_customer_id'] ?? null,
                    'meta' => array_merge($order->meta ?? [], [
                        'checkout_url' => $checkoutUrl,
                        'payment_created_at' => now()->toISOString(),
                    ]),
                ];

                if ($provider->name() === 'mollie') {
                    $updateData['mollie_payment_id'] = $result['provider_payment_id'] ?? null;
                    if (! empty($result['provider_customer_id'])) {
                        $updateData['mollie_customer_id'] = $result['provider_customer_id'];
                    }
                }

                $order->update($updateData);

                Log::info('Redirecting to provider checkout', [
                    'provider' => $provider->name(),
                    'checkout_url' => $checkoutUrl,
                ]);
                $this->redirect($checkoutUrl);

            } else {
                $order->update(['status' => OrderStatus::Failed]);
                Log::error('Provider checkout creation failed', [
                    'provider' => $provider->name(),
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
                $this->addError('checkout', $result['error'] ?? __('checkout.errors.payment_failed'));
            }

        } catch (\Exception $e) {
            Log::error('Payment start failed in checkout wizard', [
                'error' => $e->getMessage(),
                'license_id' => $this->licenseId,
            ]);
            $this->addError('checkout', __('checkout.errors.payment_failed'));
        }
    }

    /**
     * Authorize organization payment (admin check)
     */
    private function authorizeOrganizationPayment(): bool
    {
        if ($this->payerType !== 'organization') {
            return true;
        }

        $organization = Organization::find($this->payerId);
        if (! $organization) {
            $this->addError('checkout', __('checkout.errors.organization_not_found'));

            return false;
        }

        $user = auth()->user();
        $membership = $organization->users()->where('users.id', $user->id)->first();
        if (! $membership || $membership->pivot->role !== OrganizationRole::Owner) {
            $this->addError('checkout', __('checkout.errors.admin_required'));

            return false;
        }

        return true;
    }

    /**
     * Handle invoice payment directly in Livewire
     */
    private function handleInvoicePaymentDirect(): void
    {
        try {
            Log::info('HandleInvoicePaymentDirect - creating order and license', [
                'license_id' => $this->licenseId,
                'payer_type' => $this->payerType,
                'payer_id' => $this->payerId,
            ]);

            // First create the order
            $license = License::find($this->licenseId);

            // Use calculateBillingAmount (includes billing cycle multiplier)
            $pricing = $this->pricingCalculator->calculateBillingAmount(
                $license,
                $this->country,
                $this->billingData['vat_id'] ?? null,
                $this->buyerType === 'company'
            );

            // Create order (UUID will be auto-generated)
            $order = Order::create([
                'payer_type' => $this->payerType,
                'payer_id' => $this->payerId,
                'license_id' => $this->licenseId,
                'type' => $license->tier === 'premium' ? 'subscription' : 'onetime',
                'currency' => $pricing['currency'],
                'net_amount' => $pricing['net_amount'],
                'tax_amount' => $pricing['tax_amount'],
                'gross_amount' => $pricing['gross_amount'],
                'country' => $this->country,
                'vat_id' => $this->billingData['vat_id'] ?? null,
                'status' => OrderStatus::InvoiceRequested,
                'billing_snapshot' => $this->billingData,
                'meta' => [
                    'license_code' => $license->code,
                    'credits_amount' => $license->credits,
                    'pricing_calculation' => $pricing,
                    'payment_provider' => 'invoice',
                    'created_at' => now()->toISOString(),
                ],
            ]);

            Log::info('Order created successfully', ['order_id' => $order->id]);

            // Create organization license with invoice billing
            $organizationLicense = OrganizationLicense::createInvoiceLicense([
                'organization_id' => $this->payerId,
                'license_id' => $this->licenseId,
                'source' => 'checkout',
                'external_ref' => $order->id,
                'is_current' => true,
            ]);

            Log::info('Organization license created successfully', [
                'organization_license_id' => $organizationLicense->id,
                'invoice_number' => $organizationLicense->invoice_number,
            ]);

            // Check if organization is trusted - auto-approve if so
            $organization = Organization::find($this->payerId);
            $isTrusted = $organization && $organization->is_trusted;

            if ($isTrusted) {
                Log::info('Trusted organization detected - activating license (invoice still pending payment)', [
                    'organization_id' => $organization->id,
                    'organization_name' => $organization->name,
                ]);

                // Trusted org: activate license so they get credits immediately
                // But do NOT mark as paid - invoice payment is still pending!
                $organizationLicense->activate();
                // Note: payment_status remains 'unpaid', paid_at remains null

                $order->update([
                    'status' => OrderStatus::InvoiceRequested, // Keep same status - invoice still needs to be paid
                    'meta' => array_merge($order->meta ?? [], [
                        'invoice_license_id' => $organizationLicense->id,
                        'invoice_number' => $organizationLicense->invoice_number,
                        'invoice_due_date' => $organizationLicense->invoice_due_date,
                        'auto_approved' => true,
                        'auto_approved_at' => now()->toISOString(),
                        'trusted_organization' => true, // This flag differentiates trusted from non-trusted
                    ]),
                ]);

                // Add credits to organization (trusted gets credits before payment)
                $fulfillmentService = app(PaymentFulfillmentService::class);
                $fulfillmentService->fulfillOrder($order);

                Log::info('Trusted organization license activated and credits added (invoice pending payment)');

                // Store session data for activation page (success)
                session([
                    'success' => 'License activated successfully! Invoice has been generated.',
                    'order_id' => $order->id,
                ]);

                // Redirect to activation page with success message
                $this->redirect(route('activation', ['order' => $order->id]));

            } else {
                // Normal flow: pending approval
                $order->update([
                    'meta' => array_merge($order->meta ?? [], [
                        'invoice_license_id' => $organizationLicense->id,
                        'invoice_number' => $organizationLicense->invoice_number,
                        'invoice_due_date' => $organizationLicense->invoice_due_date,
                        'pending_created_at' => now()->toISOString(),
                    ]),
                ]);

                Log::info('Invoice payment processed successfully, redirecting to activation');

                // Store session data for activation page
                session([
                    'invoice_pending' => true,
                    'invoice_number' => $organizationLicense->invoice_number,
                ]);

                // Redirect to activation page with pending message
                $this->redirect(route('activation', ['order' => $order->id]));
            }

        } catch (\Exception $e) {
            Log::error('Invoice payment processing failed', [
                'error' => $e->getMessage(),
                'license_id' => $this->licenseId,
                'payer_type' => $this->payerType,
                'payer_id' => $this->payerId,
            ]);

            $this->addError('checkout', __('checkout.errors.payment_failed'));
        }
    }

    /**
     * Determine Mollie locale from country
     */
    private function determineLocale(): string
    {
        return config("services.mollie.locales.{$this->country}", config('services.mollie.default_locale', 'en_US'));
    }

    /**
     * Format amount for display
     */
    public function formatAmount(float $amount): string
    {
        return $this->pricingCalculator->formatAmount($amount, $this->currency);
    }

    /**
     * Get VAT rate as percentage
     */
    public function getVatRate(): float
    {
        if (! $this->pricingData || $this->pricingData['net_amount'] <= 0) {
            return 0;
        }

        return ($this->pricingData['tax_amount'] / $this->pricingData['net_amount']) * 100;
    }

    /**
     * Get validity text for onetime licenses
     */
    public function getValidityText(?int $period): string
    {
        return License::formatValidityPeriod($period);
    }

    /**
     * Get VAT display information
     */
    public function getVatDisplayInfo(): array
    {
        if (! $this->pricingData) {
            return ['show_vat_note' => false, 'vat_note' => ''];
        }

        $vatNote = '';
        $showVatNote = false;

        if ($this->pricingData['vat_reverse_charge'] ?? false) {
            $vatNote = 'VAT 0% (reverse charge applies)';
            $showVatNote = true;
        } elseif (! ($this->pricingData['vat_applicable'] ?? false)) {
            $vatNote = 'No VAT applicable';
            $showVatNote = true;
        } elseif ($this->pricingData['vat_rate'] > 0) {
            $vatNote = sprintf('VAT (%s%%) included', number_format($this->pricingData['vat_rate'], 0));
            $showVatNote = true;
        }

        return [
            'show_vat_note' => $showVatNote,
            'vat_note' => $vatNote,
        ];
    }

    public function render()
    {
        return view('livewire.checkout-wizard');
    }
}
