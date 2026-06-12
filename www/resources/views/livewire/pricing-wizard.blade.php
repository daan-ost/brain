<div x-data="{
    authModal: false,
    verificationModal: false,
    invoiceModal: false,
    showAuthModal() {
        console.log('showAuthModal called');
        this.authModal = true;
    },
    showVerificationModal() {
        console.log('showVerificationModal called');
        this.verificationModal = true;
    },
    showInvoiceModal() {
        console.log('showInvoiceModal called');
        this.invoiceModal = true;
    }
}">
    <style>
    .pricing-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    @media (min-width: 640px) {
        .pricing-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (min-width: 1024px) {
        .pricing-grid {
            grid-template-columns: repeat(4, 1fr);
        }
    }

    /* Currency toggle styles */
    .currency-toggle-container {
        display: flex;
        justify-content: center;
        margin-bottom: 2rem;
    }

    .currency-toggle {
        display: inline-flex;
        background: #f3f4f6;
        border-radius: 0.5rem;
        padding: 0.25rem;
        gap: 0.25rem;
    }

    .currency-toggle a {
        padding: 0.5rem 1.5rem;
        border-radius: 0.375rem;
        font-weight: 500;
        font-size: 0.875rem;
        transition: all 0.2s;
        text-decoration: none;
        color: #6b7280;
    }

    .currency-toggle a:hover {
        color: #374151;
        background: #e5e7eb;
    }

    .currency-toggle a.active {
        background: white;
        color: #1f2937;
        box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    }

    .currency-toggle-icon {
        display: inline-block;
        margin-right: 0.375rem;
        font-weight: 600;
    }
    </style>

    <!-- Currency Toggle (only show if no organization context) -->
    @if($showCurrencyToggle)
    <div class="currency-toggle-container">
        <div class="currency-toggle">
            <a href="{{ route('pricing', ['currency' => 'EUR']) }}"
                class="{{ $currency === 'EUR' ? 'active' : '' }}" title="Switch to Euro pricing">
                <span class="currency-toggle-icon">€</span>
                EUR
            </a>
            <a href="{{ route('pricing', ['currency' => 'USD']) }}"
                class="{{ $currency === 'USD' ? 'active' : '' }}" title="Switch to US Dollar pricing">
                <span class="currency-toggle-icon">$</span>
                USD
            </a>
        </div>
    </div>
    @endif

    <!-- Location & Currency Info (hidden for debugging) -->
    <div class="hidden bg-blue-50 border border-blue-200 rounded-lg p-4">
        <div class="flex items-center">
            <svg class="h-5 w-5 text-blue-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
            </svg>
            <div class="text-blue-700 text-sm">
                <div>
                    {{ __('pricing.location_detected') }}: <strong>{{ $userCountry }}</strong>
                    • {{ __('pricing.pricing_in') }}: <strong>{{ $currency }}</strong>
                </div>
                @if($this->pricingCalculator->isEuCountry($userCountry) && $userCountry !== 'NL')
                <div class="text-xs mt-1 text-blue-600">
                    {{ __('pricing.vat_notice', ['rate' => 21]) }}
                </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Pricing Cards -->
    <div class="pricing-grid">

        <!-- Free Plan Card -->
        <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6 relative flex flex-col h-full ">
            @if($this->hasCurrentLicense('free'))
            <div class="absolute top-4 right-4">
                <span
                    class="bg-green-100 text-green-800 text-xs font-medium px-2 py-1 rounded-full">{{ __('pricing.current') }}</span>
            </div>
            @endif

            <div class="text-center  flex flex-col">
                <h3 class="text-lg font-semibold text-gray-900 mb-3">{{ __('pricing.free') }}</h3>
                <div class="mb-4 min-h-[80px] flex flex-col justify-center">
                    <span class="text-3xl font-bold text-gray-900">
                        @if($currency === 'EUR')
                        €0
                        @else
                        $0
                        @endif
                    </span>
                    <span class="text-gray-500 text-sm">{{ __('pricing.per_month') }}</span>
                </div>

                @php
                    $freeLicense = \App\Models\License::where('tier', 'free')->where('currency', $currency)->first();
                    $freeRestrictions = $freeLicense ? $this->getLicenseRestrictions($freeLicense) : null;
                @endphp
                <ul class="text-sm text-gray-600 space-y-2 mb-6 text-left ">
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        {{ __('pricing.basic_conversion') }}
                    </li>
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        {{ __('pricing.conversions_per_day', ['count' => 15]) }}
                    </li>
                    @if($freeRestrictions && $freeRestrictions['max_files'])
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        {{ $freeRestrictions['max_files'] }} {{ __('pricing.files_per_conversion') }}
                    </li>
                    @endif
                    @if($freeRestrictions && $freeRestrictions['max_file_size_mb'])
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        {{ __('pricing.max_file_size', ['size' => $freeRestrictions['max_file_size_mb']]) }}
                    </li>
                    @endif
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        {{ __('pricing.standard_support') }}
                    </li>
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-red-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                            </path>
                        </svg>
                        {{ __('pricing.team_collaboration') }}
                    </li>
                </ul>

                <div class="mt-auto">
                    @auth
                        @if($this->hasCurrentLicense('free'))
                        <div class="w-full bg-green-100 text-green-700 py-3 px-4 rounded-lg text-sm font-medium">
                            {{ __('pricing.active_plan') }}
                        </div>
                        @else
                        <div class="w-full bg-green-100 text-green-700 py-3 px-4 rounded-lg text-sm font-medium">
                            {{ __('pricing.active_plan') }}
                        </div>
                        @endif
                    @else
                        <a href="{{ route('register') }}"
                            class="w-full block bg-indigo-600 text-white py-3 px-4 rounded-lg hover:bg-indigo-700 transition duration-200 text-sm font-medium text-center">
                            {{ __('pricing.signup') }}
                        </a>
                    @endauth
                </div>
            </div>
        </div>

        <!-- One-time Credits Card -->
        <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6 relative flex flex-col h-full ">
            @if($this->hasCurrentLicense('onetime'))
            @php $currentInfo = $this->getCurrentLicenseInfo('onetime'); @endphp
            <div class="absolute top-4 right-4">
                <span
                    class="bg-green-100 text-green-800 text-xs font-medium px-2 py-1 rounded-full">{{ __('pricing.current') }}</span>
            </div>
            @endif

            <div class="text-center  flex flex-col">
                <h3 class="text-lg font-semibold text-gray-900 mb-3">{{ __('pricing.onetime_credits') }}</h3>

                @if(!empty($licensesByTier['onetime']))
                <!-- Radio buttons for multiple packages -->
                <div class="mb-4 space-y-2">
                    @foreach(collect($licensesByTier['onetime'])->sortBy('ordering') as $index => $license)
                    @php
                    $vatInfo = $this->formatPriceWithVat($license);
                    $validityPeriod = $this->getValidityText($license->period ?? 180);
                    $restrictions = $this->getLicenseRestrictions($license);
                    $licenseData = json_encode([
                    'id' => $license->id,
                    'credits' => $license->credits,
                    'price' => $vatInfo['display_formatted'],
                    'validity' => __('pricing.credits_validity', ['period' => $validityPeriod]),
                    'vat_text' => $vatInfo['vat_text'],
                    'max_files' => $restrictions['max_files'],
                    'max_file_size_mb' => $restrictions['max_file_size_mb']
                    ]);
                    @endphp
                    <label
                        class="onetime-label flex items-center cursor-pointer p-3 border-2 rounded-lg transition border-gray-300"
                        data-license-id="{{ $license->id }}">
                        <input type="radio" name="onetime_package" value="{{ $license->id }}"
                            data-license='{{ $licenseData }}' onchange="selectOnetimePackage(this)"
                            {{ $index === 0 ? 'checked' : '' }}
                            class="w-4 h-4 mr-3 text-indigo-600 focus:ring-2 focus:ring-indigo-500">
                        <span class="text-sm font-medium text-gray-900">
                            {{ number_format($license->credits) }} credits
                        </span>
                    </label>
                    @endforeach
                </div>
                <input type="hidden" id="onetime-selected-id"
                    value="{{ collect($licensesByTier['onetime'])->sortBy('ordering')->first()->id ?? '' }}">

                @php
                $firstLicense = collect($licensesByTier['onetime'])->sortBy('ordering')->first();
                $firstVatInfo = $this->formatPriceWithVat($firstLicense);
                @endphp
                <div class="mb-4 min-h-[80px] flex flex-col justify-center">
                    <span id="onetime-price"
                        class="text-2xl font-bold text-gray-900">{{ $firstVatInfo['display_formatted'] }}</span>
                    @if(!empty($firstVatInfo['vat_text']))
                    <div id="onetime-vat-text" class="text-sm text-gray-600 mt-1">
                        {{ $firstVatInfo['vat_text'] }}
                    </div>
                    @else
                    <div id="onetime-vat-text" class="text-sm text-gray-600 mt-1" style="display: none;"></div>
                    @endif
                </div>

                <ul class="text-sm text-gray-600 space-y-2 mb-6 text-left ">
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        <span id="onetime-credits">{{ number_format($firstLicense->credits) }} {{ __('pricing.credits_in_total') }}</span>
                    </li>
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        <span id="onetime-validity">
                            @php
                                $validityPeriodText = $this->getValidityText($firstLicense->period ?? 180);
                            @endphp
                            {{ __('pricing.credits_validity', ['period' => $validityPeriodText]) }}
                        </span>
                    </li>
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        {{ __('pricing.unlimited_conversions') }}
                    </li>
                    @php
                        $firstRestrictions = $this->getLicenseRestrictions($firstLicense);
                    @endphp
                    @if($firstRestrictions['max_files'])
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        <span id="onetime-max-files">{{ $firstRestrictions['max_files'] }} {{ __('pricing.files_per_conversion') }}</span>
                    </li>
                    @endif
                    @if($firstRestrictions['max_file_size_mb'])
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        <span id="onetime-max-file-size">{{ __('pricing.max_file_size', ['size' => $firstRestrictions['max_file_size_mb']]) }}</span>
                    </li>
                    @endif
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        {{ __('pricing.team_collaboration') }}
                    </li>
                </ul>

                <div class="mt-auto">
                    @php $invoiceCheck = $this->canPayByInvoice(); @endphp

                    <div class="space-y-2">
                        @auth
                        @if(auth()->user()->hasVerifiedEmail())
                        <button onclick="payOnline('onetime')"
                            class="w-full bg-indigo-600 text-white py-2 px-4 rounded-lg hover:bg-indigo-700 transition duration-200 text-sm font-medium">
                            {{ __('pricing.pay_online') }}
                        </button>
                        @else
                        <button @click="console.log('Unverified user clicked Pay Online'); showVerificationModal()"
                            class="w-full bg-indigo-600 text-white py-2 px-4 rounded-lg hover:bg-indigo-700 transition duration-200 text-sm font-medium">
                            {{ __('pricing.pay_online') }}
                        </button>
                        @endif
                        @else
                        <button @click="console.log('Guest user clicked Pay Online'); showAuthModal()"
                            class="w-full bg-indigo-600 text-white py-2 px-4 rounded-lg hover:bg-indigo-700 transition duration-200 text-sm font-medium">
                            {{ __('pricing.pay_online') }}
                        </button>
                        @endauth

                        @auth
                        @if($invoiceCheck['can_pay'])
                        <button onclick="payByInvoice('onetime')"
                            class="w-full bg-white border border-indigo-600 text-indigo-600 py-2 px-4 rounded-lg hover:bg-indigo-50 transition duration-200 text-sm font-medium">
                            {{ __('pricing.pay_by_invoice') }}
                        </button>
                        @else
                        <button @click="showInvoiceModal()"
                            class="w-full bg-gray-100 border border-gray-300 text-gray-400 py-2 px-4 rounded-lg cursor-pointer hover:bg-gray-200 text-sm font-medium">
                            {{ __('pricing.pay_by_invoice') }}
                        </button>
                        @endif
                        @else
                        <button @click="console.log('Guest user clicked Pay by Invoice'); showInvoiceModal()"
                            class="w-full bg-gray-100 border border-gray-300 text-gray-700 py-2 px-4 rounded-lg cursor-pointer hover:bg-gray-200 text-sm font-medium">
                            {{ __('pricing.pay_by_invoice') }}
                        </button>
                        @endauth
                    </div>
                </div>
                @else
                <div class="text-center text-gray-500 py-8 flex items-center justify-center">
                    <p>No one-time packages available for {{ $currency }}</p>
                </div>
                @endif
            </div>
        </div>

        <!-- Premium Annual Card -->
        <div class="bg-white border-2 border-indigo-200 rounded-lg shadow-sm p-6 relative flex flex-col h-full ">
            <!-- Popular badge -->
            <div class="absolute -top-3 left-1/2 transform -translate-x-1/2">
                <span class="bg-indigo-600 text-white text-xs font-medium px-3 py-1 rounded-full">Popular</span>
            </div>

            @if($this->hasCurrentLicense('premium'))
            @php $currentInfo = $this->getCurrentLicenseInfo('premium'); @endphp
            <div class="absolute top-4 right-4">
                <span class="bg-green-100 text-green-800 text-xs font-medium px-2 py-1 rounded-full">Current</span>
            </div>
            @endif

            <div class="text-center  flex flex-col">
                <h3 class="text-lg font-semibold text-gray-900 mb-3">{{ __('pricing.premium') }}</h3>

                @if(!empty($licensesByTier['premium']))
                <!-- Radio buttons for multiple premium tiers -->
                <div class="mb-4 space-y-2">
                    @foreach(collect($licensesByTier['premium'])->sortBy('ordering') as $license)
                    @php
                    $vatInfo = $this->formatPriceWithVat($license);
                    $resetInterval = $license->credit_reset_interval ?? 'none';
                    $intervalText = $resetInterval === 'monthly' ? __('pricing.per_month') : __('pricing.per_year');
                    @endphp
                    <label
                        class="flex items-center cursor-pointer p-3 border rounded-lg hover:bg-indigo-50 transition
                                {{ $selectedPremium === $license->id ? 'border-indigo-500 bg-indigo-100' : 'border-gray-300' }}">
                        <input type="radio" wire:model.live="selectedPremium" value="{{ $license->id }}"
                            class="mr-3 text-indigo-600 focus:ring-indigo-500">
                        <span class="text-sm font-medium text-gray-900">
                            {{ number_format($license->credits) }} credits
                        </span>
                    </label>
                    @endforeach
                </div>

                @if($selectedPremium && isset($availableLicenses[$selectedPremium]))
                @php
                $selectedLicense = $availableLicenses[$selectedPremium];
                $license = \App\Models\License::find($selectedPremium);
                $vatInfo = $this->formatPriceWithVat($license);
                @endphp
                <div class="mb-4 min-h-[80px] flex flex-col justify-center">
                    @php
                    // amount is already per month
                    $monthlyFormatted = $vatInfo['display_formatted'];
                    $annualAmount = $vatInfo['display_amount'] * 12;
                    // Use same formatting as monthly price (ex VAT = 2 decimals, incl VAT = rounded)
                    $annualFormatted = $this->pricingCalculator->formatAmount($annualAmount,
                    $vatInfo['pricing']['currency'], $vatInfo['show_ex_vat']);
                    @endphp
                    <div>
                        <span class="text-3xl font-bold text-indigo-600">{{ $monthlyFormatted }}</span>
                        <span class="text-gray-500 text-sm">{{ __('pricing.per_month') }}@if(!empty($vatInfo['vat_text'])) {{ $vatInfo['vat_text'] }}@endif</span>
                    </div>
                    <div class="text-sm text-gray-600 mt-1">
                        {{ $annualFormatted }} {{ __('pricing.billed_annually') }}
                    </div>
                </div>

                <ul class="text-sm text-gray-600 space-y-2 mb-6 text-left ">
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        @php
                        $resetInterval = $selectedLicense['credit_reset_interval'] ?? 'none';
                        $creditsText = number_format($selectedLicense['credits']) . ' credits';
                        if ($resetInterval === 'monthly') {
                        $creditsText .= ' (' . __('pricing.reset_per_month') . ')';
                        } elseif ($resetInterval === 'yearly') {
                        $creditsText .= ' (' . __('pricing.reset_per_year') . ')';
                        }
                        @endphp
                        {{ $creditsText }}
                    </li>
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        {{ __('pricing.unlimited_conversions') }}
                    </li>
                    @php
                        $premiumRestrictions = $this->getLicenseRestrictions($license);
                    @endphp
                    @if($premiumRestrictions['max_files'])
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        {{ $premiumRestrictions['max_files'] }} {{ __('pricing.files_per_conversion') }}
                    </li>
                    @endif
                    @if($premiumRestrictions['max_file_size_mb'])
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        {{ __('pricing.max_file_size', ['size' => $premiumRestrictions['max_file_size_mb']]) }}
                    </li>
                    @endif
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        {{ __('pricing.priority_support') }}
                    </li>
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        {{ __('pricing.team_collaboration') }}
                    </li>
                </ul>

                <div class="mt-auto">
                    @php $invoiceCheck = $this->canPayByInvoice(); @endphp

                    <div class="space-y-2">
                        @auth
                        @if(auth()->user()->hasVerifiedEmail())
                        <button wire:click="selectLicense({{ $selectedPremium }}, 'premium')"
                            class="w-full bg-indigo-600 text-white py-2 px-4 rounded-lg hover:bg-indigo-700 transition duration-200 text-sm font-medium">
                            {{ __('pricing.pay_online') }}
                        </button>
                        @else
                        <button @click="console.log('Unverified user clicked Pay Online'); showVerificationModal()"
                            class="w-full bg-indigo-600 text-white py-2 px-4 rounded-lg hover:bg-indigo-700 transition duration-200 text-sm font-medium">
                            {{ __('pricing.pay_online') }}
                        </button>
                        @endif
                        @else
                        <button @click="console.log('Guest user clicked Pay Online'); showAuthModal()"
                            class="w-full bg-indigo-600 text-white py-2 px-4 rounded-lg hover:bg-indigo-700 transition duration-200 text-sm font-medium">
                            {{ __('pricing.pay_online') }}
                        </button>
                        @endauth

                        @auth
                        @if($invoiceCheck['can_pay'])
                        <button wire:click="selectLicenseWithInvoice({{ $selectedPremium }}, 'premium')"
                            class="w-full bg-white border border-indigo-600 text-indigo-600 py-2 px-4 rounded-lg hover:bg-indigo-50 transition duration-200 text-sm font-medium">
                            {{ __('pricing.pay_by_invoice') }}
                        </button>
                        @else
                        <button @click="showInvoiceModal()"
                            class="w-full bg-gray-100 border border-gray-300 text-gray-400 py-2 px-4 rounded-lg cursor-pointer hover:bg-gray-200 text-sm font-medium">
                            {{ __('pricing.pay_by_invoice') }}
                        </button>
                        @endif
                        @else
                        <button @click="showInvoiceModal()"
                            class="w-full bg-gray-100 border border-gray-300 text-gray-700 py-2 px-4 rounded-lg cursor-pointer hover:bg-gray-200 text-sm font-medium">
                            {{ __('pricing.pay_by_invoice') }}
                        </button>
                        @endauth
                    </div>
                </div>
                @else
                <div class="mb-4 text-indigo-600">
                    <div class="text-2xl font-bold">Choose tier</div>
                    <div class="text-sm mt-1">Select from dropdown above</div>
                </div>

                <ul class="text-sm text-gray-600 space-y-2 mb-6 text-left ">
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        Annual credit allocation
                    </li>
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        Best value for heavy usage
                    </li>
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        Premium features
                    </li>
                </ul>

                <div class="mt-auto">
                    <button disabled
                        class="w-full bg-gray-300 text-gray-500 py-3 px-4 rounded-lg cursor-not-allowed text-sm font-medium">
                        Select tier above
                    </button>
                </div>
                @endif
                @else
                <div class="text-center text-gray-500 py-8  flex items-center justify-center">
                    <p>No premium tiers available for {{ $currency }}</p>
                </div>
                @endif
            </div>
        </div>

        <!-- Enterprise Card -->
        <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6 relative flex flex-col h-full ">
            <div class="text-center  flex flex-col">
                <h3 class="text-lg font-semibold text-gray-900 mb-3">{{ __('pricing.enterprise') }}</h3>
                <div class="mb-4 min-h-[80px] flex flex-col justify-center">
                    <span class="text-2xl font-bold text-gray-900">Custom</span>
                </div>

                <ul class="text-sm text-gray-600 space-y-2 mb-6 text-left ">
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        {{ __('pricing.unlimited_credits') }}
                    </li>
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        {{ __('pricing.custom_integrations') }}
                    </li>
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        {{ __('pricing.dedicated_support') }}
                    </li>
                    <li class="flex items-center">
                        <svg class="h-4 w-4 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        {{ __('pricing.sla_guarantees') }}
                    </li>
                </ul>

                <div class="mt-auto">
                    <button wire:click="contactSales"
                        class="w-full bg-gray-800 text-white py-3 px-4 rounded-lg hover:bg-gray-900 transition duration-200 text-sm font-medium">
                        {{ __('pricing.contact_sales') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Current License Status -->
    @if(auth()->check() && $currentLicenses)
    <div class="mt-16 mb-8 bg-gray-50 rounded-lg p-8 border border-gray-200 shadow-lg">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-medium text-gray-900">{{ __('pricing.active_licenses') }}</h3>
            <a href="{{ route('profile.plans') }}"
                class="inline-flex items-center px-4 py-2 border border-indigo-600 text-sm font-medium rounded-lg text-indigo-600 bg-white hover:bg-indigo-50 transition duration-200">
                <svg class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z">
                    </path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                {{ __('pricing.manage_plans') }}
            </a>
        </div>

        <div class="space-y-4">
            @php
            $isPrimary = true; // First license shown is primary
            @endphp

            @if(isset($currentLicenses['user']) && !empty($currentLicenses['user']))
            @foreach($currentLicenses['user'] as $tier => $license)
            @php
            $backgroundColor = $isPrimary ? 'bg-blue-50 border-blue-200' : 'bg-gray-50 border-gray-200';
            $iconColor = $isPrimary ? 'text-blue-600' : 'text-gray-500';
            $titleColor = $isPrimary ? 'text-blue-900' : 'text-gray-900';
            $statusColor = $isPrimary ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800';
            @endphp

            <div class="{{ $backgroundColor }} border rounded-lg p-4">
                <div class="flex items-start justify-between">
                    <div class="flex items-start">
                        <svg class="h-5 w-5 {{ $iconColor }} mt-0.5 mr-3" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z">
                            </path>
                        </svg>
                        <div>
                            <h4 class="text-sm font-semibold {{ $titleColor }}">
                                @if($isPrimary)
                                <span class="inline-flex items-center mr-2">
                                    <svg class="w-4 h-4 mr-1 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                                        <path
                                            d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                    </svg>
                                    {{ __('pricing.primary_license') }}
                                </span>
                                @endif
                                {{ __('pricing.individual_license') }}
                            </h4>
                            <p class="{{ $isPrimary ? 'text-blue-800' : 'text-gray-800' }} font-medium">
                                {{ $license['name'] }}
                            </p>

                            @if($license['ends_at'])
                            <p class="text-xs {{ $isPrimary ? 'text-blue-600' : 'text-gray-600' }} mt-1">
                                @if(\Carbon\Carbon::parse($license['ends_at'])->isFuture())
                                {{ __('pricing.valid_until') }}:
                                {{ \Carbon\Carbon::parse($license['ends_at'])->format('M j, Y') }}
                                @else
                                {{ __('pricing.renews') }}:
                                {{ \Carbon\Carbon::parse($license['ends_at'])->format('M j, Y') }}
                                @endif
                            </p>
                            @endif
                        </div>
                    </div>
                    <div class="text-right">
                        <span
                            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $statusColor }}">
                            {{ __('pricing.active') }}
                        </span>
                        @if(isset($license['credits']))
                        <div class="mt-2">
                            <span
                                class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-green-100 text-green-800">
                                {{ number_format($license['credits']) }} {{ __('pricing.credits') }}
                            </span>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
            @php $isPrimary = false; @endphp
            @endforeach
            @endif

            @if(isset($currentLicenses['organizations']) && !empty($currentLicenses['organizations']))
            @foreach($currentLicenses['organizations'] as $orgId => $orgLicenses)
            @php
            $org = collect($userOrganizations)->firstWhere('id', $orgId);
            $orgName = is_array($org) ? ($org['name'] ?? 'Organization') : 'Organization';
            @endphp

            @foreach($orgLicenses as $tier => $license)
            @php
            $backgroundColor = $isPrimary ? 'bg-blue-50 border-blue-200' : 'bg-gray-50 border-gray-200';
            $iconColor = $isPrimary ? 'text-blue-600' : 'text-gray-500';
            $titleColor = $isPrimary ? 'text-blue-900' : 'text-gray-900';
            $statusColor = $isPrimary ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800';
            @endphp

            <div class="{{ $backgroundColor }} border rounded-lg p-4">
                <div class="flex items-start justify-between">
                    <div class="flex items-start">
                        <svg class="h-5 w-5 {{ $iconColor }} mt-0.5 mr-3" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z">
                            </path>
                        </svg>
                        <div>
                            <h4 class="text-sm font-semibold {{ $titleColor }}">
                                @if($isPrimary)
                                <span class="inline-flex items-center mr-2">
                                    <svg class="w-4 h-4 mr-1 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                                        <path
                                            d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                    </svg>
                                    {{ __('pricing.primary_license') }}
                                </span>
                                @endif
                                {{ __('pricing.organizational_license') }}
                            </h4>
                            <p class="{{ $isPrimary ? 'text-blue-800' : 'text-gray-800' }} font-medium">
                                {{ $license['name'] }}
                            </p>
                            <p class="text-xs {{ $isPrimary ? 'text-blue-600' : 'text-gray-600' }} mt-1">
                                {{ __('pricing.organization') }}: {{ $orgName }}
                            </p>

                            @if($license['ends_at'])
                            <p class="text-xs {{ $isPrimary ? 'text-blue-600' : 'text-gray-600' }}">
                                @if(\Carbon\Carbon::parse($license['ends_at'])->isFuture())
                                {{ __('pricing.valid_until') }}:
                                {{ \Carbon\Carbon::parse($license['ends_at'])->format('M j, Y') }}
                                @else
                                {{ __('pricing.renews') }}:
                                {{ \Carbon\Carbon::parse($license['ends_at'])->format('M j, Y') }}
                                @endif
                            </p>
                            @endif
                        </div>
                    </div>
                    <div class="text-right">
                        <span
                            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $statusColor }}">
                            {{ __('pricing.active') }}
                        </span>
                        @if(isset($license['credits']))
                        <div class="mt-2">
                            <span
                                class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-green-100 text-green-800">
                                {{ number_format($license['credits']) }} {{ __('pricing.credits') }}
                            </span>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
            @php $isPrimary = false; @endphp
            @endforeach
            @endforeach
            @endif
        </div>
    </div>
    @endif

    <!-- FAQ Section -->
    @php
    // FAQ data - can be populated from database or config
    $pricingFaqs = collect([]);
    @endphp

    @if($pricingFaqs->isNotEmpty())
    <section class="py-20 bg-white">
        <div class="container mx-auto px-4">
            <div class="text-center mb-16">
                <h2 class="text-4xl md:text-5xl font-bold mb-6 text-gray-900 font-display">
                    {{ __('pricing.faq_title') }}
                </h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto">
                    {{ __('pricing.faq_subtitle') }}
                </p>
            </div>

            <div class="max-w-4xl mx-auto space-y-6">
                @foreach($pricingFaqs as $faq)
                <div class="bg-white rounded-2xl border-2 border-gray-100 shadow-sm hover:shadow-md transition-shadow">
                    <details class="group">
                        <summary class="px-6 py-6 cursor-pointer list-none">
                            <div class="flex items-center justify-between">
                                <span class="text-lg font-semibold text-gray-900">{{ $faq->question }}</span>
                                <svg class="w-5 h-5 text-gray-500 group-open:rotate-180 transition-transform"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>
                        </summary>
                        <div class="px-6 pb-6">
                            <div class="text-gray-600 leading-relaxed [&_a]:text-blue-600 [&_a]:underline [&_a:hover]:text-blue-800">
                                {!! \Illuminate\Support\Str::markdown($faq->answer) !!}
                            </div>
                        </div>
                    </details>
                </div>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    <!-- Contact Sales Modal -->
    <div class="mt-8" x-data="{ showModal: false }" @show-contact-modal.window="showModal = true">
        <div x-show="showModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" @click.away="showModal = false">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Contact Sales</h3>
                    <p class="text-gray-600 mb-6">
                        For enterprise solutions, please contact our sales team at:
                    </p>
                    <div class="space-y-2 mb-6">
                        <p class="text-sm"><strong>Email:</strong> {{ config('mail.from.address') }}</p>
                        <p class="text-sm"><strong>Phone:</strong> +31 20 123 4567</p>
                    </div>
                    <button @click="showModal = false"
                        class="w-full bg-indigo-600 text-white py-2 px-4 rounded-lg hover:bg-indigo-700 transition duration-200">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Authentication Required Modal -->
    <div x-cloak>
        <div x-show="authModal" x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0" class="fixed inset-0 z-50 overflow-y-auto backdrop-blur-sm bg-black/30"
            @click.self="authModal = false" style="display: none;">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6"
                    x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0 transform scale-95"
                    x-transition:enter-end="opacity-100 transform scale-100">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center">
                            <svg class="h-6 w-6 text-indigo-600 mr-2" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z">
                                </path>
                            </svg>
                            <h3 class="text-lg font-semibold text-gray-900">{{ __('pricing.auth_required_title') }}</h3>
                        </div>
                        <button @click="authModal = false" class="text-gray-400 hover:text-gray-600">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>

                    <p class="text-gray-600 mb-6">
                        {{ __('pricing.auth_required_message') }}
                    </p>

                    <div class="flex gap-3">
                        <a href="{{ route('login') }}"
                            class="flex-1 bg-indigo-600 text-white py-2 px-4 rounded-lg hover:bg-indigo-700 transition duration-200 text-center text-sm font-medium">
                            {{ __('pricing.login') }}
                        </a>
                        <a href="{{ route('register') }}"
                            class="flex-1 bg-white border border-indigo-600 text-indigo-600 py-2 px-4 rounded-lg hover:bg-indigo-50 transition duration-200 text-center text-sm font-medium">
                            {{ __('pricing.create_account') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Email Verification Required Modal -->
    <div x-cloak>
        <div x-show="verificationModal" x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0" class="fixed inset-0 z-50 overflow-y-auto backdrop-blur-sm bg-black/30"
            @click.self="verificationModal = false" style="display: none;">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6"
                    x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0 transform scale-95"
                    x-transition:enter-end="opacity-100 transform scale-100">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center">
                            <svg class="h-6 w-6 text-amber-500 mr-2" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                                </path>
                            </svg>
                            <h3 class="text-lg font-semibold text-gray-900">
                                {{ __('pricing.verification_required_title') }}</h3>
                        </div>
                        <button @click="verificationModal = false" class="text-gray-400 hover:text-gray-600">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>

                    <p class="text-gray-600 mb-4">
                        {{ __('pricing.verification_required_message') }}
                    </p>

                    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
                        <p class="text-sm text-gray-700">
                            {{ __('pricing.verification_instructions') }}
                        </p>
                    </div>

                    <div class="flex gap-3">
                        <form method="POST" action="{{ route('verification.send') }}" class="flex-1">
                            @csrf
                            <button type="submit"
                                class="w-full bg-indigo-600 text-white py-2 px-4 rounded-lg hover:bg-indigo-700 transition duration-200 text-sm font-medium">
                                {{ __('pricing.resend_verification') }}
                            </button>
                        </form>
                        <button @click="verificationModal = false"
                            class="flex-1 bg-gray-100 text-gray-700 py-2 px-4 rounded-lg hover:bg-gray-200 transition duration-200 text-sm font-medium">
                            {{ __('pricing.close') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Invoice Requirements Modal -->
    <div x-cloak>
        <div x-show="invoiceModal" x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0" class="fixed inset-0 z-50 overflow-y-auto backdrop-blur-sm bg-black/30"
            @click.self="invoiceModal = false" style="display: none;">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6"
                    x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0 transform scale-95"
                    x-transition:enter-end="opacity-100 transform scale-100">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center">
                            <svg class="h-6 w-6 text-indigo-600 mr-2" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <h3 class="text-lg font-semibold text-gray-900">
                                {{ __('pricing.invoice_requirements_title') }}</h3>
                        </div>
                        <button @click="invoiceModal = false" class="text-gray-400 hover:text-gray-600">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>

                    <p class="text-gray-600 mb-4">
                        {{ __('pricing.invoice_requirements_intro') }}
                    </p>

                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                        <ol class="space-y-3 text-sm text-gray-700">
                            <li class="flex items-start">
                                <span
                                    class="flex-shrink-0 w-6 h-6 flex items-center justify-center bg-indigo-600 text-white rounded-full text-xs font-bold mr-3">1</span>
                                <span>{{ __('pricing.invoice_requirement_1') }}</span>
                            </li>
                            <li class="flex items-start">
                                <span
                                    class="flex-shrink-0 w-6 h-6 flex items-center justify-center bg-indigo-600 text-white rounded-full text-xs font-bold mr-3">2</span>
                                <span>{{ __('pricing.invoice_requirement_2') }}</span>
                            </li>
                            <li class="flex items-start">
                                <span
                                    class="flex-shrink-0 w-6 h-6 flex items-center justify-center bg-indigo-600 text-white rounded-full text-xs font-bold mr-3">3</span>
                                <span>{{ __('pricing.invoice_requirement_3') }}</span>
                            </li>
                        </ol>
                    </div>

                    @auth
                    <div class="flex gap-3">
                        <a href="{{ route('profile.organization') }}"
                            class="flex-1 bg-indigo-600 text-white py-2 px-4 rounded-lg hover:bg-indigo-700 transition duration-200 text-center text-sm font-medium">
                            {{ __('pricing.manage_organizations') }}
                        </a>
                        <button @click="invoiceModal = false"
                            class="flex-1 bg-gray-100 text-gray-700 py-2 px-4 rounded-lg hover:bg-gray-200 transition duration-200 text-sm font-medium">
                            {{ __('pricing.close') }}
                        </button>
                    </div>
                    @else
                    <div class="flex gap-3">
                        <a href="{{ route('register') }}"
                            class="flex-1 bg-indigo-600 text-white py-2 px-4 rounded-lg hover:bg-indigo-700 transition duration-200 text-center text-sm font-medium">
                            {{ __('pricing.create_account') }}
                        </a>
                        <a href="{{ route('login') }}"
                            class="flex-1 bg-white border border-indigo-600 text-indigo-600 py-2 px-4 rounded-lg hover:bg-indigo-50 transition duration-200 text-center text-sm font-medium">
                            {{ __('pricing.login') }}
                        </a>
                    </div>
                    @endauth
                </div>
            </div>
        </div>
    </div>

    <script>
    // Radio button handler for one-time packages
    function selectOnetimePackage(radio) {
        const data = JSON.parse(radio.getAttribute('data-license'));

        // Update card details
        document.getElementById('onetime-price').textContent = data.price;

        const vatTextElement = document.getElementById('onetime-vat-text');
        if (data.vat_text && data.vat_text.trim() !== '') {
            vatTextElement.textContent = data.vat_text;
            vatTextElement.style.display = 'block';
        } else {
            vatTextElement.textContent = '';
            vatTextElement.style.display = 'none';
        }

        document.getElementById('onetime-validity').textContent = data.validity;

        // Update credits with "in total" translation
        const creditsElement = document.getElementById('onetime-credits');
        const creditsOriginalText = creditsElement.textContent;
        const creditsTranslationPart = creditsOriginalText.replace(/^[\d,.\s]+/, ''); // Remove number from start
        creditsElement.textContent = data.credits.toLocaleString() + ' ' + creditsTranslationPart;

        document.getElementById('onetime-selected-id').value = data.id;

        // Update restrictions
        const maxFilesElement = document.getElementById('onetime-max-files');
        const maxFileSizeElement = document.getElementById('onetime-max-file-size');

        if (maxFilesElement && data.max_files) {
            // Get original translation text (everything after the first number and space)
            const originalText = maxFilesElement.textContent;
            const translationPart = originalText.replace(/^\d+\s+/, ''); // Remove "5 " from "5 files per conversion"
            maxFilesElement.textContent = data.max_files + ' ' + translationPart;
        }

        if (maxFileSizeElement && data.max_file_size_mb) {
            // Get original translation text and replace the number placeholder
            const originalText = maxFileSizeElement.textContent;
            const translationPart = originalText.replace(/\d+/, data.max_file_size_mb);
            maxFileSizeElement.textContent = translationPart;
        }

        // Update styling
        document.querySelectorAll('.onetime-label').forEach(label => {
            label.classList.remove('border-indigo-500', 'bg-indigo-50');
            label.classList.add('border-gray-300');
        });
        radio.closest('.onetime-label').classList.remove('border-gray-300');
        radio.closest('.onetime-label').classList.add('border-indigo-500', 'bg-indigo-50');
    }

    // Initialize first radio button styling on page load
    document.addEventListener('DOMContentLoaded', function() {
        const firstRadio = document.querySelector('input[name="onetime_package"]:checked');
        if (firstRadio) {
            firstRadio.closest('.onetime-label').classList.remove('border-gray-300');
            firstRadio.closest('.onetime-label').classList.add('border-indigo-500', 'bg-indigo-50');
        }
    });

    // Payment button handlers
    function payOnline(tier) {
        const licenseId = document.getElementById(tier + '-selected-id').value;
        window.location.href = '/checkout?license=' + licenseId + '&tier=' + tier;
    }

    function payByInvoice(tier) {
        const licenseId = document.getElementById(tier + '-selected-id').value;
        window.location.href = '/checkout?license=' + licenseId + '&tier=' + tier + '&payment_method=invoice';
    }
    </script>
</div>