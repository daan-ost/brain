<x-landing-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Processing Payment') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
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
                        
                        <!-- Step 3 - Processing -->
                        <div class="flex items-center">
                            <div class="bg-indigo-600 text-white rounded-full h-10 w-10 flex items-center justify-center text-sm font-medium">
                                <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-white"></div>
                            </div>
                            <span class="ml-2 text-indigo-600 font-medium">{{ __('checkout.processing_payment') }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Processing Card -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-8 text-center">
                    <div class="animate-spin rounded-full h-16 w-16 border-b-4 border-indigo-600 mx-auto mb-6"></div>
                    
                    <h3 class="text-2xl font-bold text-gray-900 mb-4">
                        {{ __('checkout.processing_payment') }}
                    </h3>

                    <p class="text-gray-600 mb-6">
                        {{ __('checkout.processing_payment_message') }}
                    </p>

                    <div id="status-message" class="text-sm text-gray-500 mb-4">
                        {{ __('checkout.check_status') }}
                    </div>
                    
                    <!-- Hidden elements for different states -->
                    <div id="success-state" class="hidden">
                        <div class="text-green-600 mb-4">
                            <svg class="mx-auto h-16 w-16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold text-green-600 mb-4">{{ __('checkout.payment_successful') }}</h3>
                        <p class="text-gray-600 mb-6">{{ __('checkout.payment_successful_message') }}</p>
                    </div>
                    
                    <div id="error-state" class="hidden">
                        <div class="text-red-600 mb-4">
                            <svg class="mx-auto h-16 w-16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold text-red-600 mb-4">{{ __('checkout.payment_failed') }}</h3>
                        <p class="text-gray-600 mb-6" id="error-message">{{ __('checkout.payment_failed') }}</p>
                        <a href="{{ $error_url }}" class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700 active:bg-red-900 focus:outline-none focus:border-red-900 focus:ring ring-red-300 disabled:opacity-25 transition ease-in-out duration-150">
                            {{ __('checkout.try_again') }}
                        </a>
                    </div>
                    
                    <!-- Fallback timeout -->
                    <div id="timeout-state" class="hidden">
                        <div class="text-yellow-600 mb-4">
                            <svg class="mx-auto h-16 w-16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold text-yellow-600 mb-4">{{ __('checkout.activation_status_unknown') }}</h3>
                        <p class="text-gray-600 mb-6">{{ __('checkout.processing_payment_message') }}</p>
                        <div class="flex justify-center gap-4">
                            <a href="{{ $success_url }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:border-indigo-900 focus:ring ring-indigo-300 disabled:opacity-25 transition ease-in-out duration-150">
                                {{ __('checkout.continue_to_dashboard') }}
                            </a>
                            <a href="{{ route('pricing') }}" class="inline-flex items-center px-4 py-2 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 active:bg-gray-900 focus:outline-none focus:border-gray-900 focus:ring ring-gray-300 disabled:opacity-25 transition ease-in-out duration-150">
                                {{ __('checkout.back_to_pricing') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const pollUrl = '{{ $poll_url }}';
            const successUrl = '{{ $success_url }}';
            const errorUrl = '{{ $error_url }}';
            
            const statusMessage = document.getElementById('status-message');
            const successState = document.getElementById('success-state');
            const errorState = document.getElementById('error-state');
            const timeoutState = document.getElementById('timeout-state');
            const errorMessage = document.getElementById('error-message');
            
            let pollCount = 0;
            const maxPolls = 60; // 5 minutes maximum (5 seconds * 60)
            
            function pollOrderStatus() {
                pollCount++;
                
                fetch(pollUrl)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const order = data.order;
                            statusMessage.textContent = `Order status: ${order.status} (${pollCount}/${maxPolls})`;
                            
                            if (order.is_paid) {
                                // Payment successful
                                document.querySelector('.animate-spin').style.display = 'none';
                                statusMessage.style.display = 'none';
                                successState.classList.remove('hidden');
                                
                                setTimeout(() => {
                                    window.location.href = successUrl;
                                }, 2000);
                                return;
                            }
                            
                            if (order.is_failed) {
                                // Payment failed
                                document.querySelector('.animate-spin').style.display = 'none';
                                statusMessage.style.display = 'none';
                                errorState.classList.remove('hidden');
                                errorMessage.textContent = `Payment ${order.status.toLowerCase()}. Please try again.`;
                                return;
                            }
                            
                            // Still pending, continue polling
                            if (pollCount >= maxPolls) {
                                // Timeout reached
                                document.querySelector('.animate-spin').style.display = 'none';
                                statusMessage.style.display = 'none';
                                timeoutState.classList.remove('hidden');
                                return;
                            }
                            
                            // Poll again after 5 seconds
                            setTimeout(pollOrderStatus, 5000);
                        } else {
                            console.error('API error:', data.error);
                            statusMessage.textContent = `Error checking status: ${data.error}`;
                            
                            if (pollCount >= maxPolls) {
                                timeoutState.classList.remove('hidden');
                                return;
                            }
                            
                            // Retry after 5 seconds
                            setTimeout(pollOrderStatus, 5000);
                        }
                    })
                    .catch(error => {
                        console.error('Network error:', error);
                        statusMessage.textContent = 'Network error checking payment status...';
                        
                        if (pollCount >= maxPolls) {
                            timeoutState.classList.remove('hidden');
                            return;
                        }
                        
                        // Retry after 5 seconds
                        setTimeout(pollOrderStatus, 5000);
                    });
            }
            
            // Start polling immediately
            pollOrderStatus();
        });
    </script>
</x-landing-layout>