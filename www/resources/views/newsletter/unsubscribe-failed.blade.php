@extends('layouts.auth-standalone')

@section('title', __('newsletter.unsubscribe_failed_title'))

@section('content')
<div class="bg-white rounded-xl shadow-lg max-w-md w-full p-8 text-center">
    <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100 mb-6">
        <svg class="h-8 w-8 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
    </div>

    <h1 class="text-2xl font-semibold text-gray-900 mb-4">
        {{ __('newsletter.unsubscribe_failed_title') }}
    </h1>

    <p class="text-gray-600 mb-6">
        @if($reason === 'invalid_token')
            {{ __('newsletter.unsubscribe_invalid_token') }}
        @else
            {{ __('newsletter.unsubscribe_generic_error') }}
        @endif
    </p>

    <p class="text-sm text-gray-500 mb-8">
        {{ __('newsletter.unsubscribe_help_note') }}
    </p>

    <a href="{{ route('home') }}" class="inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-[#53b3ae] hover:bg-[#429c97] transition-colors duration-150">
        {{ __('newsletter.back_to_home') }}
    </a>
</div>
@endsection
