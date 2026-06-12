@extends('layouts.profile')

@section('header')
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ __('profile.organization_transactions') }}
    </h2>
@endsection

@section('content')
    <div class="p-6">
        <section>
            <header>
                <h2 class="text-lg font-medium text-gray-900">
                    {{ __('profile.organization_transaction_history') }}
                </h2>

                <p class="mt-1 text-sm text-gray-600">
                    {{ __('profile.organization_transactions_description') }}
                </p>
            </header>

            @if($organizations->count() > 0)
                <!-- Tabs -->
                <div class="mt-6 border-b border-gray-200 overflow-x-auto">
                    <nav class="-mb-px flex space-x-4 sm:space-x-8" aria-label="Tabs">
                        <a href="{{ route('profile.organization.transactions', ['tab' => 'web', 'date_from' => $dateFrom ?? '', 'date_to' => $dateTo ?? '']) }}"
                           class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm {{ ($activeTab ?? 'web') === 'web' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                            <svg class="inline-block w-5 h-5 mr-2 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path>
                            </svg>
                            {{ __('profile.web_conversions') }}
                        </a>
                        <a href="{{ route('profile.organization.transactions', ['tab' => 'api', 'date_from' => $dateFrom ?? '', 'date_to' => $dateTo ?? '']) }}"
                           class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm {{ ($activeTab ?? 'web') === 'api' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                            <svg class="inline-block w-5 h-5 mr-2 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path>
                            </svg>
                            {{ __('profile.api_conversions') }}
                        </a>
                    </nav>
                </div>

                <!-- Filters -->
                <div class="mt-6 mb-4">
                    <form method="GET" class="flex flex-wrap gap-4 items-end">
                        <input type="hidden" name="tab" value="{{ $activeTab ?? 'web' }}">
                        <div>
                            <label for="date_from" class="block text-sm font-medium text-gray-700">{{ __('profile.from_date') }}</label>
                            <input type="date" id="date_from" name="date_from" value="{{ request('date_from', $dateFrom ?? '') }}"
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        </div>
                        <div>
                            <label for="date_to" class="block text-sm font-medium text-gray-700">{{ __('profile.to_date') }}</label>
                            <input type="date" id="date_to" name="date_to" value="{{ request('date_to', $dateTo ?? '') }}"
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        </div>
                        <div>
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                {{ __('profile.filter') }}
                            </button>
                        </div>
                        @if(request()->hasAny(['date_from', 'date_to']))
                            <div>
                                <a href="{{ route('profile.organization.transactions', ['tab' => $activeTab ?? 'web']) }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                    {{ __('profile.clear') }}
                                </a>
                            </div>
                        @endif
                    </form>
                </div>

                @if($transactions->count() > 0)
                    {{-- Mobile Cards --}}
                    <div class="md:hidden space-y-3">
                        @foreach($transactions as $transaction)
                            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                                @if(($activeTab ?? 'web') === 'api')
                                    {{-- API Tab Card --}}
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-sm font-medium text-gray-900">
                                            @if($transaction->workflow)
                                                {{ $transaction->workflow->name }}
                                            @else
                                                <span class="text-gray-400">-</span>
                                            @endif
                                        </span>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            @if($transaction->status === 'done') bg-green-100 text-green-800
                                            @elseif($transaction->status === 'processing') bg-yellow-100 text-yellow-800
                                            @elseif($transaction->status === 'error') bg-red-100 text-red-800
                                            @else bg-gray-100 text-gray-800
                                            @endif">
                                            {{ __('profile.status_' . $transaction->status) }}
                                        </span>
                                    </div>
                                    <div class="text-sm text-gray-500 space-y-1">
                                        @php
                                            $inputFiles = $transaction->input_files ?? [];
                                            $fileCount = is_array($inputFiles) ? count($inputFiles) : 0;
                                        @endphp
                                        @if($fileCount > 0)
                                            <div>{{ __('profile.files') }}: {{ trans_choice('profile.file_count', $fileCount, ['count' => $fileCount]) }}</div>
                                        @endif
                                        @if($transaction->user)
                                            <div>{{ __('profile.user') }}: {{ $transaction->user->name ?: $transaction->user->email }}</div>
                                        @endif
                                        <div class="flex items-center justify-between">
                                            <span class="local-time" data-timestamp="{{ $transaction->created_at->toISOString() }}">{{ format_datetime($transaction->created_at) }}</span>
                                            @if($transaction->source === 'api_v1')
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">v1</span>
                                            @elseif($transaction->source === 'api_v2')
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800">v2</span>
                                            @endif
                                        </div>
                                    </div>
                                @else
                                    {{-- Web Tab Card --}}
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-sm font-medium text-gray-900">
                                            @php
                                                $conversionType = null;
                                                $conversionTitle = null;
                                                $workflowName = null;
                                                $workflowId = null;
                                                if ($transaction->workflowExecution) {
                                                    $snapshot = $transaction->workflowExecution->execution_snapshot;
                                                    $workflowId = $snapshot['workflow']['id'] ?? null;
                                                    if ($workflowId && isset($snapshot['workflow']['name'])) {
                                                        $workflowName = $snapshot['workflow']['name'];
                                                    }
                                                    if (isset($snapshot['steps'][0]['type'])) {
                                                        $conversionType = $snapshot['steps'][0]['type'];
                                                        $conversionsConfig = config('conversions.conversions', []);
                                                        foreach ($conversionsConfig as $slug => $config) {
                                                            if (($config['conversion_type'] ?? null) === $conversionType) {
                                                                $conversionTitle = ucwords(str_replace(['_', '-'], ' ', $slug));
                                                                break;
                                                            }
                                                        }
                                                    }
                                                }
                                            @endphp
                                            @if($workflowId && $workflowName)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800 mr-1">{{ __('profile.workflow_badge') }}</span>
                                                {{ $workflowName }}
                                            @elseif($conversionTitle)
                                                {{ $conversionTitle }}
                                            @elseif($conversionType)
                                                {{ ucwords(str_replace('_', ' ', $conversionType)) }}
                                            @else
                                                <span class="text-gray-400">-</span>
                                            @endif
                                        </span>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            @if($transaction->status === 'done') bg-green-100 text-green-800
                                            @elseif($transaction->status === 'processing') bg-yellow-100 text-yellow-800
                                            @elseif($transaction->status === 'error') bg-red-100 text-red-800
                                            @else bg-gray-100 text-gray-800
                                            @endif">
                                            {{ __('profile.status_' . $transaction->status) }}
                                        </span>
                                    </div>
                                    <div class="text-sm text-gray-500 space-y-1">
                                        @if($transaction->documents_count)
                                            <div>{{ __('profile.files') }}: {{ trans_choice('profile.file_count', $transaction->documents_count, ['count' => $transaction->documents_count]) }}</div>
                                        @endif
                                        @if($transaction->user)
                                            <div>{{ __('profile.user') }}: {{ $transaction->user->name ?: $transaction->user->email }}</div>
                                        @endif
                                        @if($transaction->credits_spent)
                                            <div>{{ __('profile.credits') }}: {{ number_format($transaction->credits_spent) }}</div>
                                        @endif
                                        <div class="local-time" data-timestamp="{{ $transaction->created_at->toISOString() }}">{{ format_datetime($transaction->created_at) }}</div>
                                        @if($transaction->expires_at)
                                            <div>{{ __('profile.expires_at') }}: <span class="local-time" data-timestamp="{{ $transaction->expires_at->toISOString() }}">{{ format_datetime($transaction->expires_at) }}</span></div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach

                        {{-- Mobile Totals --}}
                        <div class="bg-gray-100 rounded-xl border border-gray-200 p-4">
                            <div class="text-sm font-semibold text-gray-900">
                                {{ __('profile.total_for_period') }}:
                                {{ number_format($totalTransactions ?? 0) }} {{ __('profile.transactions_count') }}
                                @if(($activeTab ?? 'web') !== 'api' && ($totalCredits ?? 0) > 0)
                                    &middot; {{ number_format($totalCredits) }} {{ __('profile.credits') }}
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Desktop Table --}}
                    <div class="hidden md:block overflow-x-auto shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
                        <table class="min-w-full divide-y divide-gray-300">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('profile.type_conversion') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('profile.files') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('profile.user') }}</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <a href="{{ request()->fullUrlWithQuery(['sort' => 'created_at', 'direction' => ($sortBy ?? 'created_at') === 'created_at' && ($sortDirection ?? 'desc') === 'asc' ? 'desc' : 'asc']) }}"
                                           class="group inline-flex items-center hover:text-gray-900">
                                            {{ __('profile.date') }}
                                            @if(($sortBy ?? 'created_at') === 'created_at')
                                                @if(($sortDirection ?? 'desc') === 'asc')
                                                    <svg class="ml-2 h-4 w-4 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z" clip-rule="evenodd"></path>
                                                    </svg>
                                                @else
                                                    <svg class="ml-2 h-4 w-4 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                                    </svg>
                                                @endif
                                            @endif
                                        </a>
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <a href="{{ request()->fullUrlWithQuery(['sort' => 'status', 'direction' => ($sortBy ?? 'created_at') === 'status' && ($sortDirection ?? 'desc') === 'asc' ? 'desc' : 'asc']) }}"
                                           class="group inline-flex items-center hover:text-gray-900">
                                            {{ __('profile.status') }}
                                            @if(($sortBy ?? 'created_at') === 'status')
                                                @if(($sortDirection ?? 'desc') === 'asc')
                                                    <svg class="ml-2 h-4 w-4 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z" clip-rule="evenodd"></path>
                                                    </svg>
                                                @else
                                                    <svg class="ml-2 h-4 w-4 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                                    </svg>
                                                @endif
                                            @endif
                                        </a>
                                    </th>
                                    @if(($activeTab ?? 'web') === 'api')
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('profile.api_version') }}</th>
                                    @else
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('profile.credits') }}</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'expires_at', 'direction' => ($sortBy ?? 'created_at') === 'expires_at' && ($sortDirection ?? 'desc') === 'asc' ? 'desc' : 'asc']) }}"
                                               class="group inline-flex items-center hover:text-gray-900">
                                                {{ __('profile.expires_at') }}
                                                @if(($sortBy ?? 'created_at') === 'expires_at')
                                                    @if(($sortDirection ?? 'desc') === 'asc')
                                                        <svg class="ml-2 h-4 w-4 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z" clip-rule="evenodd"></path>
                                                        </svg>
                                                    @else
                                                        <svg class="ml-2 h-4 w-4 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                                        </svg>
                                                    @endif
                                                @endif
                                            </a>
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('profile.file_info') }}</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($transactions as $transaction)
                                    <tr>
                                        @if(($activeTab ?? 'web') === 'api')
                                            {{-- API Tab: $transaction is WorkflowExecution --}}
                                            {{-- Type / Workflow Column --}}
                                            <td class="px-6 py-4 text-sm text-gray-900">
                                                @if($transaction->workflow)
                                                    <span class="text-gray-900">{{ $transaction->workflow->name }}</span>
                                                @else
                                                    <span class="text-gray-400">-</span>
                                                @endif
                                            </td>

                                            {{-- Files Column --}}
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                @php
                                                    $inputFiles = $transaction->input_files ?? [];
                                                    $fileCount = is_array($inputFiles) ? count($inputFiles) : 0;
                                                @endphp
                                                @if($fileCount > 0)
                                                    {{ trans_choice('profile.file_count', $fileCount, ['count' => $fileCount]) }}
                                                @else
                                                    <span class="text-gray-400">-</span>
                                                @endif
                                            </td>

                                            {{-- User Column --}}
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                @if($transaction->user)
                                                    <a href="{{ route('profile.organization.users') }}" class="text-indigo-600 hover:text-indigo-900 hover:underline">
                                                        {{ $transaction->user->name ?: $transaction->user->email }}
                                                    </a>
                                                @else
                                                    <span class="text-gray-400">-</span>
                                                @endif
                                            </td>

                                            {{-- Date Column --}}
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                <span class="local-time" data-timestamp="{{ $transaction->created_at->toISOString() }}">{{ format_datetime($transaction->created_at) }}</span>
                                            </td>

                                            {{-- Status Column --}}
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                                    @if($transaction->status === 'done') bg-green-100 text-green-800
                                                    @elseif($transaction->status === 'processing') bg-yellow-100 text-yellow-800
                                                    @elseif($transaction->status === 'error') bg-red-100 text-red-800
                                                    @else bg-gray-100 text-gray-800
                                                    @endif">
                                                    {{ __('profile.status_' . $transaction->status) }}
                                                </span>
                                            </td>

                                            {{-- API Version Column --}}
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                @if($transaction->source === 'api_v1')
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">v1</span>
                                                @elseif($transaction->source === 'api_v2')
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800">v2</span>
                                                @else
                                                    <span class="text-gray-400">-</span>
                                                @endif
                                            </td>
                                        @else
                                            {{-- Web Tab: $transaction is Batch --}}
                                            {{-- Type / Conversion Column --}}
                                            <td class="px-6 py-4 text-sm text-gray-900">
                                                @php
                                                    $conversionType = null;
                                                    $conversionTitle = null;
                                                    $workflowName = null;
                                                    $workflowId = null;

                                                    if ($transaction->workflowExecution) {
                                                        $snapshot = $transaction->workflowExecution->execution_snapshot;

                                                        // Get workflow ID first to determine if it's a real workflow
                                                        $workflowId = $snapshot['workflow']['id'] ?? null;

                                                        // Only consider it a workflow if ID is not null
                                                        if ($workflowId && isset($snapshot['workflow']['name'])) {
                                                            $workflowName = $snapshot['workflow']['name'];
                                                        }

                                                        // Get conversion type from first step
                                                        if (isset($snapshot['steps'][0]['type'])) {
                                                            $conversionType = $snapshot['steps'][0]['type'];

                                                            // Get user's language preference
                                                            $userLocale = auth()->user()->language ?? 'en';

                                                            // Build reverse mapping: conversion_type -> slug
                                                            $conversionsConfig = config('conversions.conversions', []);
                                                            $conversionSlug = null;

                                                            // Find the slug for this conversion_type
                                                            foreach ($conversionsConfig as $slug => $config) {
                                                                if (($config['conversion_type'] ?? null) === $conversionType) {
                                                                    $conversionSlug = $slug;
                                                                    break;
                                                                }
                                                            }

                                                            // Use formatted conversion type as title
                                                            if ($conversionSlug) {
                                                                $conversionTitle = ucwords(str_replace(['_', '-'], ' ', $conversionSlug));
                                                            }
                                                        }
                                                    }
                                                @endphp

                                                @if($workflowId && $workflowName)
                                                    <div class="flex items-center space-x-2">
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                                                            {{ __('profile.workflow_badge') }}
                                                        </span>
                                                        <span class="text-gray-900 font-medium">
                                                            {{ $workflowName }}
                                                        </span>
                                                    </div>
                                                @elseif($conversionTitle)
                                                    <span class="text-gray-900">
                                                        {{ $conversionTitle }}
                                                    </span>
                                                @elseif($conversionType)
                                                    {{-- Fallback: Show conversion type formatted --}}
                                                    <span class="text-gray-700">
                                                        {{ ucwords(str_replace('_', ' ', $conversionType)) }}
                                                    </span>
                                                @else
                                                    <span class="text-gray-400">-</span>
                                                @endif
                                            </td>

                                            {{-- Files Column --}}
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                @if($transaction->documents_count)
                                                    {{ trans_choice('profile.file_count', $transaction->documents_count, ['count' => $transaction->documents_count]) }}
                                                @else
                                                    <span class="text-gray-400">-</span>
                                                @endif
                                            </td>

                                            {{-- User Column --}}
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                @if($transaction->user)
                                                    <a href="{{ route('profile.organization.users') }}" class="text-indigo-600 hover:text-indigo-900 hover:underline">
                                                        {{ $transaction->user->name ?: $transaction->user->email }}
                                                    </a>
                                                @else
                                                    <span class="text-gray-400">-</span>
                                                @endif
                                            </td>

                                            {{-- Date Column --}}
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                <span class="local-time" data-timestamp="{{ $transaction->created_at->toISOString() }}">{{ format_datetime($transaction->created_at) }}</span>
                                            </td>

                                            {{-- Status Column --}}
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                                    @if($transaction->status === 'done') bg-green-100 text-green-800
                                                    @elseif($transaction->status === 'processing') bg-yellow-100 text-yellow-800
                                                    @elseif($transaction->status === 'error') bg-red-100 text-red-800
                                                    @else bg-gray-100 text-gray-800
                                                    @endif">
                                                    {{ __('profile.status_' . $transaction->status) }}
                                                </span>
                                            </td>

                                            {{-- Credits Column --}}
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                @if($transaction->credits_spent)
                                                    {{ number_format($transaction->credits_spent) }}
                                                @else
                                                    <span class="text-gray-400">-</span>
                                                @endif
                                            </td>

                                            {{-- Expires At Column --}}
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                @if($transaction->expires_at)
                                                    <span class="local-time" data-timestamp="{{ $transaction->expires_at->toISOString() }}">{{ format_datetime($transaction->expires_at) }}</span>
                                                @else
                                                    <span class="text-gray-400">-</span>
                                                @endif
                                            </td>

                                            {{-- File Info Column --}}
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                @if($transaction->status === 'done')
                                                    <div class="flex flex-col">
                                                        <span class="text-sm font-medium text-gray-900">.pdf</span>
                                                        @if($transaction->result_size)
                                                            <span class="text-xs text-gray-500">
                                                                @if($transaction->result_size > 1024 * 1024)
                                                                    {{ number_format($transaction->result_size / (1024 * 1024), 1) }} MB
                                                                @else
                                                                    {{ number_format($transaction->result_size / 1024, 1) }} KB
                                                                @endif
                                                            </span>
                                                        @endif
                                                    </div>
                                                @elseif($transaction->expires_at && $transaction->expires_at->isPast())
                                                    <span class="text-xs text-red-500">{{ __('profile.expired') }}</span>
                                                @else
                                                    <span class="text-xs text-gray-400">{{ __('profile.not_ready') }}</span>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-gray-100">
                                <tr class="border-t-2 border-gray-300">
                                    @if(($activeTab ?? 'web') === 'api')
                                        {{-- API tab: 6 columns (Type/Workflow, Files, User, Date, Status, API Version) --}}
                                        <td colspan="4" class="px-6 py-4 text-sm font-semibold text-gray-900 text-right">
                                            {{ __('profile.total_for_period') }}
                                        </td>
                                        <td colspan="2" class="px-6 py-4 text-sm font-semibold text-gray-900">
                                            {{ number_format($totalTransactions ?? 0) }} {{ __('profile.transactions_count') }}
                                        </td>
                                    @else
                                        {{-- Web tab: 8 columns (Type/Conversion, Files, User, Date, Status, Credits, Expires, File Info) --}}
                                        <td colspan="5" class="px-6 py-4 text-sm font-semibold text-gray-900 text-right">
                                            {{ __('profile.total_for_period') }}
                                        </td>
                                        <td class="px-6 py-4 text-sm font-semibold text-gray-900">
                                            {{ number_format($totalCredits ?? 0) }}
                                        </td>
                                        <td colspan="2" class="px-6 py-4 text-sm font-semibold text-gray-900">
                                            {{ number_format($totalTransactions ?? 0) }} {{ __('profile.transactions_count') }}
                                        </td>
                                    @endif
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="mt-6">
                        {{ $transactions->appends(request()->query())->links() }}
                    </div>
                @else
                    <div class="text-center py-12">
                        @if(($activeTab ?? 'web') === 'api')
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path>
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900">{{ __('profile.no_api_transactions') }}</h3>
                            <p class="mt-1 text-sm text-gray-500">{{ __('profile.no_api_transactions_description') }}</p>
                        @else
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900">{{ __('profile.no_organization_transactions') }}</h3>
                            <p class="mt-1 text-sm text-gray-500">{{ __('profile.no_transactions_for_organization') }}</p>
                        @endif
                    </div>
                @endif
            @else
                <div class="text-center py-12">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                    </svg>
                    <h3 class="mt-2 text-sm font-medium text-gray-900">{{ __('profile.no_organization_membership') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('profile.not_member_currently') }}</p>
                </div>
            @endif
        </section>
    </div>

    <script>
    // Convert timestamps to local time
    document.querySelectorAll('.local-time').forEach(el => {
        const timestamp = el.dataset.timestamp;
        if (timestamp) {
            const date = new Date(timestamp);
            el.textContent = date.toLocaleString(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
    });
    </script>
@endsection
