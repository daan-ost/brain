<div class="bg-white overflow-hidden shadow-sm rounded-lg">
    <div class="p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-gray-900">Demo Items</h2>
            <a href="{{ route('demo-items.index') }}" class="text-sm text-emerald-600 hover:text-emerald-800">View all &rarr;</a>
        </div>

        {{-- Stats --}}
        <div class="grid grid-cols-3 gap-4 mb-4">
            <div class="text-center">
                <p class="text-2xl font-bold text-gray-900">{{ $summary['total'] }}</p>
                <p class="text-xs text-gray-500">Total</p>
            </div>
            <div class="text-center">
                <p class="text-2xl font-bold text-emerald-600">{{ $summary['active'] }}</p>
                <p class="text-xs text-gray-500">Active</p>
            </div>
            <div class="text-center">
                <p class="text-2xl font-bold text-red-600">{{ $summary['overdue'] }}</p>
                <p class="text-xs text-gray-500">Overdue</p>
            </div>
        </div>

        {{-- Recent items --}}
        @if($recentItems->isNotEmpty())
            <div class="border-t border-gray-100 pt-4">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-400 mb-2">Recent Items</p>
                <ul class="space-y-2">
                    @foreach($recentItems as $item)
                        <li class="flex items-center justify-between">
                            <a href="{{ route('demo-items.show', $item) }}" class="text-sm text-gray-700 hover:text-emerald-600 truncate">
                                {{ $item->title }}
                            </a>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                @switch($item->status->badgeColor())
                                    @case('success') bg-green-100 text-green-800 @break
                                    @case('info') bg-blue-100 text-blue-800 @break
                                    @case('danger') bg-red-100 text-red-800 @break
                                    @default bg-gray-100 text-gray-800
                                @endswitch
                            ">
                                {{ $item->status->label() }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @else
            <div class="border-t border-gray-100 pt-4 text-center">
                <p class="text-sm text-gray-400">No items yet.</p>
                <a href="{{ route('demo-items.create') }}" class="mt-1 text-sm text-emerald-600 hover:text-emerald-800">Create your first item</a>
            </div>
        @endif
    </div>
</div>
