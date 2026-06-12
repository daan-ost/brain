@extends('layouts.auth-standalone')

@section('title', 'Register')

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
            <h1 class="text-2xl font-bold text-gray-900 mb-2">Create your account</h1>
            <p class="text-gray-600">Get started with your free {{ config('app.name') }} account</p>
        </div>

        <!-- Form -->
        <div class="space-y-6">
            <form class="space-y-4">
                <!-- First Name and Last Name -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label for="first_name" class="block text-sm font-medium text-gray-700">
                            First name
                        </label>
                        <input
                            id="first_name"
                            name="first_name"
                            type="text"
                            placeholder="John"
                            required
                            class="w-full h-12 px-4 border border-gray-200 focus:border-[#2A73E8] focus:ring-[#2A73E8] rounded-lg outline-none transition-colors"
                        />
                    </div>
                    <div class="space-y-2">
                        <label for="last_name" class="block text-sm font-medium text-gray-700">
                            Last name
                        </label>
                        <input
                            id="last_name"
                            name="last_name"
                            type="text"
                            placeholder="Doe"
                            required
                            class="w-full h-12 px-4 border border-gray-200 focus:border-[#2A73E8] focus:ring-[#2A73E8] rounded-lg outline-none transition-colors"
                        />
                    </div>
                </div>

                <!-- Email Address -->
                <div class="space-y-2">
                    <label for="email" class="block text-sm font-medium text-gray-700">
                        Email address
                    </label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        placeholder="john@example.com"
                        required
                        class="w-full h-12 px-4 border border-gray-200 focus:border-[#2A73E8] focus:ring-[#2A73E8] rounded-lg outline-none transition-colors"
                    />
                </div>

                <!-- Password -->
                <div class="space-y-2">
                    <label for="password" class="block text-sm font-medium text-gray-700">
                        Password
                    </label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        placeholder="Create a strong password"
                        required
                        class="w-full h-12 px-4 border border-gray-200 focus:border-[#2A73E8] focus:ring-[#2A73E8] rounded-lg outline-none transition-colors"
                    />
                </div>

                <!-- Confirm Password -->
                <div class="space-y-2">
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700">
                        Confirm password
                    </label>
                    <input
                        id="password_confirmation"
                        name="password_confirmation"
                        type="password"
                        placeholder="Confirm your password"
                        required
                        class="w-full h-12 px-4 border border-gray-200 focus:border-[#2A73E8] focus:ring-[#2A73E8] rounded-lg outline-none transition-colors"
                    />
                </div>

                <!-- Terms and Privacy -->
                <div class="flex items-start space-x-2">
                    <input
                        id="terms"
                        type="checkbox"
                        name="terms"
                        required
                        class="w-4 h-4 text-[#2A73E8] border-gray-300 rounded focus:ring-[#2A73E8] mt-1"
                    />
                    <label for="terms" class="text-sm text-gray-600 leading-relaxed">
                        I agree to the
                        <a href="#" class="text-[#2A73E8] hover:text-[#1557b0] font-medium">Terms of Service</a>
                        and
                        <a href="#" class="text-[#2A73E8] hover:text-[#1557b0] font-medium">Privacy Policy</a>
                    </label>
                </div>

                <!-- Submit Button -->
                <button
                    type="submit"
                    class="w-full h-12 bg-[#2A73E8] hover:bg-[#1557b0] text-white font-medium rounded-lg transition-colors shadow-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#2A73E8]"
                >
                    Create account
                </button>
            </form>

            <!-- Login Link -->
            <div class="text-center">
                <span class="text-sm text-gray-600">
                    Already have an account?
                    <a href="./standalone-login.html" class="text-[#2A73E8] hover:text-[#1557b0] font-medium">Sign in</a>
                </span>
            </div>
        </div>
    </div>

    <!-- Back to Homepage -->
    <div class="text-center mt-8">
        <a href="#" class="text-white/80 hover:text-white text-sm font-medium">
            ← Back to homepage
        </a>
    </div>
</div>
@endsection
