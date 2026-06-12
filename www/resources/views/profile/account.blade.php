@extends('layouts.profile')

@section('header')
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ __('profile.my_account') }}
    </h2>
@endsection

@section('content')
    <div x-data="{
        activeSection: 'profile-information',
        init() {
            const sections = ['profile-information', 'regional-settings', 'delete-account'];
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        this.activeSection = entry.target.id;
                    }
                });
            }, {
                rootMargin: '-20% 0px -60% 0px'
            });
            sections.forEach(id => {
                const el = document.getElementById(id);
                if (el) observer.observe(el);
            });
        },
        scrollTo(id) {
            this.activeSection = id;
            document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });
        }
    }">
        {{-- Anchor Navigation --}}
        <nav class="sticky top-0 z-10 bg-white border-b border-gray-200 rounded-t-lg px-6 py-3 flex gap-2 overflow-x-auto">
            <button
                @click="scrollTo('profile-information')"
                :class="activeSection === 'profile-information'
                    ? 'bg-indigo-100 text-indigo-700'
                    : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'"
                class="px-3 py-1.5 text-sm font-medium rounded-md transition-colors duration-200 whitespace-nowrap"
            >
                {{ __('profile.profile_information') }}
            </button>
            <button
                @click="scrollTo('regional-settings')"
                :class="activeSection === 'regional-settings'
                    ? 'bg-indigo-100 text-indigo-700'
                    : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'"
                class="px-3 py-1.5 text-sm font-medium rounded-md transition-colors duration-200 whitespace-nowrap"
            >
                {{ __('profile.localization_title') }}
            </button>
            <button
                @click="scrollTo('delete-account')"
                :class="activeSection === 'delete-account'
                    ? 'bg-indigo-100 text-indigo-700'
                    : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'"
                class="px-3 py-1.5 text-sm font-medium rounded-md transition-colors duration-200 whitespace-nowrap"
            >
                {{ __('profile.delete_account') }}
            </button>
        </nav>

        <div class="p-6 space-y-6">
            @include('profile.partials.update-profile-information-form')

            <div class="border-t pt-6">
                @livewire('profile.localization-settings')
            </div>

            <div class="border-t pt-6">
                @include('profile.partials.delete-user-form')
            </div>
        </div>
    </div>
@endsection
