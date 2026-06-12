@extends('layouts.profile')

@section('header')
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ __('profile.organization') }}
    </h2>
@endsection

@section('content')
    <div class="p-6">
        <section>
            @if($organizations->count() > 0)
                <div class="mt-6 space-y-6">
                    <!-- My Organization Section -->
                    <div class="border-t pt-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('profile.my_organization') }}</h3>
                        
                        @foreach($organizations as $organization)
                            <div class="bg-gray-50 rounded-lg p-4 mb-4">
                                <div class="flex items-center justify-between mb-4">
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-900">{{ $organization->name }}</h4>
                                        <p class="text-xs text-gray-500">
                                            {{ __('profile.role') }} <span class="font-medium">{{ $organization->pivot->role->label() }}</span>
                                            @if($organization->pivot->joined_at)
                                                • {{ __('profile.joined') }} {{ format_date(\Carbon\Carbon::parse($organization->pivot->joined_at)) }}
                                            @endif
                                        </p>
                                    </div>
                                    @if($organization->pivot->role === \App\Enums\OrganizationRole::Owner)
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            {{ __('profile.admin') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                            {{ __('profile.member') }}
                                        </span>
                                    @endif
                                </div>
                                
                                <!-- Credits Information -->
                                <div class="mt-3 pt-3 border-t border-gray-200">
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-medium text-gray-700">{{ __('profile.organization_credits') }}</span>
                                        <span class="text-lg font-bold text-indigo-600">
                                            {{ number_format($organization->creditPool->balance_credits ?? 0) }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Buy Credits Button (Admin + No Active License) -->
                                @if($organization->pivot->role === \App\Enums\OrganizationRole::Owner && $organization->currentLicenses->count() === 0)
                                    <div class="mt-3 pt-3 border-t border-gray-200">
                                        <a href="{{ route('pricing') }}"
                                           class="inline-flex items-center justify-center w-full px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-md transition-colors duration-200">
                                            <svg class="h-5 w-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                            </svg>
                                            {{ __('profile.buy_credits_on_organization') }}
                                        </a>
                                    </div>
                                @endif

                                <!-- Trusted Organization Badge -->
                                @if($organization->is_trusted)
                                    <div class="mt-3 pt-3 border-t border-gray-200">
                                        <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                                            <div class="flex items-start">
                                                <div class="flex-shrink-0">
                                                    <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                                    </svg>
                                                </div>
                                                <div class="ml-3">
                                                    <h4 class="text-sm font-medium text-green-800">
                                                        {{ __('profile.trusted_organization') }}
                                                    </h4>
                                                    <p class="mt-1 text-sm text-green-700">
                                                        {{ __('profile.trusted_organization_description') }}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                <!-- Organization Details -->
                                @if($organization->vat_number)
                                    <div class="mt-3 pt-3 border-t border-gray-200 text-sm">
                                        <span class="font-medium text-gray-700">{{ __('profile.vat_number') }}:</span>
                                        <span class="text-gray-900">{{ $organization->vat_number }}</span>
                                        @if($organization->vat_validated_at)
                                            <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                                {{ __('profile.validated') }} {{ format_date($organization->vat_validated_at) }}
                                            </span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <!-- Organization Settings (Admin Only) -->
                    @if($isAdmin && $currentOrganization)
                        <div class="border-t pt-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('profile.organization_settings') }}</h3>
                            
                            <form method="post" action="{{ route('profile.organization.update') }}" class="space-y-6">
                                @csrf
                                @method('patch')

                                <div>
                                    <x-input-label for="name" :value="__('profile.organization_name')" />
                                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                                                 :value="old('name', $currentOrganization->name)" required />
                                    <x-input-error class="mt-2" :messages="$errors->get('name')" />
                                </div>

                                <div>
                                    <x-input-label for="billing_country_code" :value="__('profile.country')" />
                                    <select id="billing_country_code" name="billing_country_code"
                                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                        <option value="">{{ __('profile.select_country') }}</option>
                                        <option value="NL" {{ old('billing_country_code', $currentOrganization->billing_country_code) === 'NL' ? 'selected' : '' }}>🇳🇱 {{ __('profile.countries.NL') }}</option>
                                        <option value="BE" {{ old('billing_country_code', $currentOrganization->billing_country_code) === 'BE' ? 'selected' : '' }}>🇧🇪 {{ __('profile.countries.BE') }}</option>
                                        <option value="DE" {{ old('billing_country_code', $currentOrganization->billing_country_code) === 'DE' ? 'selected' : '' }}>🇩🇪 {{ __('profile.countries.DE') }}</option>
                                        <option value="FR" {{ old('billing_country_code', $currentOrganization->billing_country_code) === 'FR' ? 'selected' : '' }}>🇫🇷 {{ __('profile.countries.FR') }}</option>
                                        <option value="GB" {{ old('billing_country_code', $currentOrganization->billing_country_code) === 'GB' ? 'selected' : '' }}>🇬🇧 {{ __('profile.countries.GB') }}</option>
                                        <option value="US" {{ old('billing_country_code', $currentOrganization->billing_country_code) === 'US' ? 'selected' : '' }}>🇺🇸 {{ __('profile.countries.US') }}</option>
                                        <option value="CA" {{ old('billing_country_code', $currentOrganization->billing_country_code) === 'CA' ? 'selected' : '' }}>🇨🇦 {{ __('profile.countries.CA') }}</option>
                                        <option value="AU" {{ old('billing_country_code', $currentOrganization->billing_country_code) === 'AU' ? 'selected' : '' }}>🇦🇺 {{ __('profile.countries.AU') }}</option>
                                        <option value="AT" {{ old('billing_country_code', $currentOrganization->billing_country_code) === 'AT' ? 'selected' : '' }}>🇦🇹 {{ __('profile.countries.AT') }}</option>
                                        <option value="CH" {{ old('billing_country_code', $currentOrganization->billing_country_code) === 'CH' ? 'selected' : '' }}>🇨🇭 {{ __('profile.countries.CH') }}</option>
                                        <option value="DK" {{ old('billing_country_code', $currentOrganization->billing_country_code) === 'DK' ? 'selected' : '' }}>🇩🇰 {{ __('profile.countries.DK') }}</option>
                                        <option value="ES" {{ old('billing_country_code', $currentOrganization->billing_country_code) === 'ES' ? 'selected' : '' }}>🇪🇸 {{ __('profile.countries.ES') }}</option>
                                        <option value="FI" {{ old('billing_country_code', $currentOrganization->billing_country_code) === 'FI' ? 'selected' : '' }}>🇫🇮 {{ __('profile.countries.FI') }}</option>
                                        <option value="IT" {{ old('billing_country_code', $currentOrganization->billing_country_code) === 'IT' ? 'selected' : '' }}>🇮🇹 {{ __('profile.countries.IT') }}</option>
                                        <option value="NO" {{ old('billing_country_code', $currentOrganization->billing_country_code) === 'NO' ? 'selected' : '' }}>🇳🇴 {{ __('profile.countries.NO') }}</option>
                                        <option value="PL" {{ old('billing_country_code', $currentOrganization->billing_country_code) === 'PL' ? 'selected' : '' }}>🇵🇱 {{ __('profile.countries.PL') }}</option>
                                        <option value="PT" {{ old('billing_country_code', $currentOrganization->billing_country_code) === 'PT' ? 'selected' : '' }}>🇵🇹 {{ __('profile.countries.PT') }}</option>
                                        <option value="SE" {{ old('billing_country_code', $currentOrganization->billing_country_code) === 'SE' ? 'selected' : '' }}>🇸🇪 {{ __('profile.countries.SE') }}</option>
                                    </select>
                                    <x-input-error class="mt-2" :messages="$errors->get('billing_country_code')" />
                                </div>

                                <div>
                                    <x-input-label for="currency_preference" :value="__('profile.currency')" />
                                    <select id="currency_preference" name="currency_preference"
                                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                        <option value="EUR" {{ old('currency_preference', $currentOrganization->currency_preference) === 'EUR' ? 'selected' : '' }}>{{ __('profile.currencies.EUR') }}</option>
                                        <option value="USD" {{ old('currency_preference', $currentOrganization->currency_preference) === 'USD' ? 'selected' : '' }}>{{ __('profile.currencies.USD') }}</option>
                                    </select>
                                    <x-input-error class="mt-2" :messages="$errors->get('currency_preference')" />
                                </div>

                                <div>
                                    <x-input-label for="vat_number" :value="__('profile.vat_number_optional')" />
                                    <x-text-input id="vat_number" name="vat_number" type="text" class="mt-1 block w-full"
                                                 :value="old('vat_number', $currentOrganization->vat_number)"
                                                 :placeholder="__('profile.vat_number_placeholder')" />
                                    <p class="mt-1 text-sm text-gray-500">
                                        {{ __('profile.vat_number_help') }}
                                    </p>
                                    <x-input-error class="mt-2" :messages="$errors->get('vat_number')" />
                                </div>

                                <div class="flex items-center gap-4">
                                    <x-primary-button>{{ __('profile.save_changes') }}</x-primary-button>

                                    @if (session('status') === 'organization-updated')
                                        <p x-data="{ show: true }"
                                           x-show="show"
                                           x-transition
                                           x-init="setTimeout(() => show = false, 2000)"
                                           class="text-sm text-gray-600">
                                            {{ __('profile.organization_updated_successfully') }}
                                        </p>
                                    @endif

                                    @if (session('error'))
                                        <p class="text-sm text-red-600">
                                            {{ session('error') }}
                                        </p>
                                    @endif
                                </div>
                            </form>
                        </div>
                    @endif
                </div>
            @else
                <div class="mt-6" x-data="{ showForm: false }">
                    <!-- Empty State with Better Layout -->
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden" x-show="!showForm">
                        <div class="px-6 py-12">
                            <div class="max-w-3xl mx-auto">
                                <div class="flex flex-col md:flex-row items-center gap-8">
                                    <!-- Left side: Illustration -->
                                    <div class="flex-shrink-0">
                                        <div class="w-48 h-48 bg-gradient-to-br from-blue-50 to-indigo-50 rounded-2xl flex items-center justify-center">
                                            <svg class="w-24 h-24 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                            </svg>
                                        </div>
                                    </div>

                                    <!-- Right side: Content -->
                                    <div class="flex-1 text-center md:text-left">
                                        <h3 class="text-2xl font-semibold text-gray-900 mb-3">
                                            {{ __('profile.create_organization_title') }}
                                        </h3>
                                        <p class="text-gray-600 mb-6">
                                            {{ __('profile.create_organization_subtitle') }}
                                        </p>

                                        <!-- Benefits List -->
                                        <ul class="space-y-3 mb-8">
                                            <li class="flex items-start">
                                                <svg class="w-5 h-5 text-green-500 mr-3 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                </svg>
                                                <span class="text-gray-700">{{ __('profile.org_benefit_invite') }}</span>
                                            </li>
                                            <li class="flex items-start">
                                                <svg class="w-5 h-5 text-green-500 mr-3 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                </svg>
                                                <span class="text-gray-700">{{ __('profile.org_benefit_credits') }}</span>
                                            </li>
                                            <li class="flex items-start">
                                                <svg class="w-5 h-5 text-green-500 mr-3 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                </svg>
                                                <span class="text-gray-700">{{ __('profile.org_benefit_workflows') }}</span>
                                            </li>
                                        </ul>

                                        <!-- CTA Button -->
                                        <div>
                                            <button
                                                @click="showForm = true; $nextTick(() => document.getElementById('name').focus())"
                                                type="button"
                                                class="inline-flex items-center px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg shadow-sm transition-colors duration-200">
                                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                                </svg>
                                                {{ __('profile.create_organization') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- DEBUG: View updated at {{ now() }} -->

                    @if(!$user->hasVerifiedEmail())
                        <!-- Email Verification Required Notice -->
                        <div class="border-t pt-6">
                            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                                <div class="flex">
                                    <div class="flex-shrink-0">
                                        <svg class="h-5 w-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                        </svg>
                                    </div>
                                    <div class="ml-3">
                                        <h3 class="text-sm font-medium text-yellow-800">{{ __('profile.email_verification_required') }}</h3>
                                        <p class="mt-2 text-sm text-yellow-700">
                                            {{ __('profile.email_verification_required_description') }}
                                        </p>
                                        <div class="mt-4">
                                            <form method="post" action="{{ route('verification.send') }}">
                                                @csrf
                                                <button type="submit" class="text-sm font-medium text-yellow-800 hover:text-yellow-900 underline">
                                                    {{ __('profile.resend_verification_email') }}
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif

                    <!-- Create Organization Form -->
                    <div id="create-form" class="mt-8 bg-white rounded-lg shadow-sm border border-gray-200 p-6" :class="showForm ? 'block' : 'hidden'">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-medium text-gray-900">{{ __('profile.create_new_organization') }}</h3>
                            <button
                                @click="showForm = false"
                                type="button"
                                class="text-gray-400 hover:text-gray-600 transition">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>

                        @if(!$user->hasVerifiedEmail())
                            <p class="text-sm text-gray-500 mb-4 italic">
                                {{ __('profile.email_verification_create_org') }}
                            </p>
                        @endif

                        <form method="post" action="{{ route('profile.organization.create') }}" class="space-y-6">
                            @csrf

                            <div>
                                <x-input-label for="name" :value="__('profile.organization_name')" />
                                <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                                             :value="old('name')" required :disabled="!$user->hasVerifiedEmail()" />
                                <x-input-error class="mt-2" :messages="$errors->get('name')" />
                            </div>

                            <div>
                                <x-input-label for="billing_country_code" :value="__('profile.country')" />
                                <select id="billing_country_code" name="billing_country_code"
                                        class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                        {{ !$user->hasVerifiedEmail() ? 'disabled' : '' }}>
                                    <option value="">{{ __('profile.select_country') }}</option>
                                    <option value="NL" {{ old('billing_country_code') === 'NL' ? 'selected' : '' }}>🇳🇱 {{ __('profile.countries.NL') }}</option>
                                    <option value="BE" {{ old('billing_country_code') === 'BE' ? 'selected' : '' }}>🇧🇪 {{ __('profile.countries.BE') }}</option>
                                    <option value="DE" {{ old('billing_country_code') === 'DE' ? 'selected' : '' }}>🇩🇪 {{ __('profile.countries.DE') }}</option>
                                    <option value="FR" {{ old('billing_country_code') === 'FR' ? 'selected' : '' }}>🇫🇷 {{ __('profile.countries.FR') }}</option>
                                    <option value="GB" {{ old('billing_country_code') === 'GB' ? 'selected' : '' }}>🇬🇧 {{ __('profile.countries.GB') }}</option>
                                    <option value="US" {{ old('billing_country_code') === 'US' ? 'selected' : '' }}>🇺🇸 {{ __('profile.countries.US') }}</option>
                                    <option value="CA" {{ old('billing_country_code') === 'CA' ? 'selected' : '' }}>🇨🇦 {{ __('profile.countries.CA') }}</option>
                                    <option value="AU" {{ old('billing_country_code') === 'AU' ? 'selected' : '' }}>🇦🇺 {{ __('profile.countries.AU') }}</option>
                                    <option value="AT" {{ old('billing_country_code') === 'AT' ? 'selected' : '' }}>🇦🇹 {{ __('profile.countries.AT') }}</option>
                                    <option value="CH" {{ old('billing_country_code') === 'CH' ? 'selected' : '' }}>🇨🇭 {{ __('profile.countries.CH') }}</option>
                                    <option value="DK" {{ old('billing_country_code') === 'DK' ? 'selected' : '' }}>🇩🇰 {{ __('profile.countries.DK') }}</option>
                                    <option value="ES" {{ old('billing_country_code') === 'ES' ? 'selected' : '' }}>🇪🇸 {{ __('profile.countries.ES') }}</option>
                                    <option value="FI" {{ old('billing_country_code') === 'FI' ? 'selected' : '' }}>🇫🇮 {{ __('profile.countries.FI') }}</option>
                                    <option value="IT" {{ old('billing_country_code') === 'IT' ? 'selected' : '' }}>🇮🇹 {{ __('profile.countries.IT') }}</option>
                                    <option value="NO" {{ old('billing_country_code') === 'NO' ? 'selected' : '' }}>🇳🇴 {{ __('profile.countries.NO') }}</option>
                                    <option value="PL" {{ old('billing_country_code') === 'PL' ? 'selected' : '' }}>🇵🇱 {{ __('profile.countries.PL') }}</option>
                                    <option value="PT" {{ old('billing_country_code') === 'PT' ? 'selected' : '' }}>🇵🇹 {{ __('profile.countries.PT') }}</option>
                                    <option value="SE" {{ old('billing_country_code') === 'SE' ? 'selected' : '' }}>🇸🇪 {{ __('profile.countries.SE') }}</option>
                                </select>
                                <x-input-error class="mt-2" :messages="$errors->get('billing_country_code')" />
                            </div>

                            <div>
                                <x-input-label for="currency_preference" :value="__('profile.currency')" />
                                <select id="currency_preference" name="currency_preference"
                                        class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                        {{ !$user->hasVerifiedEmail() ? 'disabled' : '' }}>
                                    <option value="EUR" {{ old('currency_preference') === 'EUR' ? 'selected' : '' }}>{{ __('profile.currencies.EUR') }}</option>
                                    <option value="USD" {{ old('currency_preference') === 'USD' ? 'selected' : '' }}>{{ __('profile.currencies.USD') }}</option>
                                </select>
                                <x-input-error class="mt-2" :messages="$errors->get('currency_preference')" />
                            </div>

                            <div>
                                <x-input-label for="vat_number" :value="__('profile.vat_number_optional')" />
                                <x-text-input id="vat_number" name="vat_number" type="text" class="mt-1 block w-full"
                                             :value="old('vat_number')"
                                             :placeholder="__('profile.vat_number_placeholder')"
                                             :disabled="!$user->hasVerifiedEmail()" />
                                <p class="mt-1 text-sm text-gray-500">
                                    {{ __('profile.vat_number_help') }}
                                </p>
                                <x-input-error class="mt-2" :messages="$errors->get('vat_number')" />
                            </div>

                            <div class="flex items-center gap-4">
                                @if(!$user->hasVerifiedEmail())
                                    <button type="button" disabled
                                            class="inline-flex items-center px-4 py-2 bg-gray-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest cursor-not-allowed opacity-50">
                                        {{ __('profile.create_organization') }}
                                    </button>
                                    <p class="text-sm text-gray-500 italic">
                                        {{ __('profile.email_verification_required_short') }}
                                    </p>
                                @else
                                    <x-primary-button>{{ __('profile.create_organization') }}</x-primary-button>
                                @endif

                                @if (session('status') === 'organization-created')
                                    <p x-data="{ show: true }"
                                       x-show="show"
                                       x-transition
                                       x-init="setTimeout(() => show = false, 2000)"
                                       class="text-sm text-gray-600">
                                        {{ __('profile.organization_created_successfully') }}
                                    </p>
                                @endif

                                @if (session('error'))
                                    <p class="text-sm text-red-600">
                                        {{ session('error') }}
                                    </p>
                                @endif
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        </section>
    </div>
@endsection