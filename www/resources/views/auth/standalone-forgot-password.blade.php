@extends('layouts.auth-standalone')

@section('title', 'Forgot Password')

@section('content')
<div class="w-full max-w-md">
    <!-- Logo -->
    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-white rounded-2xl shadow-lg mb-4">
            <span class="text-2xl font-bold text-[#53b3ae]">P</span>
        </div>
    </div>

    <!-- Card -->
    <div class="bg-white rounded-2xl shadow-2xl border-0 backdrop-blur-sm bg-white/95 p-8">
        <!-- Header -->
        <div class="text-center pb-6">
            <h1 class="text-2xl font-bold text-gray-900 mb-2">Forgot your password?</h1>
            <p class="text-gray-600">No worries! Enter your email address and we'll send you a reset link.</p>
        </div>

        <!-- Form -->
        <div class="space-y-6">
            <form class="space-y-6">
                <!-- Email Address -->
                <div class="space-y-2">
                    <label for="email" class="block text-sm font-medium text-gray-700">
                        Email address
                    </label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        placeholder="Enter your email address"
                        required
                        class="w-full h-12 px-4 border border-gray-300 focus:border-[#2A73E8] focus:ring-[#2A73E8] rounded-lg outline-none transition-colors"
                    />
                </div>

                <!-- Submit Button -->
                <button
                    type="submit"
                    class="w-full h-12 bg-[#2A73E8] hover:bg-[#1557b0] text-white font-medium rounded-lg transition-colors shadow-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#2A73E8]"
                >
                    Send reset link
                </button>
            </form>

            <!-- Back to Login -->
            <div class="text-center pt-4 border-t border-gray-200">
                <a
                    href="./standalone-login.html"
                    class="inline-flex items-center space-x-2 text-sm text-gray-600 hover:text-[#2A73E8] transition-colors"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    <span>Back to login</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Support Contact -->
    <div class="text-center mt-6 text-white/80 text-sm">
        <p>Need help? Contact our support team at</p>
        <a href="mailto:{{ config('mail.from.address') }}" class="text-white hover:underline font-medium">
            {{ config('mail.from.address') }}
        </a>
    </div>
</div>

<!-- Success State (hidden by default, can be shown with JavaScript) -->
<div class="w-full max-w-md hidden" id="success-state">
    <!-- Logo -->
    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-white rounded-2xl shadow-lg mb-4">
            <span class="text-2xl font-bold text-[#53b3ae]">P</span>
        </div>
    </div>

    <!-- Card -->
    <div class="bg-white rounded-2xl shadow-2xl border-0 backdrop-blur-sm bg-white/95 p-8">
        <!-- Header -->
        <div class="text-center pb-6">
            <h1 class="text-2xl font-bold text-gray-900 mb-2">Check your email</h1>
            <p class="text-gray-600">We've sent a password reset link to your email address.</p>
        </div>

        <!-- Success Content -->
        <div class="space-y-6">
            <div class="text-center space-y-6">
                <div class="flex justify-center">
                    <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center">
                        <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                </div>

                <div class="p-4 bg-green-50 border border-green-200 rounded-lg">
                    <div class="flex items-center">
                        <svg class="w-4 h-4 text-green-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                        </svg>
                        <span class="text-green-800 text-sm">
                            Password reset link sent to <strong>your@email.com</strong>
                        </span>
                    </div>
                </div>

                <div class="text-sm text-gray-600">
                    <p>Didn't receive the email? Check your spam folder or</p>
                    <button onclick="showForm()" class="text-[#2A73E8] hover:underline font-medium">
                        try again
                    </button>
                </div>
            </div>

            <!-- Back to Login -->
            <div class="text-center pt-4 border-t border-gray-200">
                <a
                    href="./standalone-login.html"
                    class="inline-flex items-center space-x-2 text-sm text-gray-600 hover:text-[#2A73E8] transition-colors"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    <span>Back to login</span>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function showSuccessState() {
    document.querySelector('.w-full.max-w-md:not(#success-state)').classList.add('hidden');
    document.getElementById('success-state').classList.remove('hidden');
}

function showForm() {
    document.querySelector('.w-full.max-w-md:not(#success-state)').classList.remove('hidden');
    document.getElementById('success-state').classList.add('hidden');
}

// Demo: show success state when form is submitted
document.querySelector('form').addEventListener('submit', function(e) {
    e.preventDefault();
    showSuccessState();
});
</script>
@endsection
