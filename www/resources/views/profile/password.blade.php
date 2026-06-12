@extends('layouts.profile')

@section('header')
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ __('profile.password') }}
    </h2>
@endsection

@section('content')
    <div class="p-6">
        @include('profile.partials.update-password-form')
    </div>

    <div class="p-6 border-t border-gray-200">
        @livewire('profile.two-factor-manager')
    </div>

    <div class="p-6 border-t border-gray-200">
        @livewire('profile.connected-accounts')
    </div>
@endsection