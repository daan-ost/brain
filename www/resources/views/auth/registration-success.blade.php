@extends('layouts.auth-standalone')

@section('title', __('auth.registration_successful'))

@section('content')
<div class="w-full max-w-md">
    <!-- Logo -->
    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-white rounded-2xl shadow-lg mb-4">
            <span class="text-2xl font-bold text-[#53b3ae]">P</span>
        </div>
    </div>

    <!-- Success Card -->
    <div class="bg-white rounded-2xl shadow-2xl border-0 backdrop-blur-sm bg-white/95 p-8">
        <!-- Success Icon -->
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-green-100 rounded-full mb-4">
                <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 mb-2">{{ __('auth.welcome_to_app') }}</h1>
            <p class="text-gray-600">{{ __('auth.account_created_successfully') }}</p>
        </div>

        <!-- Email Confirmation Notice -->
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <svg class="w-5 h-5 text-yellow-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.996-.833-2.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-yellow-800">{{ __('auth.email_confirmation_required') }}</h3>
                    <div class="mt-2 text-sm text-yellow-700">
                        <p>{!! __('auth.confirmation_email_sent', ['email' => e($user->email)]) !!}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Current Status -->
        <div class="space-y-4 mb-6">
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-gray-700">{{ __('auth.account_status') }}</span>
                <span class="px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-800 rounded-full">
                    {{ __('auth.email_unconfirmed') }}
                </span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-gray-700">{{ __('auth.available_credits') }}</span>
                <span class="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-600 rounded-full">
                    {{ __('auth.credits_after_confirmation') }}
                </span>
            </div>
        </div>

        <!-- Actions -->
        <div class="space-y-3">
            <!-- Continue to Uploads (Limited) -->
            <a href="{{ route('uploads') }}"
               class="w-full flex items-center justify-center px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#53b3ae] transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                </svg>
                {{ __('auth.browse_upload_options') }}
            </a>

            <!-- Profile -->
            <a href="{{ route('profile.edit') }}"
               class="w-full flex items-center justify-center px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#53b3ae] transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                {{ __('auth.view_profile') }}
            </a>
        </div>

        <!-- Help Text -->
        <div class="mt-6 pt-6 border-t border-gray-200 text-center">
            <p class="text-xs text-gray-500">
                {{ __('auth.didnt_receive_resend') }}
                <a href="{{ route('profile.edit') }}" class="text-[#53b3ae] hover:text-[#45a49a] font-medium">{{ __('auth.resend_confirmation') }}</a>.
            </p>
        </div>
    </div>
</div>
@endsection
