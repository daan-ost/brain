<div class="space-y-4">
    @if($error)
        <div class="p-4 bg-danger-50 dark:bg-danger-900/20 border border-danger-200 dark:border-danger-700 rounded-lg">
            <div class="flex items-center gap-2 text-danger-700 dark:text-danger-400">
                <x-heroicon-o-exclamation-triangle class="w-5 h-5" />
                <span class="font-medium">{{ $error }}</span>
            </div>
        </div>
    @else
        {{-- Customer Info --}}
        @if($customer)
            <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase mb-3">Customer Details</h4>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Name</span>
                        <p class="text-sm text-gray-900 dark:text-gray-100 font-medium">{{ $customer['name'] ?? '—' }}</p>
                    </div>
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Email</span>
                        <p class="text-sm text-gray-900 dark:text-gray-100">{{ $customer['email'] ?? '—' }}</p>
                    </div>
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Customer ID</span>
                        <p class="text-sm text-gray-900 dark:text-gray-100 font-mono">{{ $customer['id'] ?? '—' }}</p>
                    </div>
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Created</span>
                        <p class="text-sm text-gray-900 dark:text-gray-100">
                            {{ isset($customer['createdAt']) ? \Carbon\Carbon::parse($customer['createdAt'])->format('d-m-Y H:i') : '—' }}
                        </p>
                    </div>
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Locale</span>
                        <p class="text-sm text-gray-900 dark:text-gray-100">{{ $customer['locale'] ?? '—' }}</p>
                    </div>
                    @if(isset($customer['metadata']) && !empty($customer['metadata']))
                        <div class="col-span-2">
                            <span class="text-xs text-gray-500 dark:text-gray-400">Metadata</span>
                            <pre class="text-xs text-gray-700 dark:text-gray-300 mt-1">{{ json_encode($customer['metadata'], JSON_PRETTY_PRINT) }}</pre>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- Subscription Info --}}
        @if($subscription)
            <div class="p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase mb-3">Subscription Details</h4>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Status</span>
                        <p>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                {{ $subscription['status'] === 'active' ? 'bg-success-100 text-success-800 dark:bg-success-900/30 dark:text-success-400' : '' }}
                                {{ $subscription['status'] === 'canceled' ? 'bg-warning-100 text-warning-800 dark:bg-warning-900/30 dark:text-warning-400' : '' }}
                                {{ $subscription['status'] === 'suspended' ? 'bg-danger-100 text-danger-800 dark:bg-danger-900/30 dark:text-danger-400' : '' }}
                                {{ !in_array($subscription['status'], ['active', 'canceled', 'suspended']) ? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' : '' }}">
                                {{ ucfirst($subscription['status']) }}
                            </span>
                        </p>
                    </div>
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Amount</span>
                        <p class="text-sm text-gray-900 dark:text-gray-100 font-medium">
                            {{ $subscription['amount']['currency'] ?? 'EUR' }} {{ $subscription['amount']['value'] ?? '—' }}
                        </p>
                    </div>
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Interval</span>
                        <p class="text-sm text-gray-900 dark:text-gray-100">{{ $subscription['interval'] ?? '—' }}</p>
                    </div>
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Subscription ID</span>
                        <p class="text-sm text-gray-900 dark:text-gray-100 font-mono">{{ $subscription['id'] ?? '—' }}</p>
                    </div>
                    @if(isset($subscription['nextPaymentDate']))
                        <div>
                            <span class="text-xs text-gray-500 dark:text-gray-400">Next Payment</span>
                            <p class="text-sm text-gray-900 dark:text-gray-100">
                                {{ \Carbon\Carbon::parse($subscription['nextPaymentDate'])->format('d-m-Y') }}
                            </p>
                        </div>
                    @endif
                    @if(isset($subscription['canceledAt']))
                        <div>
                            <span class="text-xs text-gray-500 dark:text-gray-400">Canceled At</span>
                            <p class="text-sm text-danger-600 dark:text-danger-400">
                                {{ \Carbon\Carbon::parse($subscription['canceledAt'])->format('d-m-Y H:i') }}
                            </p>
                        </div>
                    @endif
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Created</span>
                        <p class="text-sm text-gray-900 dark:text-gray-100">
                            {{ isset($subscription['createdAt']) ? \Carbon\Carbon::parse($subscription['createdAt'])->format('d-m-Y H:i') : '—' }}
                        </p>
                    </div>
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Description</span>
                        <p class="text-sm text-gray-900 dark:text-gray-100">{{ $subscription['description'] ?? '—' }}</p>
                    </div>
                </div>
            </div>
        @endif

        {{-- Payment History --}}
        @if(!empty($payments))
            <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <h4 class="text-sm font-medium text-gray-500 dark:text-gray-400 uppercase mb-3">Recent Payments ({{ count($payments) }})</h4>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead>
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Date</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Amount</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Status</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Method</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">ID</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($payments as $payment)
                                <tr>
                                    <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">
                                        {{ isset($payment['createdAt']) ? \Carbon\Carbon::parse($payment['createdAt'])->format('d-m-Y H:i') : '—' }}
                                    </td>
                                    <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100 font-medium">
                                        {{ $payment['amount']['currency'] ?? 'EUR' }} {{ $payment['amount']['value'] ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                            {{ $payment['status'] === 'paid' ? 'bg-success-100 text-success-800 dark:bg-success-900/30 dark:text-success-400' : '' }}
                                            {{ $payment['status'] === 'failed' ? 'bg-danger-100 text-danger-800 dark:bg-danger-900/30 dark:text-danger-400' : '' }}
                                            {{ $payment['status'] === 'pending' ? 'bg-warning-100 text-warning-800 dark:bg-warning-900/30 dark:text-warning-400' : '' }}
                                            {{ $payment['status'] === 'canceled' ? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' : '' }}
                                            {{ !in_array($payment['status'], ['paid', 'failed', 'pending', 'canceled']) ? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' : '' }}">
                                            {{ ucfirst($payment['status']) }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">
                                        {{ $payment['method'] ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400 font-mono">
                                        {{ substr($payment['id'] ?? '', 0, 15) }}...
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @elseif($subscription)
            <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg text-center">
                <p class="text-sm text-gray-500 dark:text-gray-400">No payment history found for this subscription.</p>
            </div>
        @endif

        {{-- No Data --}}
        @if(!$customer && !$subscription)
            <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg text-center">
                <p class="text-sm text-gray-500 dark:text-gray-400">No Mollie data available.</p>
            </div>
        @endif
    @endif
</div>
