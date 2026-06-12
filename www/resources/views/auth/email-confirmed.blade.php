@extends('layouts.auth-standalone')

@section('title', __('auth.email_confirmed'))

@section('content')
<div class="w-full max-w-md">
    <!-- Logo -->
    <div class="text-center mb-8">
        <a href="{{ url('/') }}" class="inline-block">
            <img src="/favicon.svg" alt="{{ config('app.name') }} Logo" class="h-16 w-auto opacity-90 hover:opacity-100 transition-opacity">
        </a>
    </div>

    <!-- Success Card -->
    <div class="bg-white rounded-2xl shadow-2xl border-0 backdrop-blur-sm bg-white/95 p-8">
        <!-- Success Icon -->
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-20 h-20 bg-green-100 rounded-full mb-4">
                <svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 mb-2">{{ __('auth.email_confirmed') }}</h1>
            <p class="text-gray-600">{{ __('auth.email_confirmed_message') }}</p>
        </div>

        <!-- Organization Invitation Accepted -->
        @if($acceptedInvitation)
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <svg class="w-5 h-5 text-blue-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-blue-800">{{ __('auth.organization_invitation_accepted') }}</h3>
                    <div class="mt-2 text-sm text-blue-700">
                        <p>{!! __('auth.organization_invitation_accepted_message', ['organization' => e($acceptedInvitation->organization->name), 'role' => __('profile.role_' . $acceptedInvitation->role)]) !!}</p>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <!-- Auto-Enrolled Organizations -->
        @if($autoEnrolledOrganizations->isNotEmpty())
        <div class="bg-purple-50 border border-purple-200 rounded-lg p-4 mb-6">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <svg class="w-5 h-5 text-purple-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-purple-800">{{ __('auth.auto_enrolled_title') }}</h3>
                    <div class="mt-2 text-sm text-purple-700">
                        <p class="mb-2">{{ __('auth.auto_enrolled_message') }}</p>
                        <ul class="list-disc list-inside space-y-1">
                            @foreach($autoEnrolledOrganizations as $organization)
                            <li><strong>{{ $organization->name }}</strong></li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <!-- License Assignment Success -->
        <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <svg class="w-5 h-5 text-green-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-green-800">{{ __('auth.free_credits_added') }}</h3>
                    <div class="mt-2 text-sm text-green-700">
                        <p>{!! __('auth.free_credits_message') !!}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Account Status -->
        <div class="space-y-4 mb-6">
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-gray-700">{{ __('auth.account_status') }}</span>
                <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded-full">
                    {{ __('auth.confirmed') }}
                </span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-gray-700">{{ __('auth.available_credits') }}</span>
                <span class="px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded-full">
                    {{ __('auth.credits_count', ['count' => 15]) }}
                </span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-gray-700">{{ __('auth.license_type') }}</span>
                <span class="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-600 rounded-full">
                    {{ __('auth.free_tier') }}
                </span>
            </div>
        </div>

        <!-- Actions -->
        <div class="space-y-3">
            <!-- Go to Dashboard -->
            <a href="{{ route('dashboard') }}"
               class="w-full flex items-center justify-center px-4 py-3 border border-transparent rounded-lg text-sm font-medium text-white bg-[#53b3ae] hover:bg-[#45a49a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#53b3ae] transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                {{ __('auth.go_to_dashboard') }}
            </a>

            <!-- View Profile -->
            <a href="{{ route('profile.edit') }}"
               class="w-full flex items-center justify-center px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#53b3ae] transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                {{ __('auth.view_profile') }}
            </a>
        </div>

        <!-- Welcome Message -->
        <div class="mt-6 pt-6 border-t border-gray-200 text-center">
            <p class="text-sm text-gray-600">
                {!! __('auth.welcome_message', ['name' => e($user->name)]) !!}
                <br>{{ __('auth.ready_to_convert') }}
            </p>
        </div>
    </div>
</div>
@endsection