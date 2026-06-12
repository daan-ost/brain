@extends('layouts.profile')

@section('header')
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ __('profile.invoices') }}
    </h2>
@endsection

@section('content')
    <div class="p-6" x-data="{ showDetailModal: false, selectedOrder: null }">
        <section>
            <header>
                <h2 class="text-lg font-medium text-gray-900">
                    {{ __('profile.invoice_management') }}
                </h2>

                <p class="mt-1 text-sm text-gray-600">
                    {{ __('profile.invoice_management_description') }}
                </p>
            </header>

            <div class="mt-6">
                @if($orders->isEmpty())
                    <!-- No invoices yet -->
                    <div class="bg-gray-50 p-8 rounded-lg text-center">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <h3 class="mt-2 text-sm font-medium text-gray-900">{{ __('profile.no_invoices_yet') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ __('profile.invoices_appear_after_purchase') }}</p>
                    </div>
                @else
                    <!-- Invoices table -->
                    <div class="bg-white shadow overflow-hidden sm:rounded-lg">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        {{ __('profile.invoice_number') }}
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        {{ __('profile.date') }}
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        {{ __('profile.license') }}
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        {{ __('profile.billed_to') }}
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        {{ __('profile.amount') }}
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        {{ __('profile.status') }}
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        {{ __('profile.action') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($orders as $order)
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            {{ $order->invoice_number }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ format_date(\Carbon\Carbon::parse($order->invoice_date)) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            @if($order->license)
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">
                                                    {{ $order->license->name }}
                                                </span>
                                            @else
                                                <span class="text-gray-400">{{ __('profile.unknown_license') }}</span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            @if($order->payer_type === 'organization')
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                                    {{ $order->organizationPayer ? $order->organizationPayer->name : __('profile.organization') }}
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                    {{ __('profile.personal') }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ format_currency($order->gross_amount, strtoupper($order->currency)) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            @if($order->isPaid() && $order->paid_at)
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                    {{ __('profile.paid') }}
                                                </span>
                                            @elseif($order->isFailed())
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                    {{ __('profile.failed') }}
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                    {{ __('profile.pending') }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <div class="flex items-center justify-end space-x-2">
                                                <!-- Details button -->
                                                <button type="button"
                                                        @click="selectedOrder = {
                                                            invoice_number: '{{ $order->invoice_number }}',
                                                            invoice_date: '{{ format_date(\Carbon\Carbon::parse($order->invoice_date)) }}',
                                                            paid_at: '{{ $order->paid_at ? format_datetime(\Carbon\Carbon::parse($order->paid_at)) : '' }}',
                                                            license_name: '{{ $order->license ? addslashes($order->license->name) : __('profile.unknown_license') }}',
                                                            license_type: '{{ $order->license ? ($order->license->billing_cycle === 'one_time' ? __('profile.one_time') : __('profile.subscription')) : '-' }}',
                                                            license_credits: '{{ $order->license ? $order->license->credits : '-' }}',
                                                            billed_to: '{{ $order->payer_type === 'organization' ? ($order->organizationPayer ? addslashes($order->organizationPayer->name) : __('profile.organization')) : __('profile.personal') }}',
                                                            currency: '{{ strtoupper($order->currency) }}',
                                                            net_amount: '{{ format_number($order->net_amount) }}',
                                                            tax_amount: '{{ format_number($order->tax_amount) }}',
                                                            vat_rate: '{{ $order->net_amount > 0 ? round(($order->tax_amount / $order->net_amount) * 100) : 0 }}',
                                                            gross_amount: '{{ format_number($order->gross_amount) }}',
                                                            payment_method: '{{ $order->payment_method ?? '-' }}',
                                                            download_url: '{{ route('profile.invoices.download', $order) }}'
                                                        }; showDetailModal = true"
                                                        class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                                    <svg class="mr-1.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                    </svg>
                                                    {{ __('profile.details') }}
                                                </button>
                                                <!-- Download button -->
                                                <a href="{{ route('profile.invoices.download', $order) }}"
                                                   class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                                    <svg class="mr-1.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                    </svg>
                                                    {{ __('profile.download') }}
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Invoice count summary -->
                    <div class="mt-4 text-sm text-gray-500">
                        {{ __('profile.showing_count_invoices', ['count' => $orders->count()]) }}
                    </div>
                @endif
            </div>
        </section>

        <!-- Invoice Details Modal -->
        <div x-show="showDetailModal"
             x-cloak
             class="fixed z-10 inset-0 overflow-y-auto"
             aria-labelledby="modal-title"
             role="dialog"
             aria-modal="true"
             style="display: none;">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <!-- Background overlay -->
                <div x-show="showDetailModal"
                     x-transition:enter="ease-out duration-300"
                     x-transition:enter-start="opacity-0"
                     x-transition:enter-end="opacity-100"
                     x-transition:leave="ease-in duration-200"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0"
                     class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                     aria-hidden="true"
                     @click="showDetailModal = false"></div>

                <!-- Modal panel -->
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                <div x-show="showDetailModal"
                     x-transition:enter="ease-out duration-300"
                     x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                     x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                     x-transition:leave="ease-in duration-200"
                     x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                     x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                     class="inline-block align-bottom bg-white rounded-lg px-4 pt-5 pb-4 text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full sm:p-6">

                    <div class="absolute top-0 right-0 pt-4 pr-4">
                        <button type="button" @click="showDetailModal = false" class="bg-white rounded-md text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            <span class="sr-only">{{ __('profile.close') }}</span>
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="sm:flex sm:items-start">
                        <div class="mt-3 text-center sm:mt-0 sm:text-left w-full">
                            <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">
                                {{ __('profile.invoice_details_title') }}
                            </h3>

                            <div class="mt-4 space-y-4">
                                <!-- Invoice number and dates -->
                                <div class="bg-gray-50 rounded-lg p-4">
                                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                        <dt class="text-gray-500">{{ __('profile.invoice_number') }}</dt>
                                        <dd class="text-gray-900 font-medium" x-text="selectedOrder?.invoice_number"></dd>

                                        <dt class="text-gray-500">{{ __('profile.invoice_date_label') }}</dt>
                                        <dd class="text-gray-900" x-text="selectedOrder?.invoice_date"></dd>

                                        <dt class="text-gray-500">{{ __('profile.paid_at') }}</dt>
                                        <dd class="text-gray-900" x-text="selectedOrder?.paid_at || '{{ __('profile.not_paid_yet') }}'"></dd>
                                    </dl>
                                </div>

                                <!-- License section -->
                                <div>
                                    <h4 class="text-sm font-medium text-gray-900 uppercase tracking-wider border-b pb-2">{{ __('profile.license_section') }}</h4>
                                    <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                        <dt class="text-gray-500">{{ __('profile.license_name') }}</dt>
                                        <dd class="text-gray-900" x-text="selectedOrder?.license_name"></dd>

                                        <dt class="text-gray-500">{{ __('profile.license_type') }}</dt>
                                        <dd class="text-gray-900" x-text="selectedOrder?.license_type"></dd>

                                        <dt class="text-gray-500">{{ __('profile.license_credits') }}</dt>
                                        <dd class="text-gray-900" x-text="selectedOrder?.license_credits"></dd>
                                    </dl>
                                </div>

                                <!-- Billing section -->
                                <div>
                                    <h4 class="text-sm font-medium text-gray-900 uppercase tracking-wider border-b pb-2">{{ __('profile.billing_section') }}</h4>
                                    <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                        <dt class="text-gray-500">{{ __('profile.billed_to') }}</dt>
                                        <dd class="text-gray-900" x-text="selectedOrder?.billed_to"></dd>

                                        <dt class="text-gray-500">{{ __('profile.net_amount') }}</dt>
                                        <dd class="text-gray-900" x-text="selectedOrder?.currency + ' ' + selectedOrder?.net_amount"></dd>

                                        <dt class="text-gray-500">
                                            <span>{{ __('profile.vat_amount') }}</span>
                                            <span x-text="'(' + selectedOrder?.vat_rate + '%)'"></span>
                                        </dt>
                                        <dd class="text-gray-900" x-text="selectedOrder?.currency + ' ' + selectedOrder?.tax_amount"></dd>

                                        <dt class="text-gray-500 font-medium">{{ __('profile.gross_amount') }}</dt>
                                        <dd class="text-gray-900 font-medium" x-text="selectedOrder?.currency + ' ' + selectedOrder?.gross_amount"></dd>

                                        <dt class="text-gray-500">{{ __('profile.payment_method') }}</dt>
                                        <dd class="text-gray-900 capitalize" x-text="selectedOrder?.payment_method"></dd>
                                    </dl>
                                </div>
                            </div>

                            <!-- Download button -->
                            <div class="mt-6">
                                <a :href="selectedOrder?.download_url" class="w-full inline-flex justify-center items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                    <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                    {{ __('profile.download') }}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
