<div>
    <!-- Progress Stepper -->
    <div class="mb-8">
        <div class="flex items-center justify-center">
            <div class="flex items-center">
                <!-- Step 1 - Completed -->
                <div class="flex items-center">
                    <div class="bg-green-600 text-white rounded-full h-10 w-10 flex items-center justify-center text-sm font-medium">
                        ✓
                    </div>
                    <span class="ml-2 text-green-600 font-medium">{{ __('checkout.step_product_selection') }}</span>
                </div>

                <!-- Arrow -->
                <svg class="mx-4 h-5 w-5 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>

                <!-- Step 2 - Completed -->
                <div class="flex items-center">
                    <div class="bg-green-600 text-white rounded-full h-10 w-10 flex items-center justify-center text-sm font-medium">
                        ✓
                    </div>
                    <span class="ml-2 text-green-600 font-medium">{{ __('checkout.step_secure_checkout') }}</span>
                </div>

                <!-- Arrow -->
                <svg class="mx-4 h-5 w-5 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>

                <!-- Step 3 - Active -->
                <div class="flex items-center">
                    <div class="bg-indigo-600 text-white rounded-full h-10 w-10 flex items-center justify-center text-sm font-medium">
                        3
                    </div>
                    <span class="ml-2 text-indigo-600 font-medium">{{ __('checkout.step_activation') }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Activation Content -->
    <div class="bg-white rounded-lg shadow p-8">
        @if($status === 'success')
            <!-- Success State -->
            <div class="text-center">
                <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-green-100 mb-6">
                    <svg class="h-8 w-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                </div>
                
                @if($orderData && ($orderData['is_invoice_payment'] ?? false))
                    <h3 class="text-2xl font-semibold text-gray-900 mb-4">{{ __('checkout.license_added') }}</h3>
                @else
                    <h3 class="text-2xl font-semibold text-gray-900 mb-4">{{ __('checkout.payment_successful') }}</h3>
                @endif

                @if($orderData && ($orderData['is_invoice_payment'] ?? false) && $orderData['license'])
                    <!-- Checklist for invoice payments -->
                    <div class="bg-gray-50 rounded-lg p-6 mb-8 text-left max-w-md mx-auto">
                        <ul class="space-y-3">
                            <li class="flex items-start">
                                <svg class="h-5 w-5 text-green-600 mr-3 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                <span class="text-gray-700">{{ __('checkout.license_activated_check') }}</span>
                            </li>
                            <li class="flex items-start">
                                <svg class="h-5 w-5 text-green-600 mr-3 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                <span class="text-gray-700">{{ __('checkout.invoice_generated_check') }}</span>
                            </li>
                            <li class="flex items-start">
                                <svg class="h-5 w-5 text-green-600 mr-3 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                <span class="text-gray-700">{{ __('checkout.credits_added_check', ['credits' => number_format($orderData['license']['credits'])]) }}</span>
                            </li>
                            <li class="flex items-start">
                                <svg class="h-5 w-5 text-indigo-600 mr-3 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                                </svg>
                                <span class="text-gray-900 font-bold text-lg">{{ __('checkout.pay_invoice_todo') }}</span>
                            </li>
                        </ul>
                    </div>
                @else
                    <p class="text-gray-600 mb-8">
                        @if($orderData && $orderData['license'])
                            {{ __('checkout.license_activated_with_credits', ['credits' => number_format($orderData['license']['credits'])]) }}
                        @else
                            {{ $message ?? __('checkout.payment_successful_message') }}
                        @endif
                    </p>
                @endif

                @if($orderData && $orderData['license'])
                    <div class="bg-gray-50 rounded-lg p-6 mb-8 text-left max-w-md mx-auto">
                        <h4 class="font-medium text-gray-900 mb-4">{{ __('checkout.order_summary') }}</h4>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">{{ __('checkout.order_id') }}:</span>
                                <span class="font-mono text-gray-900">{{ $orderData['id'] }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">{{ __('checkout.license') }}:</span>
                                <span class="text-gray-900">{{ $orderData['license']['name'] }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">{{ __('checkout.amount') }}:</span>
                                <span class="text-gray-900">{{ $orderData['formatted_amount'] }}</span>
                            </div>
                            @if($orderData['license']['tier'] === 'onetime')
                                <div class="flex justify-between">
                                    <span class="text-gray-600">{{ __('checkout.credits_added') }}:</span>
                                    <span class="text-gray-900">{{ number_format($orderData['license']['credits']) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">{{ __('checkout.valid_until') }}:</span>
                                    <span class="text-gray-900">
                                        {{ now()->addDays($orderData['license']['period'] ?? 180)->format('M j, Y') }}
                                    </span>
                                </div>
                            @elseif($orderData['license']['tier'] === 'premium')
                                <div class="flex justify-between">
                                    <span class="text-gray-600">{{ __('checkout.subscription') }}:</span>
                                    <span class="text-gray-900">{{ __('checkout.annual_auto_renewal') }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">{{ __('checkout.next_billing') }}:</span>
                                    <span class="text-gray-900">{{ now()->addYear()->format('M j, Y') }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                <div class="space-y-4">
                    <a href="{{ url('/') }}"
                       class="inline-block bg-indigo-600 text-white py-3 px-6 rounded-lg hover:bg-indigo-700 transition duration-200 mr-4">
                        {{ __('checkout.start_converting_files') }}
                    </a>
                    @if($orderId)
                        <a href="{{ route('profile.invoices.download', $orderId) }}"
                           class="inline-block bg-gray-200 text-gray-800 py-3 px-6 rounded-lg hover:bg-gray-300 transition duration-200">
                            {{ __('checkout.download_invoice') }}
                        </a>
                    @endif
                </div>
            </div>
            
        @elseif($status === 'invoice_pending')
            <!-- Invoice Pending State -->
            <div class="text-center">
                <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-yellow-100 mb-6">
                    <svg class="h-8 w-8 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                
                <h3 class="text-2xl font-semibold text-gray-900 mb-4">{{ __('checkout.license_request_submitted') }}</h3>
                <p class="text-gray-600 mb-8">
                    {{ $message }}
                </p>

                @if($invoiceNumber)
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-8 max-w-md mx-auto">
                        <div class="flex items-center mb-4">
                            <svg class="h-6 w-6 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            <h4 class="font-medium text-blue-900">{{ __('checkout.invoice_details') }}</h4>
                        </div>
                        <div class="text-left space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-blue-700">{{ __('checkout.invoice_number') }}:</span>
                                <span class="font-mono text-blue-900">{{ $invoiceNumber }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-blue-700">{{ __('checkout.status') }}:</span>
                                <span class="text-blue-900">{{ __('checkout.pending_review') }}</span>
                            </div>
                        </div>
                    </div>
                @endif

                <div class="bg-gray-50 rounded-lg p-6 mb-8 text-left max-w-lg mx-auto">
                    <h4 class="font-medium text-gray-900 mb-3">{{ __('checkout.what_happens_next') }}</h4>
                    <ol class="list-decimal list-inside space-y-2 text-sm text-gray-600">
                        <li>{{ __('checkout.next_step_1') }}</li>
                        <li>{{ __('checkout.next_step_2') }}</li>
                        <li>{{ __('checkout.next_step_3') }}</li>
                        <li>{{ __('checkout.next_step_4') }}</li>
                    </ol>
                </div>

                <div class="space-y-4">
                    <div class="space-x-4">
                        @if($orderId)
                            <a href="{{ route('profile.invoices.download', $orderId) }}"
                               class="inline-block bg-indigo-600 text-white py-3 px-6 rounded-lg hover:bg-indigo-700 transition duration-200">
                                {{ __('checkout.download_invoice') }}
                            </a>
                        @endif
                        <a href="{{ url('/') }}"
                           class="inline-block bg-gray-200 text-gray-800 py-3 px-6 rounded-lg hover:bg-gray-300 transition duration-200">
                            {{ __('checkout.continue_with_free_account') }}
                        </a>
                    </div>
                    <p class="text-sm text-gray-500">
                        {{ __('checkout.email_confirmation') }}
                    </p>
                </div>
            </div>
            
        @elseif($status === 'error')
            <!-- Error State -->
            <div class="text-center">
                <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100 mb-6">
                    <svg class="h-8 w-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </div>

                <h3 class="text-2xl font-semibold text-gray-900 mb-4">{{ __('checkout.payment_failed') }}</h3>
                <p class="text-gray-600 mb-8">
                    {{ $message }}
                </p>

                <div class="space-y-4">
                    <a href="{{ route('checkout') }}"
                       class="inline-block bg-indigo-600 text-white py-3 px-6 rounded-lg hover:bg-indigo-700 transition duration-200 mr-4">
                        {{ __('checkout.try_again') }}
                    </a>
                    <a href="{{ route('pricing') }}"
                       class="inline-block bg-gray-200 text-gray-800 py-3 px-6 rounded-lg hover:bg-gray-300 transition duration-200">
                        {{ __('checkout.choose_different_plan') }}
                    </a>
                </div>
            </div>

        @elseif($status === 'pending')
            <!-- Pending State -->
            <div class="text-center">
                <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-yellow-100 mb-6">
                    <svg class="h-8 w-8 text-yellow-600 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>

                <h3 class="text-2xl font-semibold text-gray-900 mb-4">{{ __('checkout.processing_payment') }}</h3>
                <p class="text-gray-600 mb-8">
                    {{ $message }}
                </p>

                <div class="space-y-4">
                    <button wire:click="refreshStatus"
                            class="inline-block bg-indigo-600 text-white py-3 px-6 rounded-lg hover:bg-indigo-700 transition duration-200 mr-4">
                        {{ __('checkout.check_status') }}
                    </button>
                    <a href="{{ route('uploads') }}"
                       class="inline-block bg-gray-200 text-gray-800 py-3 px-6 rounded-lg hover:bg-gray-300 transition duration-200">
                        {{ __('checkout.continue_to_dashboard') }}
                    </a>
                </div>
            </div>

        @else
            <!-- Default/Unknown State -->
            <div class="text-center">
                <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-gray-100 mb-6">
                    <svg class="h-8 w-8 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>

                <h3 class="text-2xl font-semibold text-gray-900 mb-4">{{ __('checkout.activation_status_unknown') }}</h3>
                <p class="text-gray-600 mb-8">
                    {{ $message }}
                </p>

                <div class="space-y-4">
                    <a href="{{ route('pricing') }}"
                       class="inline-block bg-indigo-600 text-white py-3 px-6 rounded-lg hover:bg-indigo-700 transition duration-200 mr-4">
                        {{ __('checkout.view_pricing') }}
                    </a>
                    <a href="{{ route('uploads') }}"
                       class="inline-block bg-gray-200 text-gray-800 py-3 px-6 rounded-lg hover:bg-gray-300 transition duration-200">
                        {{ __('checkout.go_to_dashboard') }}
                    </a>
                </div>
            </div>
        @endif
    </div>
</div>