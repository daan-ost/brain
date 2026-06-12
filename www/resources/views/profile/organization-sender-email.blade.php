@extends('layouts.profile')

@section('header')
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ __('profile.sender_email_title') }}
    </h2>
@endsection

@section('content')
    <livewire:organization.sender-email-settings />
@endsection
