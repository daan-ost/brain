@extends('layouts.auth-standalone')

@section('title', __('auth.login_code_verify_title'))

@section('content')
<div class="w-full max-w-md">
    <!-- Logo -->
    <div class="text-center mb-8">
        <a href="{{ url('/') }}" class="inline-block">
            <img src="/favicon.svg" alt="{{ config('app.name') }} Logo" class="h-16 w-auto opacity-90 hover:opacity-100 transition-opacity">
        </a>
    </div>

    <div class="bg-white rounded-2xl shadow-2xl border-0 backdrop-blur-sm bg-white/95 p-8">
        <div class="text-center pb-6">
            <h1 class="text-2xl font-bold text-gray-900 mb-2">{{ __('auth.login_code_verify_title') }}</h1>
            <p id="login-code-verify-subtitle" class="text-gray-600">{{ __('auth.login_code_verify_subtitle', ['email' => $email]) }}</p>
        </div>

        @if (session('status') === 'login-code-sent')
            <div role="status" class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
                {{ __('auth.login_code_sent_status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('login.code.verify.submit') }}" class="space-y-4">
            @csrf

            <input type="hidden" name="email" value="{{ $email }}">

            <div class="space-y-2">
                <label for="code" class="block text-sm font-medium text-gray-700">
                    {{ __('auth.login_code_label') }}
                </label>
                <input
                    id="code"
                    name="code"
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    autocomplete="one-time-code"
                    autofocus
                    required
                    aria-describedby="login-code-verify-subtitle{{ $errors->has('code') ? ' code-error' : '' }}"
                    @if ($errors->has('code')) aria-invalid="true" @endif
                    class="w-full h-14 px-4 text-center text-2xl tracking-[0.5em] font-mono border border-gray-200 focus:border-[#2A73E8] focus:ring-2 focus:ring-[#2A73E8] focus:ring-offset-1 rounded-lg outline-none transition-colors"
                >
                @error('code')
                    <p id="code-error" role="alert" class="text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="w-full h-12 bg-[#2A73E8] hover:bg-[#1f5fc4] text-white font-semibold rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-[#2A73E8] focus:ring-offset-2"
            >
                {{ __('auth.login') }}
            </button>
        </form>

        <div class="mt-6 flex justify-between text-sm text-gray-600">
            <a href="{{ route('login.code') }}" class="text-[#2A73E8] underline hover:text-[#1f5fc4] focus:outline-none focus:ring-2 focus:ring-[#2A73E8] focus:ring-offset-2 rounded">
                {{ __('auth.login_code_resend') }}
            </a>
            <a href="{{ route('login') }}" class="text-[#2A73E8] underline hover:text-[#1f5fc4] focus:outline-none focus:ring-2 focus:ring-[#2A73E8] focus:ring-offset-2 rounded">
                {{ __('auth.back_to_login') }}
            </a>
        </div>
    </div>
</div>
@endsection
