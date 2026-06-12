@extends('layouts.app')

@section('content')
<div class="min-h-screen">
    {{-- Header --}}
    <div class="masthead py-24 sm:py-32">
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <h1 class="text-4xl font-bold tracking-tight text-white sm:text-6xl">403</h1>
                <p class="mt-6 text-lg leading-8 text-white">
                    Access denied.
                </p>
            </div>
        </div>
    </div>

    {{-- Error Content --}}
    <section class="content-section py-24 sm:py-32">
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-base leading-7 text-gray-600">You don't have permission to access this page.</p>
                <div class="mt-10 flex items-center justify-center gap-x-6">
                    <a href="/" class="btn-primary rounded-md px-3.5 py-2.5 text-sm font-semibold shadow-sm hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                        Go back home
                    </a>
                </div>
            </div>
        </div>
    </section>

    @include('components.footer')
</div>
@endsection
