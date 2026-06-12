<div>
    {{-- Flash message --}}
    @if (session()->has('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-700" role="alert">
            {{ session('message') }}
        </div>
    @endif

    {{-- Breadcrumb --}}
    <nav class="mb-4 text-sm text-gray-500">
        <a href="{{ route('demo-items.index') }}" class="hover:text-emerald-600">Demo Items</a>
        <span class="mx-1">/</span>
        <span class="text-gray-900">{{ $demoItem->title }}</span>
    </nav>

    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h1 class="text-2xl font-bold text-gray-900">{{ $demoItem->title }}</h1>
        <div class="flex flex-col sm:flex-row gap-2">
            <a href="{{ route('demo-items.edit', $demoItem) }}" class="w-full sm:w-auto inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Edit
            </a>
            <button wire:click="delete" wire:confirm="Are you sure you want to delete this item?" class="w-full sm:w-auto inline-flex items-center justify-center rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                Delete
            </button>
        </div>
    </div>

    {{-- Tab Bar --}}
    <div x-data="{ activeTab: 'overview' }" class="rounded-lg bg-white shadow-sm border border-gray-200">
        <div class="overflow-x-auto -mx-4 px-4 sm:mx-0 sm:px-0 border-b border-gray-200">
            <div class="flex gap-1 min-w-max sm:min-w-0 px-2 sm:px-4" role="tablist">
                <button @click="activeTab = 'overview'" :class="activeTab === 'overview' ? 'border-emerald-500 text-emerald-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="px-3 py-3 text-sm font-medium border-b-2 whitespace-nowrap transition-colors" type="button" role="tab" id="tab-overview" aria-controls="panel-overview" :aria-selected="activeTab === 'overview'">
                    Overview
                </button>
                <button @click="activeTab = 'details'" :class="activeTab === 'details' ? 'border-emerald-500 text-emerald-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="px-3 py-3 text-sm font-medium border-b-2 whitespace-nowrap transition-colors" type="button" role="tab" id="tab-details" aria-controls="panel-details" :aria-selected="activeTab === 'details'">
                    Details
                </button>
                @if(count($demoItem->status->allowedTransitions()) > 0)
                <button @click="activeTab = 'status'" :class="activeTab === 'status' ? 'border-emerald-500 text-emerald-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="px-3 py-3 text-sm font-medium border-b-2 whitespace-nowrap transition-colors" type="button" role="tab" id="tab-status" aria-controls="panel-status" :aria-selected="activeTab === 'status'">
                    Status
                </button>
                @endif
            </div>
        </div>

        {{-- Overview Tab --}}
        <div x-show="activeTab === 'overview'" id="panel-overview" aria-labelledby="tab-overview" class="p-4 sm:p-6" role="tabpanel">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 sm:gap-6">
                <div>
                    <dt class="text-sm font-medium text-gray-500">Status</dt>
                    <dd class="mt-1">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                            @switch($demoItem->status->badgeColor())
                                @case('success') bg-green-100 text-green-800 @break
                                @case('info') bg-blue-100 text-blue-800 @break
                                @case('danger') bg-red-100 text-red-800 @break
                                @default bg-gray-100 text-gray-800
                            @endswitch
                        ">
                            {{ $demoItem->status->label() }}
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Priority</dt>
                    <dd class="mt-1">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                            @switch($demoItem->priority->badgeColor())
                                @case('warning') bg-yellow-100 text-yellow-800 @break
                                @case('danger') bg-red-100 text-red-800 @break
                                @case('info') bg-blue-100 text-blue-800 @break
                                @default bg-gray-100 text-gray-800
                            @endswitch
                        ">
                            {{ $demoItem->priority->label() }}
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Amount</dt>
                    <dd class="mt-1 text-sm text-gray-900">&euro;{{ number_format($demoItem->amount, 2) }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Due Date</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $demoItem->due_date?->format('d M Y') ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        {{-- Details Tab --}}
        <div x-show="activeTab === 'details'" x-cloak id="panel-details" aria-labelledby="tab-details" class="p-4 sm:p-6" role="tabpanel">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 sm:gap-6">
                <div>
                    <dt class="text-sm font-medium text-gray-500">Created</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $demoItem->created_at->format('d M Y H:i') }}</dd>
                </div>
                @if($demoItem->completed_at)
                <div>
                    <dt class="text-sm font-medium text-gray-500">Completed</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $demoItem->completed_at->format('d M Y H:i') }}</dd>
                </div>
                @endif
                @if($demoItem->description)
                <div class="sm:col-span-2">
                    <dt class="text-sm font-medium text-gray-500">Description</dt>
                    <dd class="mt-1 text-sm text-gray-900 whitespace-pre-line">{{ $demoItem->description }}</dd>
                </div>
                @endif
            </dl>
        </div>

        {{-- Status Tab --}}
        @if(count($demoItem->status->allowedTransitions()) > 0)
        <div x-show="activeTab === 'status'" x-cloak id="panel-status" aria-labelledby="tab-status" class="p-4 sm:p-6" role="tabpanel">
            <h3 class="text-sm font-medium text-gray-700 mb-3">Change Status</h3>
            <div class="flex flex-wrap gap-2">
                @foreach($demoItem->status->allowedTransitions() as $transition)
                    <button wire:click="transitionTo('{{ $transition->value }}')"
                        class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                        {{ $transition->label() }}
                    </button>
                @endforeach
            </div>
        </div>
        @endif
    </div>
</div>
