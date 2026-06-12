@props([
    'licenseType' => 'user', // 'user' or 'organization'
    'licenseId' => null,
    'title' => 'Credit Usage',
    'period' => 30, // days to analyze, null for all time
    'displayMode' => 'card', // 'card', 'compact', 'detailed'
    'showWorkflows' => true,
    'showRecentActivity' => true
])

@php
    $creditsService = app(\App\Services\CreditsService::class);
    
    $options = [];
    if ($period) {
        $options['days'] = $period;
    }
    
    $spendingData = $creditsService->getLicenseSpendingData($licenseType, $licenseId, $options);
@endphp

@if($spendingData['has_activity'])
    <div class="bg-white border border-gray-200 rounded-lg">
        {{-- Header --}}
        <div class="px-4 py-3 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h4 class="text-sm font-medium text-gray-900">{{ $title }}</h4>
                @if($period)
                    <span class="text-xs text-gray-500">Last {{ $period }} days</span>
                @else
                    <span class="text-xs text-gray-500">All time</span>
                @endif
            </div>
        </div>

        {{-- Main metrics --}}
        <div class="px-4 py-3">
            @if($displayMode === 'compact')
                {{-- Compact view: single row of key metrics --}}
                <div class="flex items-center space-x-6 text-sm">
                    <div class="flex items-center">
                        <div class="w-2 h-2 bg-red-400 rounded-full mr-2"></div>
                        <span class="text-gray-600">{{ number_format($spendingData['total_credits_spent']) }} credits spent</span>
                    </div>
                    <div class="flex items-center">
                        <div class="w-2 h-2 bg-blue-400 rounded-full mr-2"></div>
                        <span class="text-gray-600">{{ number_format($spendingData['total_documents_processed']) }} docs</span>
                    </div>
                    @if($showRecentActivity && $spendingData['recent_activity']['credits_spent_last_7_days'] > 0)
                        <div class="flex items-center">
                            <div class="w-2 h-2 bg-green-400 rounded-full mr-2"></div>
                            <span class="text-gray-600">{{ $spendingData['recent_activity']['credits_spent_last_7_days'] }} in last 7d</span>
                        </div>
                    @endif
                </div>
            @else
                {{-- Card or detailed view --}}
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-red-600">{{ number_format($spendingData['total_credits_spent']) }}</div>
                        <div class="text-xs text-gray-500 uppercase tracking-wide">Credits Spent</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-blue-600">{{ number_format($spendingData['total_documents_processed']) }}</div>
                        <div class="text-xs text-gray-500 uppercase tracking-wide">Documents</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-green-600">{{ number_format($spendingData['total_runs']) }}</div>
                        <div class="text-xs text-gray-500 uppercase tracking-wide">Total Runs</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-purple-600">{{ $spendingData['average_credits_per_document'] }}</div>
                        <div class="text-xs text-gray-500 uppercase tracking-wide">Avg per Doc</div>
                    </div>
                </div>

                @if($displayMode === 'detailed')
                    {{-- Detailed metrics --}}
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <span class="text-gray-600">Average per run:</span>
                                <span class="font-medium text-gray-900">{{ $spendingData['average_credits_per_run'] }} credits</span>
                            </div>
                            @if($spendingData['first_transaction_date'])
                                <div>
                                    <span class="text-gray-600">Using since:</span>
                                    <span class="font-medium text-gray-900">{{ \Carbon\Carbon::parse($spendingData['first_transaction_date'])->format('M j, Y') }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Recent activity --}}
                @if($showRecentActivity && $spendingData['recent_activity']['credits_spent_last_7_days'] > 0)
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <div class="flex items-center justify-between">
                            <h5 class="text-sm font-medium text-gray-700">Recent Activity</h5>
                            <div class="flex items-center space-x-4 text-xs text-gray-500">
                                <span>{{ $spendingData['recent_activity']['credits_spent_last_7_days'] }} credits (7d)</span>
                                <span>{{ $spendingData['recent_activity']['runs_last_7_days'] }} runs (7d)</span>
                            </div>
                        </div>
                        
                        {{-- Simple activity indicator --}}
                        @php
                            $activityLevel = min(100, ($spendingData['recent_activity']['credits_spent_last_7_days'] / max(1, $spendingData['total_credits_spent']) * 100 * 7));
                            $activityColor = $activityLevel > 50 ? 'bg-green-400' : ($activityLevel > 20 ? 'bg-yellow-400' : 'bg-gray-300');
                        @endphp
                        <div class="mt-2">
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="{{ $activityColor }} h-2 rounded-full transition-all duration-300" style="width: {{ $activityLevel }}%"></div>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Top workflows --}}
                @if($showWorkflows && $spendingData['workflow_usage']->count() > 0 && $displayMode !== 'compact')
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <h5 class="text-sm font-medium text-gray-700 mb-3">Top Workflows</h5>
                        <div class="space-y-2">
                            @foreach($spendingData['workflow_usage']->take(3) as $workflow)
                                <div class="flex items-center justify-between text-sm">
                                    <div class="flex items-center">
                                        <div class="w-2 h-2 bg-blue-400 rounded-full mr-2"></div>
                                        <span class="text-gray-700 truncate max-w-[150px]" title="{{ $workflow['workflow_name'] }}">
                                            {{ Str::limit($workflow['workflow_name'], 20) }}
                                        </span>
                                    </div>
                                    <div class="flex items-center space-x-3 text-xs text-gray-500">
                                        <span>{{ $workflow['credits_spent'] }}c</span>
                                        <span>{{ $workflow['documents_processed'] }}d</span>
                                        <span>{{ $workflow['runs_count'] }}r</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        
                        @if($spendingData['workflow_usage']->count() > 3)
                            <div class="mt-2 text-xs text-gray-500">
                                +{{ $spendingData['workflow_usage']->count() - 3 }} more workflows
                            </div>
                        @endif
                    </div>
                @endif
            @endif
        </div>
    </div>
@else
    {{-- No activity state --}}
    <div class="bg-gray-50 border border-gray-200 rounded-lg px-4 py-3">
        <div class="flex items-center">
            <svg class="h-5 w-5 text-gray-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
            </svg>
            <div>
                <h4 class="text-sm font-medium text-gray-900">{{ $title }}</h4>
                <p class="text-xs text-gray-500">
                    @if($period)
                        No credits spent in the last {{ $period }} days
                    @else
                        No credit usage yet
                    @endif
                </p>
            </div>
        </div>
    </div>
@endif