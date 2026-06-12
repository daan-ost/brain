<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Refresh Button --}}
        <div class="flex justify-end gap-2">
            <x-filament::button wire:click="refresh" icon="heroicon-o-arrow-path" color="gray">
                Refresh
            </x-filament::button>
        </div>

        {{-- Stats Cards --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <x-filament::section>
                <div class="text-center">
                    <div class="text-3xl font-bold {{ $stats['pending'] > 100 ? 'text-warning-600' : 'text-gray-900 dark:text-gray-100' }}">
                        {{ number_format($stats['pending']) }}
                    </div>
                    <div class="text-sm text-gray-500">Pending Jobs</div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-center">
                    <div class="text-3xl font-bold {{ $stats['failed'] > 0 ? 'text-danger-600' : 'text-success-600' }}">
                        {{ number_format($stats['failed']) }}
                    </div>
                    <div class="text-sm text-gray-500">Failed Jobs</div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-center">
                    <div class="text-3xl font-bold text-primary-600">
                        {{ number_format($stats['jobs_per_hour']) }}
                    </div>
                    <div class="text-sm text-gray-500">Jobs/Hour</div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-center">
                    <div class="text-3xl font-bold text-gray-900 dark:text-gray-100">
                        {{ $stats['avg_wait_time'] }}
                    </div>
                    <div class="text-sm text-gray-500">Avg Wait Time</div>
                </div>
            </x-filament::section>
        </div>

        {{-- Batches Stats --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-squares-2x2 class="w-5 h-5" />
                    Batch Statistics
                </div>
            </x-slot>
            <div class="grid grid-cols-4 gap-4 text-center">
                <div>
                    <div class="text-2xl font-bold">{{ number_format($stats['batches_total']) }}</div>
                    <div class="text-sm text-gray-500">Total</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-warning-600">{{ number_format($stats['batches_pending']) }}</div>
                    <div class="text-sm text-gray-500">Pending</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-success-600">{{ number_format($stats['batches_completed']) }}</div>
                    <div class="text-sm text-gray-500">Completed</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-danger-600">{{ number_format($stats['batches_failed']) }}</div>
                    <div class="text-sm text-gray-500">Failed</div>
                </div>
            </div>
        </x-filament::section>

        {{-- Tabs --}}
        <div class="border-b border-gray-200 dark:border-gray-700">
            <nav class="-mb-px flex space-x-8">
                <button
                    wire:click="setActiveTab('pending')"
                    class="py-4 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'pending' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
                >
                    Pending Jobs ({{ $stats['pending'] }})
                </button>
                <button
                    wire:click="setActiveTab('failed')"
                    class="py-4 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'failed' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
                >
                    Failed Jobs ({{ $stats['failed'] }})
                </button>
                <button
                    wire:click="setActiveTab('batches')"
                    class="py-4 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'batches' ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
                >
                    Batches ({{ $stats['batches_total'] }})
                </button>
            </nav>
        </div>

        {{-- Tab Content --}}
        @if($activeTab === 'pending')
            <x-filament::section>
                <x-slot name="heading">Pending Jobs</x-slot>
                @php $pendingJobs = $this->getPendingJobs(); @endphp
                @if(count($pendingJobs) > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-700">
                                    <th class="text-left py-2 px-3">Job</th>
                                    <th class="text-left py-2 px-3">Queue</th>
                                    <th class="text-left py-2 px-3">Attempts</th>
                                    <th class="text-left py-2 px-3">Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($pendingJobs as $job)
                                    <tr class="border-b border-gray-100 dark:border-gray-800">
                                        <td class="py-2 px-3 font-medium">{{ $job['job_name'] }}</td>
                                        <td class="py-2 px-3">
                                            <span class="px-2 py-1 text-xs rounded-full bg-gray-100 dark:bg-gray-800">
                                                {{ $job['queue'] }}
                                            </span>
                                        </td>
                                        <td class="py-2 px-3">{{ $job['attempts'] }}</td>
                                        <td class="py-2 px-3 text-gray-500">{{ $job['created_at'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-center py-8 text-gray-500">
                        <x-heroicon-o-check-circle class="w-12 h-12 mx-auto mb-2 text-success-500" />
                        No pending jobs
                    </div>
                @endif
            </x-filament::section>
        @endif

        @if($activeTab === 'failed')
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center justify-between w-full">
                        <span>Failed Jobs</span>
                        @if($stats['failed'] > 0)
                            <div class="flex gap-2">
                                <x-filament::button wire:click="retryAllFailed" size="sm" color="warning">
                                    Retry All
                                </x-filament::button>
                                <x-filament::button wire:click="clearAllFailed" size="sm" color="danger">
                                    Clear All
                                </x-filament::button>
                            </div>
                        @endif
                    </div>
                </x-slot>
                @php $failedJobs = $this->getFailedJobs(); @endphp
                @if(count($failedJobs) > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-700">
                                    <th class="text-left py-2 px-3">Job</th>
                                    <th class="text-left py-2 px-3">Queue</th>
                                    <th class="text-left py-2 px-3">Exception</th>
                                    <th class="text-left py-2 px-3">Failed</th>
                                    <th class="text-left py-2 px-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($failedJobs as $job)
                                    <tr class="border-b border-gray-100 dark:border-gray-800">
                                        <td class="py-2 px-3 font-medium">{{ $job['job_name'] }}</td>
                                        <td class="py-2 px-3">
                                            <span class="px-2 py-1 text-xs rounded-full bg-gray-100 dark:bg-gray-800">
                                                {{ $job['queue'] }}
                                            </span>
                                        </td>
                                        <td class="py-2 px-3 text-danger-600 text-xs max-w-xs truncate" title="{{ $job['exception'] }}">
                                            {{ $job['exception'] }}
                                        </td>
                                        <td class="py-2 px-3 text-gray-500">{{ $job['failed_at'] }}</td>
                                        <td class="py-2 px-3">
                                            <div class="flex gap-1">
                                                <x-filament::icon-button
                                                    wire:click="retryFailedJob({{ $job['id'] }})"
                                                    icon="heroicon-o-arrow-path"
                                                    color="warning"
                                                    size="sm"
                                                    tooltip="Retry"
                                                />
                                                <x-filament::icon-button
                                                    wire:click="deleteFailedJob({{ $job['id'] }})"
                                                    icon="heroicon-o-trash"
                                                    color="danger"
                                                    size="sm"
                                                    tooltip="Delete"
                                                />
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-center py-8 text-gray-500">
                        <x-heroicon-o-check-circle class="w-12 h-12 mx-auto mb-2 text-success-500" />
                        No failed jobs
                    </div>
                @endif
            </x-filament::section>
        @endif

        @if($activeTab === 'batches')
            <x-filament::section>
                <x-slot name="heading">Batches</x-slot>
                @php $batches = $this->getBatches(); @endphp
                @if(count($batches) > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-700">
                                    <th class="text-left py-2 px-3">Name</th>
                                    <th class="text-left py-2 px-3">Progress</th>
                                    <th class="text-left py-2 px-3">Jobs</th>
                                    <th class="text-left py-2 px-3">Status</th>
                                    <th class="text-left py-2 px-3">Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($batches as $batch)
                                    <tr class="border-b border-gray-100 dark:border-gray-800">
                                        <td class="py-2 px-3 font-medium">{{ $batch['name'] }}</td>
                                        <td class="py-2 px-3">
                                            <div class="flex items-center gap-2">
                                                <div class="w-24 bg-gray-200 rounded-full h-2">
                                                    <div class="h-2 rounded-full {{ $batch['status'] === 'failed' ? 'bg-danger-600' : ($batch['status'] === 'completed' ? 'bg-success-600' : 'bg-primary-600') }}"
                                                         style="width: {{ $batch['progress'] }}%"></div>
                                                </div>
                                                <span class="text-xs">{{ $batch['progress'] }}%</span>
                                            </div>
                                        </td>
                                        <td class="py-2 px-3">
                                            {{ $batch['total_jobs'] - $batch['pending_jobs'] }}/{{ $batch['total_jobs'] }}
                                            @if($batch['failed_jobs'] > 0)
                                                <span class="text-danger-600">({{ $batch['failed_jobs'] }} failed)</span>
                                            @endif
                                        </td>
                                        <td class="py-2 px-3">
                                            <span class="px-2 py-1 text-xs rounded-full
                                                {{ $batch['status'] === 'completed' ? 'bg-success-100 text-success-800' : '' }}
                                                {{ $batch['status'] === 'pending' ? 'bg-warning-100 text-warning-800' : '' }}
                                                {{ $batch['status'] === 'failed' ? 'bg-danger-100 text-danger-800' : '' }}
                                                {{ $batch['status'] === 'cancelled' ? 'bg-gray-100 text-gray-800' : '' }}
                                            ">
                                                {{ ucfirst($batch['status']) }}
                                            </span>
                                        </td>
                                        <td class="py-2 px-3 text-gray-500">{{ $batch['created_at'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-center py-8 text-gray-500">
                        No batches found
                    </div>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
