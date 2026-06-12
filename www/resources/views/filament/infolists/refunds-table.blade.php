<div class="overflow-x-auto">
    @if(empty($refunds))
        <p class="text-gray-500 text-sm italic">No refunds for this payment</p>
    @else
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 dark:border-gray-700">
                    <th class="text-left py-2 font-semibold text-gray-900 dark:text-white">Refund ID</th>
                    <th class="text-left py-2 font-semibold text-gray-900 dark:text-white">Amount</th>
                    <th class="text-left py-2 font-semibold text-gray-900 dark:text-white">Status</th>
                    <th class="text-left py-2 font-semibold text-gray-900 dark:text-white">Description</th>
                    <th class="text-left py-2 font-semibold text-gray-900 dark:text-white">Created At</th>
                </tr>
            </thead>
            <tbody>
                @foreach($refunds as $refund)
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <td class="py-2">
                            <span class="font-mono text-xs bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">
                                {{ $refund['id'] }}
                            </span>
                        </td>
                        <td class="py-2 font-semibold">
                            €{{ number_format((float)$refund['amount']['value'], 2) }} {{ strtoupper($refund['amount']['currency']) }}
                        </td>
                        <td class="py-2">
                            @php
                                $statusColors = [
                                    'refunded' => 'green',
                                    'pending' => 'yellow', 
                                    'processing' => 'blue',
                                    'failed' => 'red',
                                ];
                                $color = $statusColors[$refund['status']] ?? 'gray';
                            @endphp
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-{{ $color }}-100 text-{{ $color }}-800 dark:bg-{{ $color }}-900 dark:text-{{ $color }}-200">
                                {{ ucfirst($refund['status']) }}
                            </span>
                        </td>
                        <td class="py-2 text-gray-600 dark:text-gray-400">
                            {{ $refund['description'] ?? 'No description' }}
                        </td>
                        <td class="py-2 text-gray-600 dark:text-gray-400">
                            {{ \Carbon\Carbon::parse($refund['createdAt'])->format('M j, Y H:i') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>