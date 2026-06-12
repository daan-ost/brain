@extends('layouts.profile')

@section('content')
    <div class="p-6 space-y-6">

        {{-- Page Header with Credits Summary --}}
        <div class="flex items-start justify-between">
            <div>
                <h2 class="text-2xl font-bold text-gray-900">{{ __('profile.plans') }}</h2>
                @if($primaryLicenseData['exists'] && $primaryLicenseData['credit_renewal_date'])
                    <p class="mt-1 text-sm text-gray-600">
                        @if($primaryLicenseData['billing_cycle'] === 'once')
                            {{ __('profile.credits_valid_until_message', [
                                'credits' => number_format($paymentSource['balance']),
                                'date' => $primaryLicenseData['valid_until_date'] ? format_date($primaryLicenseData['valid_until_date']) : '-'
                            ]) }}
                        @else
                            {{ __('profile.credits_renew_message', [
                                'credits' => number_format($paymentSource['balance']),
                                'date' => format_date($primaryLicenseData['credit_renewal_date'])
                            ]) }}
                        @endif
                    </p>
                @endif
            </div>

            {{-- Credits Summary Box (top right) --}}
            <div class="bg-indigo-50 border border-indigo-200 rounded-lg px-4 py-3 text-right">
                <p class="text-2xl font-bold text-indigo-900">{{ number_format($paymentSource['balance']) }}</p>
                <p class="text-xs text-indigo-600">{{ __('profile.credits_this_period') }}</p>
                @if($paymentSource['type'] === 'organization')
                    <p class="text-xs text-indigo-700 font-medium">{{ $paymentSource['name'] }}</p>
                @endif
            </div>
        </div>

        {{-- Your Plan Section --}}
        <section class="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <div class="px-4 py-3 bg-gray-50 border-b border-gray-200">
                <h3 class="text-base font-semibold text-gray-900">{{ __('profile.your_plan') }}</h3>
            </div>

            @if($primaryLicenseData['exists'])
                <table class="min-w-full">
                    <tbody class="divide-y divide-gray-200">
                        {{-- Credits Row --}}
                        <tr>
                            <td class="px-4 py-3 text-sm text-gray-500 w-1/3">
                                @if($primaryLicenseData['billing_cycle'] === 'once' && $primaryLicenseData['tier'] !== 'free')
                                    {{ __('profile.credits') }}
                                @elseif($primaryLicenseData['display_billing_cycle'] === 'yearly')
                                    {{ __('profile.credits_per_year') }}
                                @else
                                    {{ __('profile.credits_per_month') }}
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                {{ number_format($primaryLicenseData['credits']) }}
                            </td>
                        </tr>
                        {{-- Price Row (not for free) --}}
                        @if($primaryLicenseData['tier'] !== 'free')
                            <tr>
                                <td class="px-4 py-3 text-sm text-gray-500">
                                    {{ __('profile.price') }}
                                </td>
                                <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                    {{ $primaryLicenseData['price'] ?? '-' }}
                                </td>
                            </tr>
                        @endif
                        {{-- Credit replenishment date (free or recurring with credit_reset_interval) --}}
                        @if($primaryLicenseData['credit_renewal_date'])
                            <tr>
                                <td class="px-4 py-3 text-sm text-gray-500">
                                    {{ __('profile.credits_replenish_date') }}
                                </td>
                                <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                    {{ format_date($primaryLicenseData['credit_renewal_date']) }}
                                </td>
                            </tr>
                        @endif
                        {{-- Subscription renewal date (recurring only, not free) --}}
                        @if($primaryLicenseData['subscription_renewal_date'] && $primaryLicenseData['tier'] !== 'free' && !$primaryLicenseData['ends_at'])
                            <tr>
                                <td class="px-4 py-3 text-sm text-gray-500">
                                    {{ __('profile.subscription_renewal_date') }}
                                </td>
                                <td class="px-4 py-3 text-sm font-medium text-gray-900 flex items-center justify-between">
                                    <span>{{ format_date($primaryLicenseData['subscription_renewal_date']) }}</span>
                                    @if($primaryLicenseData['can_cancel'])
                                        <button
                                            onclick="if(confirm('{{ __('profile.cancel_renewal_confirm', ['date' => format_date($primaryLicenseData['subscription_renewal_date'])]) }}')) { document.getElementById('cancel-renewal-form').submit(); }"
                                            class="text-xs text-red-600 hover:text-red-800 underline ml-4">
                                            {{ __('profile.cancel_renewal') }}
                                        </button>
                                        <form id="cancel-renewal-form" action="{{ route('profile.plans.cancel-renewal', $primaryLicenseData['id']) }}" method="POST" class="hidden">
                                            @csrf
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endif

                        {{-- Stripe Billing Portal knop --}}
                        @if(($primaryLicenseData['payment_provider'] ?? null) === 'stripe' && $primaryLicenseData['tier'] !== 'free')
                            <tr>
                                <td class="px-4 py-3 text-sm text-gray-500">
                                    Beheer abonnement
                                </td>
                                <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                    <form action="{{ route('profile.plans.billing-portal') }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" class="text-sm text-indigo-600 hover:text-indigo-800 underline">
                                            Betaalmethode, facturen &amp; opzeggen →
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endif
                        {{-- Valid until date (one-time only, not free) --}}
                        @if($primaryLicenseData['valid_until_date'])
                            <tr>
                                <td class="px-4 py-3 text-sm text-gray-500">
                                    {{ __('profile.valid_until') }}
                                </td>
                                <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                    {{ format_date($primaryLicenseData['valid_until_date']) }}
                                </td>
                            </tr>
                        @endif
                        {{-- End date (canceled recurring) --}}
                        @if($primaryLicenseData['ends_at'] && $primaryLicenseData['tier'] !== 'free' && $primaryLicenseData['billing_cycle'] !== 'once')
                            <tr>
                                <td class="px-4 py-3 text-sm text-gray-500">
                                    {{ __('profile.end_date') }}
                                </td>
                                <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                    {{ format_date($primaryLicenseData['ends_at']) }}
                                </td>
                            </tr>
                        @endif
                        {{-- Organization Row (if applicable) --}}
                        @if($primaryLicenseData['is_organizational'])
                            <tr>
                                <td class="px-4 py-3 text-sm text-gray-500">
                                    {{ __('profile.organization_label') }}
                                </td>
                                <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                    {{ $primaryLicenseData['organization_name'] }}
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            @else
                <div class="p-4">
                    <p class="text-sm text-gray-600">{{ __('profile.no_active_license') }}</p>
                </div>
            @endif
        </section>

        {{-- Additional Licenses (Collapsed) --}}
        @if($activeLicenses->count() > 1)
            <section x-data="{ open: false }" class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                <button @click="open = !open" class="flex items-center justify-between w-full px-4 py-3 text-left bg-gray-50 border-b border-gray-200">
                    <h3 class="text-base font-semibold text-gray-900">
                        {{ __('profile.additional_licenses') }} ({{ $activeLicenses->count() - 1 }})
                    </h3>
                    <svg class="h-5 w-5 text-gray-500 transform transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>

                <div x-show="open" x-collapse class="p-4 space-y-3">
                    @foreach($activeLicenses->skip(1) as $license)
                        @php
                            $isOrganizational = $license->license_type === 'organizational';
                            $rawBillingCycle = $license->license->billing_cycle ?? 'once';
                            $billingCycle = $rawBillingCycle === 'one_time' ? 'once' : $rawBillingCycle;
                            $creditResetInterval = $license->license->credit_reset_interval;
                            $credits = $license->license->credits ?? 0;

                            // Use credit_reset_interval for the cycle text
                            if ($creditResetInterval === 'monthly') {
                                $cycleText = __('profile.credits_reset_monthly');
                            } elseif ($creditResetInterval === 'yearly') {
                                $cycleText = __('profile.credits_reset_yearly');
                            } elseif ($billingCycle === 'once' || $creditResetInterval === 'none') {
                                $cycleText = __('profile.credits_one_time');
                            } else {
                                $cycleText = '';
                            }
                        @endphp
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">
                                        @if($isOrganizational && isset($license->source_organization))
                                            {{ $license->source_organization->name }}
                                        @else
                                            {{ $license->license->name }}
                                        @endif
                                    </p>
                                    <p class="text-xs text-gray-600">{{ $credits }} {{ $cycleText }}</p>
                                </div>
                                @if($license->ends_at || $license->starts_at)
                                    <p class="text-xs text-gray-500">
                                        @if($billingCycle === 'once' && $license->ends_at)
                                            {{ __('profile.valid_until') }}: {{ format_date($license->ends_at) }}
                                        @elseif($license->starts_at && $creditResetInterval && $creditResetInterval !== 'none')
                                            @php
                                                $renewalService = app(\App\Services\LicenseRenewalService::class);
                                                $nextDate = $renewalService->getNextRenewalDate($license->starts_at, $license->license->credit_reset_interval ?? $billingCycle);
                                            @endphp
                                            {{ __('profile.renews') }}: {{ format_date($nextDate) }}
                                        @endif
                                    </p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Pending Licenses --}}
        @if($pendingLicenses->count() > 0)
            <section>
                <h3 class="text-base font-semibold text-gray-900 mb-3">{{ __('profile.pending_licenses') }}</h3>

                <div class="space-y-3">
                    @foreach($pendingLicenses as $license)
                        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
                            <div class="flex items-start justify-between">
                                <div class="flex items-start">
                                    <svg class="h-5 w-5 text-amber-600 mt-0.5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <div>
                                        <h4 class="text-sm font-semibold text-amber-900">
                                            {{ $license->license->name ?? __('profile.unknown_license') }}
                                        </h4>
                                        @if($license->organization)
                                            <p class="text-xs text-amber-700 mt-1">
                                                {{ __('profile.organization_label') }}: {{ $license->organization->name }}
                                            </p>
                                        @endif
                                        <p class="text-xs text-amber-700 mt-1">
                                            {{ __('profile.pending_license_credits', ['credits' => number_format($license->license->credits ?? 0)]) }}
                                        </p>
                                        @if($license->invoice_number)
                                            <p class="text-xs text-amber-700 mt-1">
                                                {{ __('profile.invoice_number_short', ['number' => $license->invoice_number]) }}
                                            </p>
                                        @endif
                                        @if($license->invoice_due_date)
                                            <p class="text-xs text-amber-700 mt-1">
                                                {{ __('profile.invoice_due_date_label') }}: {{ format_date($license->invoice_due_date) }}
                                            </p>
                                        @endif
                                    </div>
                                </div>
                                <div class="text-right">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                                        {{ __('profile.awaiting_payment') }}
                                    </span>
                                    @if($license->invoice_number)
                                        <div class="mt-2">
                                            <a href="{{ route('profile.invoices.index') }}"
                                               class="text-xs text-amber-700 hover:text-amber-900 underline">
                                                {{ __('profile.download_invoice') }}
                                            </a>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Credit Usage Details (Collapsed) --}}
        @if($activeLicenses->count() > 0)
            <section x-data="{ open: false }" class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                <button @click="open = !open" class="flex items-center justify-between w-full px-4 py-3 text-left bg-gray-50 border-b border-gray-200">
                    <h3 class="text-base font-semibold text-gray-900">{{ __('profile.credit_usage_details') }}</h3>
                    <svg class="h-5 w-5 text-gray-500 transform transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>

                <div x-show="open" x-collapse class="p-4 space-y-4">
                    @foreach($activeLicenses as $license)
                        @php
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
                        @endphp

                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                            <h4 class="text-sm font-medium text-gray-900 mb-3">
                                @if($isOrganizational && isset($license->source_organization))
                                    {{ $license->source_organization->name }}
                                @else
                                    {{ __('profile.personal') }}
                                @endif
                            </h4>

                            <x-license-credit-spending
                                :license-type="$spendingLicenseType"
                                :license-id="$spendingLicenseId"
                                :title="__('profile.credit_usage')"
                                :period="$periodDays"
                                :display-mode="'detailed'"
                                :show-workflows="false"
                                :show-recent-activity="true"
                            />
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- License History (Collapsed) --}}
        @if($previousSubscriptions->count() > 0 || $previousOrganizationalLicenses->count() > 0)
            <section x-data="{ open: false }" class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                <button @click="open = !open" class="flex items-center justify-between w-full px-4 py-3 text-left bg-gray-50 border-b border-gray-200">
                    <h3 class="text-base font-semibold text-gray-900">{{ __('profile.license_history') }}</h3>
                    <svg class="h-5 w-5 text-gray-500 transform transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>

                <div x-show="open" x-collapse class="p-4 space-y-6">
                    {{-- Individual License History --}}
                    @if($previousSubscriptions->count() > 0)
                        <div>
                            <h4 class="text-sm font-medium text-gray-700 mb-3">{{ __('profile.individual_licenses') }}</h4>
                            <div class="overflow-hidden border border-gray-200 rounded-lg">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('profile.license') }}</th>
                                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('profile.start_date') }}</th>
                                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('profile.end_date') }}</th>
                                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('common.status') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        @foreach($previousSubscriptions as $subscription)
                                            <tr>
                                                <td class="px-4 py-2 text-sm text-gray-900">{{ $subscription->license->name }}</td>
                                                <td class="px-4 py-2 text-sm text-gray-600">{{ $subscription->starts_at ? format_date($subscription->starts_at) : '-' }}</td>
                                                <td class="px-4 py-2 text-sm text-gray-600">{{ $subscription->ends_at ? format_date($subscription->ends_at) : '-' }}</td>
                                                <td class="px-4 py-2">
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                                        @if($subscription->status === 'expired') bg-red-100 text-red-800
                                                        @elseif($subscription->status === 'canceled') bg-yellow-100 text-yellow-800
                                                        @else bg-gray-100 text-gray-800
                                                        @endif">
                                                        {{ __('profile.status_' . $subscription->status) }}
                                                    </span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    {{-- Organizational License History (Admin Only) --}}
                    @if($previousOrganizationalLicenses->count() > 0)
                        <div>
                            <h4 class="text-sm font-medium text-gray-700 mb-3">
                                {{ __('profile.organizational_licenses_admin') }}
                                <span class="text-xs text-indigo-600 bg-indigo-100 px-2 py-0.5 rounded-full ml-2">{{ __('profile.admin_view') }}</span>
                            </h4>
                            <div class="overflow-hidden border border-gray-200 rounded-lg">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-indigo-50">
                                        <tr>
                                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('profile.organization_label') }}</th>
                                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('profile.license') }}</th>
                                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('profile.end_date') }}</th>
                                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('common.status') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        @foreach($previousOrganizationalLicenses as $orgLicense)
                                            <tr>
                                                <td class="px-4 py-2 text-sm font-medium text-indigo-900">{{ $orgLicense->organization->name }}</td>
                                                <td class="px-4 py-2 text-sm text-gray-900">{{ $orgLicense->license->name }}</td>
                                                <td class="px-4 py-2 text-sm text-gray-600">{{ $orgLicense->ends_at ? format_date($orgLicense->ends_at) : '-' }}</td>
                                                <td class="px-4 py-2">
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                                        @if($orgLicense->status === 'expired') bg-red-100 text-red-800
                                                        @elseif($orgLicense->status === 'canceled') bg-yellow-100 text-yellow-800
                                                        @else bg-gray-100 text-gray-800
                                                        @endif">
                                                        {{ __('profile.status_' . $orgLicense->status) }}
                                                    </span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                </div>
            </section>
        @endif

        {{-- Upgrade Button (only for admins and individual users) --}}
        @if($canUpgrade)
            <div class="text-center pt-2">
                <a href="{{ route('pricing') }}"
                   class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-lg text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200 shadow-sm hover:shadow-md">
                    <svg class="h-5 w-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                    </svg>
                    {{ __('profile.upgrade_plan') }}
                </a>
            </div>
        @endif

    </div>
@endsection
