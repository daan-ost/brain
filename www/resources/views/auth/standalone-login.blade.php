@extends('layouts.auth-standalone')

@section('title', 'Login')

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
            <h1 class="text-2xl font-bold text-gray-900 mb-2">Welcome back</h1>
            <p class="text-gray-600">Sign in to your account to continue</p>
        </div>

        <!-- Form -->
        <div class="space-y-6">
            <form class="space-y-4">
                <!-- Email Address -->
                <div class="space-y-2">
                    <label for="email" class="block text-sm font-medium text-gray-700">
                        Email address
                    </label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        placeholder="Enter your email"
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
                        placeholder="Enter your password"
                        required
                        class="w-full h-12 px-4 border border-gray-200 focus:border-[#2A73E8] focus:ring-[#2A73E8] rounded-lg outline-none transition-colors"
                    />
                </div>

                <!-- Remember Me and Forgot Password -->
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-2">
                        <input
                            id="remember_me"
                            type="checkbox"
                            name="remember"
                            class="w-4 h-4 text-[#2A73E8] border-gray-300 rounded focus:ring-[#2A73E8]"
                        />
                        <label for="remember_me" class="text-sm text-gray-600">
                            Remember me
                        </label>
                    </div>
                    <a href="./standalone-forgot-password.html" class="text-sm text-[#2A73E8] hover:text-[#1557b0] font-medium">
                        Forgot password?
                    </a>
                </div>

                <!-- Submit Button -->
                <button
                    type="submit"
                    class="w-full h-12 bg-[#2A73E8] hover:bg-[#1557b0] text-white font-medium rounded-lg transition-colors shadow-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#2A73E8]"
                >
                    Sign in
                </button>
            </form>

            <!-- Register Link -->
            <div class="text-center">
                <span class="text-sm text-gray-600">
                    Don't have an account?
                    <a href="./standalone-register.html" class="text-[#2A73E8] hover:text-[#1557b0] font-medium">Sign up</a>
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
