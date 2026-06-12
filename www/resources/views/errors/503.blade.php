@extends('layouts.app')

@section('content')
<div class="min-h-screen">
    {{-- Header --}}
    <div class="masthead py-24 sm:py-32">
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <h1 class="text-4xl font-bold tracking-tight text-white sm:text-6xl">Maintenance</h1>
                <p class="mt-6 text-lg leading-8 text-white">
                    We're currently performing maintenance.
                </p>
            </div>
        </div>
    </div>

    {{-- Error Content --}}
    <section class="content-section py-24 sm:py-32">
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-base leading-7 text-gray-600">We'll be back shortly. Thank you for your patience.</p>
            </div>
        </div>
    </section>

    @include('components.footer')
</div>
@endsection
