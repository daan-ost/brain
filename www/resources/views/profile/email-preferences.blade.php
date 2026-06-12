@extends('layouts.profile')

@section('header')
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ __('profile.email_preferences') }}
    </h2>
@endsection

@section('content')
    <div class="p-6">
        <!-- Newsletter Section -->
        <section>
            @livewire('profile.newsletter-preferences')
        </section>

        @if(config('inbound.enabled'))
            <!-- Inbound Email Section -->
            <section class="mt-8">
                @livewire('profile.inbound-email-preferences')
            </section>
        @endif
    </div>
@endsection
