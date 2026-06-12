@extends('layouts.auth-standalone')

@section('title', __('You\'ve been invited!'))

@section('content')
<div class="w-full max-w-md">
    <!-- Logo -->
    <div class="text-center mb-8">
        <a href="{{ url('/') }}" class="inline-block">
            <img src="/favicon.svg" alt="{{ config('app.name') }} Logo" class="h-16 w-auto opacity-90 hover:opacity-100 transition-opacity">
        </a>
    </div>

    <!-- Card -->
    <div class="bg-white rounded-2xl shadow-2xl border-0 backdrop-blur-sm bg-white/95 p-8">
        @if(isset($error))
            <!-- Error State -->
            <div class="text-center">
                <div class="flex justify-center mb-4">
                    <div class="inline-flex items-center justify-center w-20 h-20 bg-red-100 rounded-full">
                        <svg class="w-10 h-10 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 mb-2">
                    {{ __('Invitation Invalid') }}
                </h1>
                <p class="text-gray-600 mb-6">
                    {{ $error }}
                </p>
                <a href="{{ route('profile.organization.users') }}" class="inline-flex items-center justify-center px-4 py-3 bg-[#2A73E8] hover:bg-[#1557b0] border border-transparent rounded-lg font-medium text-sm text-white transition-colors shadow-lg">
                    {{ __('Go to Dashboard') }}
                </a>
            </div>
        @else
            <!-- Valid Invitation -->
            <div class="text-center mb-6">
                <div class="flex justify-center mb-4">
                    <div class="inline-flex items-center justify-center w-20 h-20 bg-[#2A73E8]/10 rounded-full">
                        <svg class="w-10 h-10 text-[#2A73E8]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 19v-8.93a2 2 0 01.89-1.664l7-4.666a2 2 0 012.22 0l7 4.666A2 2 0 0121 10.07V19M3 19a2 2 0 002 2h14a2 2 0 002-2M3 19l6.75-4.5M21 19l-6.75-4.5M3 10l6.75 4.5M21 10l-6.75 4.5m0 0l-1.14.76a2 2 0 01-2.22 0l-1.14-.76"></path>
                        </svg>
                    </div>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 mb-2">
                    {{ __('You\'ve been invited!') }}
                </h1>
            </div>

            <div class="bg-gray-50 rounded-lg p-4 mb-6">
                <p class="text-sm text-gray-600 mb-2">
                    <strong>{{ $invitation->invitedBy->name }}</strong> {{ __('has invited you to join') }}
                </p>
                <p class="text-lg font-bold text-gray-900 mb-3">
                    {{ $invitation->organization->name }}
                </p>
                <div class="text-xs text-gray-500 space-y-1">
                    <p>{{ __('Role') }}: <span class="font-medium">{{ \App\Enums\OrganizationRole::from($invitation->role)->label() }}</span></p>
                    <p>{{ __('Expires') }}: <span class="font-medium">{{ format_date($invitation->expires_at) }}</span></p>
                </div>
            </div>

            @auth
                <!-- User is logged in -->
                @if(auth()->user()->email === $invitation->email)
                    <!-- Correct user -->
                    <form method="POST" action="{{ route('invitations.accept', $invitation->token) }}">
                        @csrf
                        <button type="submit" class="w-full h-12 bg-[#2A73E8] hover:bg-[#1557b0] text-white font-medium rounded-lg transition-colors shadow-lg">
                            {{ __('Accept Invitation') }}
                        </button>
                    </form>
                @else
                    <!-- Wrong user logged in -->
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                        <p class="text-sm text-yellow-800">
                            {{ __('This invitation was sent to') }} <strong>{{ $invitation->email }}</strong>.
                            {{ __('You are currently logged in as') }} <strong>{{ auth()->user()->email }}</strong>.
                        </p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full h-12 bg-gray-600 hover:bg-gray-700 text-white font-medium rounded-lg transition-colors">
                            {{ __('Logout and try again') }}
                        </button>
                    </form>
                @endif
            @else
                <!-- User is not logged in -->
                <div class="space-y-3">
                    <a href="{{ route('login') }}?invitation={{ $invitation->token }}" class="w-full h-12 flex items-center justify-center bg-[#2A73E8] hover:bg-[#1557b0] text-white font-medium rounded-lg transition-colors shadow-lg">
                        {{ __('Login to Accept') }}
                    </a>
                    <a href="{{ route('register') }}?invitation={{ $invitation->token }}" class="w-full h-12 flex items-center justify-center bg-gray-200 hover:bg-gray-300 text-gray-700 font-medium rounded-lg transition-colors">
                        {{ __('Create Account') }}
                    </a>
                </div>
                <p class="text-xs text-gray-500 text-center mt-4">
                    {{ __('If you don\'t have an account yet, create one to accept this invitation.') }}
                </p>
            @endauth
        @endif
    </div>

    <!-- Back to Homepage -->
    <div class="text-center mt-8">
        <a href="{{ url('/') }}" class="text-white/80 hover:text-white text-sm font-medium">
            ← Terug naar homepage
        </a>
    </div>
</div>
@endsection
