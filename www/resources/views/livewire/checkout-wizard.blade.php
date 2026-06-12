<div>
    <!-- Progress Stepper -->
    <div class="mb-8">
        <div class="flex items-center justify-center">
            <div class="flex items-center">
                <!-- Step 1 - Completed -->
                <div class="flex items-center">
                    <div class="bg-green-600 text-white rounded-full h-10 w-10 flex items-center justify-center text-sm font-medium">
                        ✓
                    </div>
                    <a href="{{ route('pricing') }}" class="ml-2 text-green-600 font-medium hover:underline">{{ __('checkout.step_product_selection') }}</a>
                </div>

                <!-- Arrow -->
                <svg class="mx-4 h-5 w-5 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>

                <!-- Step 2 - Active -->
                <div class="flex items-center">
                    <div class="bg-indigo-600 text-white rounded-full h-10 w-10 flex items-center justify-center text-sm font-medium">
                        2
                    </div>
                    <span class="ml-2 text-indigo-600 font-medium">{{ __('checkout.step_secure_checkout') }}</span>
                </div>

                <!-- Arrow -->
                <svg class="mx-4 h-5 w-5 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>

                <!-- Step 3 - Inactive -->
                <div class="flex items-center">
                    <div class="bg-gray-200 text-gray-400 rounded-full h-10 w-10 flex items-center justify-center text-sm font-medium">
                        3
                    </div>
                    <span class="ml-2 text-gray-400 font-medium">{{ __('checkout.step_activation') }}</span>
                </div>
            </div>
        </div>
    </div>

    @if($licenseData)
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Left Column: Order Summary -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-lg shadow-sm border p-6 sticky top-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ __('checkout.order_summary') }}</h3>
                
                <div class="space-y-4 mb-6">
                    <div class="flex justify-between">
                        <span class="text-gray-600">{{ __('checkout.license_type') }}:</span>
                        <span class="font-medium">
                            @if($licenseData['tier'] === 'onetime')
                                {{ __('checkout.license_type_onetime') }}
                            @else
                                {{ __('checkout.license_type_recurring') }}
                            @endif
                        </span>
                    </div>

                    @if($licenseData['tier'] === 'onetime')
                        <div class="flex justify-between">
                            <span class="text-gray-600">{{ __('checkout.credits') }}:</span>
                            <span class="font-medium">{{ number_format($licenseData['credits']) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">{{ __('checkout.valid_for') }}:</span>
                            <span class="font-medium">
                                {{ $this->getValidityText($licenseData['period']) }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">{{ __('checkout.end_date') }}:</span>
                            <span class="font-medium">
                                {{ now()->addDays($licenseData['period'] ?? 180)->format('d-m-Y') }}
                            </span>
                        </div>
                    @else
                        <div class="flex justify-between">
                            <span class="text-gray-600">{{ __('checkout.credits') }}:</span>
                            <span class="font-medium">
                                @php
                                    $resetInterval = $licenseData['credit_reset_interval'] ?? 'none';
                                    $creditsText = number_format($licenseData['credits']);
                                    if ($resetInterval === 'monthly') {
                                        $creditsText .= ' (' . __('pricing.reset_per_month') . ')';
                                    } elseif ($resetInterval === 'yearly') {
                                        $creditsText .= ' (' . __('pricing.reset_per_year') . ')';
                                    }
                                @endphp
                                {{ $creditsText }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">{{ __('checkout.billing_cycle') }}:</span>
                            <span class="font-medium">
                                @if($licenseData['billing_cycle'] === 'yearly')
                                    {{ __('checkout.billed_yearly') }}
                                @elseif($licenseData['billing_cycle'] === 'monthly')
                                    {{ __('checkout.billed_monthly') }}
                                @else
                                    {{ ucfirst($licenseData['billing_cycle']) }}
                                @endif
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">{{ __('checkout.renewal_date') }}:</span>
                            <span class="font-medium">
                                @if($licenseData['billing_cycle'] === 'yearly')
                                    {{ now()->addYear()->format('d-m-Y') }}
                                @elseif($licenseData['billing_cycle'] === 'monthly')
                                    {{ now()->addMonth()->format('d-m-Y') }}
                                @else
                                    {{ now()->addYear()->format('d-m-Y') }}
                                @endif
                            </span>
                        </div>
                    @endif
                </div>

                @if($pricingData)
                    <div class="border-t pt-4 space-y-3">
                        <div class="flex justify-between">
                            <span class="text-gray-600">{{ __('checkout.subtotal_excl_vat') }}:</span>
                            <span class="font-medium">{{ $this->formatAmount($pricingData['net_amount']) }}</span>
                        </div>

                        @if($pricingData['tax_amount'] > 0)
                            <div class="flex justify-between">
                                <span class="text-gray-600">
                                    {{ __('checkout.vat', ['rate' => number_format($this->getVatRate(), 1)]) }}:
                                </span>
                                <span class="font-medium">{{ $this->formatAmount($pricingData['tax_amount']) }}</span>
                            </div>
                        @endif

                        <div class="flex justify-between text-lg font-semibold border-t pt-3">
                            <span>
                                @if($pricingData['tax_amount'] > 0)
                                    {{ __('checkout.total_incl_vat') }}
                                @else
                                    {{ __('checkout.total_excl_vat') }}
                                @endif
                            </span>
                            <span>{{ $this->formatAmount($pricingData['gross_amount']) }}</span>
                        </div>

                        @if($pricingData['vat_reverse_charge'] ?? false)
                            <div class="text-xs text-blue-600 bg-blue-50 p-2 rounded">
                                {{ __('checkout.vat_reverse_charge_note') }}
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        <!-- Right Column: Checkout Form -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow-sm border p-6">
                <!-- Payer Selection -->
                @if(auth()->check())
                    @if($preselectedPaymentMethod === 'invoice')
                        <!-- Invoice payment: Show organization selector or selected organization -->
                        <div class="mb-8">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ __('checkout.organization_purchase') }}</h3>

                            @if($payerType === 'organization' && $payerId)
                                {{-- Organization already selected - show info --}}
                                @php
                                    $selectedOrg = collect($availableOrganizations)->firstWhere('id', $payerId);
                                @endphp
                                @if($selectedOrg)
                                    <div class="p-4 border-2 border-indigo-500 bg-indigo-50 rounded-lg">
                                        <div class="flex items-center">
                                            <div>
                                                <p class="font-medium text-gray-900">{{ $selectedOrg['name'] }}</p>
                                                <p class="text-sm text-gray-500">{{ __('checkout.invoice_payment_organization_only') }}</p>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @elseif(!empty($availableOrganizations))
                                {{-- No organization selected yet - show selector --}}
                                <p class="text-sm text-gray-600 mb-4">{{ __('checkout.select_organization_for_invoice') }}</p>
                                <div class="grid grid-cols-1 gap-4">
                                    @foreach($availableOrganizations as $org)
                                        <div class="relative">
                                            <input type="radio"
                                                   wire:click="switchPayer('organization', {{ $org['id'] }})"
                                                   class="sr-only peer"
                                                   name="payer"
                                                   id="payer_org_{{ $org['id'] }}"
                                                   @if($payerType === 'organization' && $payerId === $org['id']) checked @endif>
                                            <label for="payer_org_{{ $org['id'] }}"
                                                   class="block p-4 border-2 rounded-lg cursor-pointer peer-checked:border-indigo-500 peer-checked:bg-indigo-50 hover:bg-gray-50">
                                                <div class="flex items-center">
                                                    <div>
                                                        <p class="font-medium text-gray-900">{{ $org['name'] }}</p>
                                                        <p class="text-sm text-gray-500">{{ __('checkout.organization_purchase') }}</p>
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                {{-- No organizations available --}}
                                <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                                    <p class="text-yellow-800">{{ __('checkout.no_organizations_for_invoice') }}</p>
                                </div>
                            @endif
                        </div>
                    @else
                        <!-- Normal payment: Show full payer selection -->
                        <div class="mb-8">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ __('checkout.who_is_purchasing') }}</h3>

                            <div class="grid grid-cols-1 gap-4">
                                <!-- Personal Purchase -->
                                <div class="relative">
                                    <input type="radio"
                                           wire:click="switchPayer('user', {{ auth()->id() }})"
                                           class="sr-only peer"
                                           name="payer"
                                           id="payer_user"
                                           @if($payerType === 'user') checked @endif>
                                    <label for="payer_user"
                                           class="block p-4 border-2 rounded-lg cursor-pointer peer-checked:border-indigo-500 peer-checked:bg-indigo-50 hover:bg-gray-50">
                                        <div class="flex items-center">
                                            <div>
                                                <p class="font-medium text-gray-900">{{ __('checkout.personal_purchase') }}</p>
                                                <p class="text-sm text-gray-500">{{ __('checkout.buy_for_yourself') }}</p>
                                            </div>
                                        </div>
                                    </label>
                                </div>

                                <!-- Organization Purchases -->
                                @if(!empty($availableOrganizations))
                                    @foreach($availableOrganizations as $org)
                                        <div class="relative">
                                            <input type="radio"
                                                   wire:click="switchPayer('organization', {{ $org['id'] }})"
                                                   class="sr-only peer"
                                                   name="payer"
                                                   id="payer_org_{{ $org['id'] }}"
                                                   @if($payerType === 'organization' && $payerId === $org['id']) checked @endif>
                                            <label for="payer_org_{{ $org['id'] }}"
                                                   class="block p-4 border-2 rounded-lg cursor-pointer peer-checked:border-indigo-500 peer-checked:bg-indigo-50 hover:bg-gray-50">
                                                <div class="flex items-center">
                                                    <div>
                                                        <p class="font-medium text-gray-900">{{ $org['name'] }}</p>
                                                        <p class="text-sm text-gray-500">{{ __('checkout.organization_purchase') }}</p>
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    @endforeach
                                @endif
                            </div>
                        </div>
                    @endif
                @endif

                <!-- Billing Information - Only show when payer is selected -->
                @if($payerType)
                    <div class="mb-8">
                        <h3 class="text-lg font-semibold text-gray-900 mb-6">{{ __('checkout.billing_information') }}</h3>
                    
                    <!-- Country Selection -->
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('checkout.country') }}</label>
                        <select wire:model.live="country" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="NL">Netherlands</option>
                            <option value="DE">Germany</option>
                            <option value="BE">Belgium</option>
                            <option value="FR">France</option>
                            <option value="ES">Spain</option>
                            <option value="IT">Italy</option>
                            <option value="AT">Austria</option>
                            <option value="DK">Denmark</option>
                            <option value="SE">Sweden</option>
                            <option value="NO">Norway</option>
                            <option value="CH">Switzerland</option>
                            <option value="GB">United Kingdom</option>
                            <option value="US">United States</option>
                            <option value="CA">Canada</option>
                            <option value="AU">Australia</option>
                        </select>
                    </div>

                    <!-- Billing Fields -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Email -->
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('checkout.email_address') }} *</label>
                            <input type="email"
                                   wire:model="billingData.email"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <p class="text-sm text-gray-500 mt-1">{{ __('checkout.email_address_hint') }}</p>
                            @error('billingData.email') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        </div>

                        <!-- Full Name (Individual) -->
                        @if($buyerType === 'individual')
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('checkout.full_name') }} *</label>
                                <input type="text" 
                                       wire:model="billingData.full_name"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                @error('billingData.full_name') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                        @endif

                        <!-- Company Fields -->
                        @if($buyerType === 'company')
                            @if($payerType === 'organization')
                                {{-- Organization purchase: Company name and VAT are read-only from profile --}}
                                <div class="md:col-span-2 bg-gray-50 rounded-lg p-4 border border-gray-200">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-500 mb-1">{{ __('checkout.company_name') }}</label>
                                            <p class="font-medium text-gray-900">{{ $billingData['company_name'] }}</p>
                                        </div>
                                        @if(!empty($billingData['vat_id']))
                                            <div>
                                                <label class="block text-sm font-medium text-gray-500 mb-1">{{ __('checkout.vat_id') }}</label>
                                                <p class="font-medium text-gray-900 flex items-center">
                                                    {{ $billingData['vat_id'] }}
                                                    @if($vatValidation && $vatValidation['valid'])
                                                        <svg class="h-4 w-4 text-green-500 ml-2" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                                        </svg>
                                                    @endif
                                                </p>
                                            </div>
                                        @endif
                                    </div>
                                    <a href="{{ route('profile.organization') }}" class="text-sm text-indigo-600 hover:text-indigo-800 mt-3 inline-block">
                                        {{ __('checkout.edit_in_organization_settings') }} →
                                    </a>
                                </div>
                            @else
                                {{-- Personal company purchase: editable fields --}}
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('checkout.company_name') }} *</label>
                                    <input type="text"
                                           wire:model="billingData.company_name"
                                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                    @error('billingData.company_name') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                                </div>

                                <!-- VAT ID for EU companies -->
                                @if($this->pricingCalculator->isEuCountry($country))
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('checkout.vat_id') }}</label>
                                        <div class="relative">
                                            <input type="text"
                                                   wire:model.live.debounce.1000ms="billingData.vat_id"
                                                   placeholder="e.g. NL123456789B01"
                                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 pr-10 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">

                                            @if($vatValidating)
                                                <div class="absolute right-3 top-3">
                                                    <svg class="animate-spin h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                    </svg>
                                                </div>
                                            @elseif($vatValidation)
                                                <div class="absolute right-3 top-3">
                                                    @if($vatValidation['valid'])
                                                        <svg class="h-4 w-4 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                                        </svg>
                                                    @else
                                                        <svg class="h-4 w-4 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                                        </svg>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>

                                        @if($vatValidation && !$vatValidation['valid'])
                                            <p class="text-red-500 text-sm mt-1">
                                                {{ $vatValidation['error'] ?? __('checkout.invalid_vat') }}
                                            </p>
                                        @elseif($vatValidation && $vatValidation['valid'])
                                            <p class="text-green-600 text-sm mt-1">✓ {{ __('checkout.valid_vat_no_charge') }}</p>
                                        @endif
                                    </div>
                                @endif
                            @endif

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('checkout.company_registration_number') }}</label>
                                <input type="text"
                                       wire:model="billingData.company_id"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('checkout.internal_reference') }}</label>
                                <input type="text"
                                       wire:model="billingData.internal_reference"
                                       placeholder="PO number, project code, etc."
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                        @endif

                        <!-- Address Fields -->
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('checkout.street_address') }} *</label>
                            <input type="text" 
                                   wire:model="billingData.street"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            @error('billingData.street') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('checkout.postal_code') }} *</label>
                            <input type="text" 
                                   wire:model="billingData.postal_code"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            @error('billingData.postal_code') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('checkout.city') }} *</label>
                            <input type="text"
                                   wire:model="billingData.city"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            @error('billingData.city') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                        </div>

                        {{-- State/Province field for countries that require it --}}
                        @if(in_array($country, ['US', 'CA', 'AU']))
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('checkout.state_province') }} *</label>
                                <input type="text"
                                       wire:model="billingData.state"
                                       placeholder="{{ __('checkout.state_placeholder') }}"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                @error('billingData.state') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
                            </div>
                        @endif
                    </div>
                    </div>

                    <!-- Payment Methods -->
                    @if($preselectedPaymentMethod === 'invoice')
                        <!-- Invoice Payment Selected -->
                        <div class="mb-8">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ __('checkout.payment_method') }}</h3>

                            @if($isTrustedOrganization)
                                {{-- Trusted organization: immediate activation --}}
                                <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                                    <div class="flex items-center space-x-3">
                                        <svg class="h-8 w-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        <div>
                                            <span class="text-sm font-medium text-green-900">{{ __('checkout.trusted_invoice_title') }}</span>
                                            <p class="text-xs text-green-700">{{ __('checkout.trusted_invoice_description') }}</p>
                                        </div>
                                    </div>
                                </div>
                            @else
                                {{-- Regular organization: pending approval --}}
                                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                    <div class="flex items-center space-x-3">
                                        <svg class="h-8 w-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                        <div>
                                            <span class="text-sm font-medium text-blue-900">{{ __('checkout.pay_by_invoice') }}</span>
                                            <p class="text-xs text-blue-700">{{ __('checkout.pay_by_invoice_description') }}</p>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @elseif(!empty($paymentMethods))
                        <div class="mb-8">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ __('checkout.payment_method') }}</h3>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                @foreach($paymentMethods as $method)
                                    <div class="relative">
                                        <input type="radio" 
                                               wire:model="selectedPaymentMethod" 
                                               wire:click="$refresh"
                                               value="{{ $method['id'] }}"
                                               class="sr-only peer" 
                                               id="payment_{{ $method['id'] }}">
                                        <label for="payment_{{ $method['id'] }}" 
                                               class="block p-4 border-2 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors duration-200
                                                      {{ $selectedPaymentMethod === $method['id'] ? 'border-indigo-500 bg-indigo-50' : 'border-gray-300' }}">
                                            <div class="flex items-center space-x-3">
                                                @if(isset($method['image']) && is_array($method['image']) && isset($method['image']['size2x']))
                                                    <img src="{{ $method['image']['size2x'] }}" 
                                                         alt="{{ $method['description'] }}"
                                                         class="h-8 w-auto">
                                                @endif
                                                <span class="text-sm font-medium">{{ $method['description'] }}</span>
                                            </div>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <!-- Action Buttons -->
                    <div class="flex justify-between pt-6 border-t">
                        <a href="{{ route('pricing') }}"
                           class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition duration-200">
                            ← {{ __('checkout.back_to_pricing') }}
                        </a>

                        <div class="space-x-4">
                            @if($preselectedPaymentMethod === 'invoice' && $isTrustedOrganization)
                                <button wire:click="createOrder"
                                        wire:confirm="{{ __('checkout.trusted_confirm_activation') }}"
                                        class="px-8 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition duration-200 disabled:opacity-50"
                                        wire:loading.attr="disabled">
                                    <span wire:loading.remove>{{ __('checkout.activate_license') }}</span>
                                    <span wire:loading>{{ __('checkout.processing') }}</span>
                                </button>
                            @else
                                <button wire:click="createOrder"
                                        class="px-8 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition duration-200 disabled:opacity-50"
                                        wire:loading.attr="disabled">
                                    <span wire:loading.remove>
                                        @if($preselectedPaymentMethod === 'invoice')
                                            {{ __('checkout.submit_license_request') }}
                                        @else
                                            {{ __('checkout.complete_payment') }}
                                        @endif
                                    </span>
                                    <span wire:loading>{{ __('checkout.processing') }}</span>
                                </button>
                            @endif
                        </div>
                    </div>
                @elseif(!($preselectedPaymentMethod === 'invoice' && !empty($availableOrganizations)))
                    {{-- Only show this message for non-invoice payments without payer selected --}}
                    {{-- For invoice payments with organizations, the selector is already shown above --}}
                    <div class="text-center py-8 text-gray-500">
                        <p class="mb-2">Please select who is making this purchase to continue.</p>
                        <div class="flex justify-center">
                            <a href="{{ route('pricing') }}"
                               class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition duration-200">
                                ← {{ __('checkout.back_to_pricing') }}
                            </a>
                        </div>
                    </div>
                @endif

                <!-- Error Messages -->
                @if($errors->any())
                    <div class="mt-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                        <ul class="text-red-700 text-sm space-y-1">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>
    </div>
    @else
        <div class="text-center py-12">
            <p class="text-gray-500 mb-4">No license selected for checkout.</p>
            <a href="{{ route('pricing') }}" 
               class="inline-block px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                Select a License
            </a>
        </div>
    @endif
</div>