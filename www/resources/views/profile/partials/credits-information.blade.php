<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('profile.credits') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('profile.credit_balance_description') }}
        </p>
    </header>

    <div class="mt-6 space-y-6">
        @auth
        @php
            $creditsService = app(\App\Services\CreditsService::class);
            $paymentSource = $creditsService->getPaymentSource(auth()->user());

            // Get current user license
            $currentUserLicense = auth()->user()->currentLicenses()->first();
        @endphp

        <div class="bg-gray-50 p-4 rounded-lg">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    @if($paymentSource['type'] === 'organization')
                        <svg class="h-6 w-6 text-blue-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                        </svg>
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ $paymentSource['name'] }}</p>
                            <p class="text-xs text-gray-500">{{ __('profile.organization_credits') }}</p>
                        </div>
                    @else
                        <svg class="h-6 w-6 text-green-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ __('profile.personal') }}</p>
                            <p class="text-xs text-gray-500">{{ __('profile.personal_credits') }}</p>
                        </div>
                    @endif
                </div>
                
                <div class="text-right">
                    <p class="text-2xl font-bold text-gray-900">{{ number_format($paymentSource['balance']) }}</p>
                    <p class="text-xs text-gray-500">{{ trans_choice('profile.credits_available', $paymentSource['balance']) }}</p>
                </div>
            </div>
        </div>

        {{-- Current License Information --}}
        @if($currentUserLicense)
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <div class="flex items-start justify-between">
                    <div class="flex items-start">
                        <svg class="h-5 w-5 text-blue-600 mt-0.5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"></path>
                        </svg>
                        <div>
                            <h4 class="text-sm font-semibold text-blue-900">{{ __('profile.current_license') }}</h4>
                            <p class="text-blue-800 font-medium">{{ $currentUserLicense->license->name }}</p>
                            @if($currentUserLicense->ends_at)
                                <p class="text-xs text-blue-600 mt-1">
                                    {{ __('profile.valid_until') }}: {{ format_date($currentUserLicense->ends_at) }}
                                </p>
                            @else
                                <p class="text-xs text-blue-600 mt-1">{{ __('profile.no_expiration_date') }}</p>
                            @endif
                        </div>
                    </div>
                    <div class="text-right">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            {{ ucfirst($currentUserLicense->status) }}
                        </span>
                        @if($currentUserLicense->source)
                            <p class="text-xs text-blue-600 mt-1">
                                {{ ucwords(str_replace('_', ' ', $currentUserLicense->source)) }}
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        @else
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <div class="flex items-center">
                    <svg class="h-5 w-5 text-gray-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <div>
                        <h4 class="text-sm font-medium text-gray-900">{{ __('profile.no_active_license') }}</h4>
                        <p class="text-xs text-gray-600">{{ __('profile.contact_support_activate') }}</p>
                    </div>
                </div>
            </div>
        @endif

        @if($paymentSource['type'] === 'organization')
            <div class="text-sm text-gray-600">
                <p>💡 <strong>{{ __('profile.payment_source') }}:</strong> {{ __('profile.payment_source_organization') }}</p>
            </div>
        @else
            <div class="text-sm text-gray-600">
                <p>💡 <strong>{{ __('profile.payment_source') }}:</strong> {{ __('profile.payment_source_personal') }}</p>
            </div>
        @endif

        @if($paymentSource['balance'] < 10)
            <div class="bg-yellow-50 border border-yellow-200 rounded-md p-4">
                <div class="flex">
                    <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                    </svg>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-yellow-800">{{ __('profile.low_credit_balance') }}</h3>
                        <p class="mt-1 text-sm text-yellow-700">
                            {{ trans_choice('profile.credits_remaining', $paymentSource['balance'], ['count' => $paymentSource['balance']]) }}
                            @if($paymentSource['type'] === 'organization')
                                {{ __('profile.contact_org_admin_credits') }}
                            @else
                                {{ __('profile.consider_purchasing_credits') }}
                            @endif
                        </p>
                    </div>
                </div>
            </div>
        @endif

        {{-- Detailed Credit Usage by License --}}
        @if(isset($activeLicenses) && $activeLicenses->count() > 0)
            <div class="mt-8">
                <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('profile.credit_usage_by_license') }}</h3>

                @foreach($activeLicenses as $index => $license)
                    @php
                        $isPrimary = $index === 0;
                        $isOrganizational = $license->license_type === 'organizational';

                        $spendingLicenseType = $isOrganizational ? 'organization' : 'user';
                        $spendingLicenseId = $isOrganizational && isset($license->source_organization)
                            ? $license->source_organization->id
                            : auth()->user()->id;

                        // Calculate period based on license start date
                        if ($license->starts_at && $license->starts_at <= now()) {
                            $periodDays = (int) now()->diffInDays($license->starts_at, false);
                        } else {
                            $periodDays = 30;
                        }
                        $periodDays = max(1, abs($periodDays));

                        $backgroundColor = $isPrimary ? 'bg-blue-50 border-blue-200' : 'bg-gray-50 border-gray-200';
                    @endphp

                    <div class="{{ $backgroundColor }} border rounded-lg p-4 mb-4">
                        <div class="mb-3">
                            <div class="flex items-center justify-between">
                                <div>
                                    @if($isPrimary)
                                        <span class="inline-flex items-center text-sm font-semibold text-blue-900">
                                            <svg class="w-4 h-4 mr-1 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                            </svg>
                                            {{ __('profile.primary_license') }}
                                        </span>
                                    @else
                                        <span class="text-sm font-semibold text-gray-700">{{ __('profile.license_number', ['number' => $index + 1]) }}</span>
                                    @endif
                                    <h4 class="text-lg font-medium {{ $isPrimary ? 'text-blue-900' : 'text-gray-900' }}">
                                        {{ $license->license->name }}
                                    </h4>
                                    @if($isOrganizational && isset($license->source_organization))
                                        <p class="text-sm {{ $isPrimary ? 'text-blue-600' : 'text-gray-600' }}">
                                            {{ __('profile.organization_label') }}: {{ $license->source_organization->name }}
                                        </p>
                                    @else
                                        <p class="text-sm {{ $isPrimary ? 'text-blue-600' : 'text-gray-600' }}">
                                            {{ __('profile.individual_license') }}
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <x-license-credit-spending
                            :license-type="$spendingLicenseType"
                            :license-id="$spendingLicenseId"
                            :title="__('profile.credit_usage')"
                            :period="$periodDays"
                            :display-mode="'detailed'"
                            :show-workflows="true"
                            :show-recent-activity="true"
                        />
                    </div>
                @endforeach
            </div>
        @endif
        @else
        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
            <div class="flex items-center">
                <svg class="h-5 w-5 text-gray-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <div>
                    <h4 class="text-sm font-medium text-gray-900">{{ __('profile.login_required') }}</h4>
                    <p class="text-xs text-gray-600">{{ __('profile.login_required_credits') }}</p>
                </div>
            </div>
        </div>
        @endauth
    </div>
</section>