@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-6">
            <nav class="text-sm text-gray-500">
                <a href="{{ route('demo-items.index') }}" class="hover:text-emerald-600">Demo Items</a>
                <span class="mx-1">/</span>
                <span class="text-gray-900">Edit</span>
            </nav>
            <h1 class="mt-2 text-2xl font-bold text-gray-900">Edit Demo Item</h1>
        </div>
        <div class="rounded-lg bg-white shadow-sm border border-gray-200 p-6">
            @livewire('demo-items.edit-form', ['demoItem' => $demoItem])
        </div>
    </div>
</div>
@endsection
