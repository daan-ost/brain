<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Refresh Button --}}
        <div class="flex justify-end">
            <x-filament::button wire:click="refresh" icon="heroicon-o-arrow-path">
                Refresh Data
            </x-filament::button>
        </div>

        {{-- Server Resources Section --}}
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            {{-- CPU Usage --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-cpu-chip class="w-5 h-5" />
                        CPU Usage
                    </div>
                </x-slot>
                <div class="text-3xl font-bold {{ $data['server']['cpu_usage'] > 80 ? 'text-danger-600' : ($data['server']['cpu_usage'] > 60 ? 'text-warning-600' : 'text-success-600') }}">
                    {{ $data['server']['cpu_usage'] }}%
                </div>
                @if($data['server']['load_average'])
                    <div class="text-sm text-gray-500 mt-2">
                        Load: {{ $data['server']['load_average']['1min'] }} / {{ $data['server']['load_average']['5min'] }} / {{ $data['server']['load_average']['15min'] }}
                    </div>
                @endif
            </x-filament::section>

            {{-- Memory Usage --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-server class="w-5 h-5" />
                        Memory
                    </div>
                </x-slot>
                <div class="text-3xl font-bold {{ $data['server']['memory']['percent_used'] > 80 ? 'text-danger-600' : ($data['server']['memory']['percent_used'] > 60 ? 'text-warning-600' : 'text-primary-600') }}">
                    {{ $data['server']['memory']['percent_used'] }}%
                </div>
                <div class="text-sm text-gray-500 mt-2">
                    {{ $data['server']['memory']['used_mb'] }} MB / {{ $data['server']['memory']['limit_mb'] }} MB
                </div>
            </x-filament::section>

            {{-- Disk Usage --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-circle-stack class="w-5 h-5" />
                        Disk
                    </div>
                </x-slot>
                <div class="text-3xl font-bold {{ $data['server']['disk']['percent_used'] > 80 ? 'text-danger-600' : ($data['server']['disk']['percent_used'] > 60 ? 'text-warning-600' : 'text-success-600') }}">
                    {{ $data['server']['disk']['percent_used'] }}%
                </div>
                <div class="text-sm text-gray-500 mt-2">
                    {{ $data['server']['disk']['used_gb'] }} GB / {{ $data['server']['disk']['total_gb'] }} GB
                </div>
            </x-filament::section>

            {{-- Uptime --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-clock class="w-5 h-5" />
                        Uptime
                    </div>
                </x-slot>
                <div class="text-lg font-medium text-gray-700">
                    {{ $data['server']['uptime'] ?? 'N/A' }}
                </div>
            </x-filament::section>
        </div>

        {{-- Queue System Section --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-queue-list class="w-5 h-5" />
                    Queue System
                </div>
            </x-slot>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div class="text-2xl font-bold {{ $data['queue']['pending'] > 100 ? 'text-warning-600' : 'text-gray-900 dark:text-gray-100' }}">
                        {{ number_format($data['queue']['pending']) }}
                    </div>
                    <div class="text-sm text-gray-500">Pending Jobs</div>
                </div>
                <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                    <div class="text-2xl font-bold {{ $data['queue']['failed_24h'] > 10 ? 'text-danger-600' : 'text-gray-900 dark:text-gray-100' }}">
                        {{ number_format($data['queue']['failed_24h']) }}
                    </div>
                    <div class="text-sm text-gray-500">Failed (24h)</div>
                </div>
            </div>
        </x-filament::section>

        {{-- External Services Section --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            {{-- DeepL --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-language class="w-5 h-5" />
                        DeepL Translation
                    </div>
                </x-slot>
                @if(isset($data['deepl']['error']))
                    <div class="text-sm text-danger-600">{{ $data['deepl']['error'] }}</div>
                @else
                    <div class="mb-3">
                        <div class="flex justify-between text-sm mb-1">
                            <span>Usage</span>
                            <span class="{{ $data['deepl']['percent_used'] > 80 ? 'text-danger-600' : 'text-gray-600' }}">
                                {{ $data['deepl']['percent_used'] }}%
                            </span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="h-2 rounded-full {{ $data['deepl']['percent_used'] > 80 ? 'bg-danger-600' : 'bg-primary-600' }}"
                                 style="width: {{ $data['deepl']['percent_used'] }}%"></div>
                        </div>
                    </div>
                    <div class="text-sm text-gray-600">
                        {{ number_format($data['deepl']['character_count']) }} / {{ number_format($data['deepl']['character_limit']) }} characters
                    </div>
                @endif
            </x-filament::section>

            {{-- Postmark --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-envelope class="w-5 h-5" />
                        Postmark
                    </div>
                </x-slot>
                @if(isset($data['postmark']['error']))
                    <div class="text-sm text-danger-600">{{ $data['postmark']['error'] }}</div>
                @else
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <div>
                            <div class="text-xl font-bold">{{ number_format($data['postmark']['emails_today']) }}</div>
                            <div class="text-gray-500">Today</div>
                        </div>
                        <div>
                            <div class="text-xl font-bold">{{ number_format($data['postmark']['emails_this_month']) }}</div>
                            <div class="text-gray-500">This Month</div>
                        </div>
                    </div>
                    <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">
                        <div class="flex justify-between text-sm">
                            <span>Bounce Rate (Month)</span>
                            <span class="{{ $data['postmark']['bounce_rate_month'] > 5 ? 'text-danger-600' : 'text-success-600' }}">
                                {{ $data['postmark']['bounce_rate_month'] }}%
                            </span>
                        </div>
                    </div>
                @endif
            </x-filament::section>

            {{-- Credits --}}
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-currency-dollar class="w-5 h-5" />
                        Credits System
                    </div>
                </x-slot>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span>Consumed Today</span>
                        <span class="font-bold text-danger-600">{{ number_format($data['credits']['consumed_today']) }}</span>
                    </div>
                    <div class="flex justify-between text-gray-600">
                        <span class="pl-4">- User Credits</span>
                        <span>{{ number_format($data['credits']['user_credits_today']) }}</span>
                    </div>
                    <div class="flex justify-between text-gray-600">
                        <span class="pl-4">- Org Credits</span>
                        <span>{{ number_format($data['credits']['org_credits_today']) }}</span>
                    </div>
                    <div class="pt-2 border-t border-gray-200 dark:border-gray-700 flex justify-between">
                        <span>Issued Today</span>
                        <span class="font-bold text-success-600">{{ number_format($data['credits']['issued_today']) }}</span>
                    </div>
                </div>
            </x-filament::section>
        </div>

        {{-- IPRegistry Stats --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-globe-alt class="w-5 h-5" />
                    IPRegistry
                </div>
            </x-slot>
            @if(isset($data['ipregistry']['error']))
                <div class="text-sm text-danger-600">{{ $data['ipregistry']['error'] }}</div>
            @else
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <div class="text-xl font-bold">{{ number_format($data['ipregistry']['detections_today']) }}</div>
                        <div class="text-sm text-gray-500">Detections Today</div>
                    </div>
                    <div>
                        <div class="text-xl font-bold">{{ number_format($data['ipregistry']['detections_this_month']) }}</div>
                        <div class="text-sm text-gray-500">This Month</div>
                    </div>
                    <div>
                        <div class="text-xl font-bold text-success-600">{{ $data['ipregistry']['cache_hit_rate_percent'] }}%</div>
                        <div class="text-sm text-gray-500">Cache Hit Rate</div>
                    </div>
                    <div>
                        <div class="text-xl font-bold">{{ number_format($data['ipregistry']['estimated_api_calls_month']) }}</div>
                        <div class="text-sm text-gray-500">Est. API Calls</div>
                    </div>
                </div>
                @if(!empty($data['ipregistry']['top_countries']))
                    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <div class="text-sm text-gray-500 mb-2">Top Countries (30d)</div>
                        <div class="flex gap-4">
                            @foreach($data['ipregistry']['top_countries'] as $country)
                                <div class="text-sm">
                                    <span class="font-bold">{{ $country['code'] }}</span>: {{ $country['count'] }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        </x-filament::section>

        {{-- Cache Notice --}}
        <div class="text-sm text-gray-500 text-center">
            Dashboard data is cached for 5 minutes for optimal performance.
        </div>
    </div>
</x-filament-panels::page>
