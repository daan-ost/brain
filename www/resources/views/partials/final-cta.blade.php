@props(['entry' => null])

@php
$finalCta = $entry?->final_cta;
$ctaTitle = $finalCta?->title ?? __('final_cta.default_title', [], app()->getLocale());
$ctaDescription = $finalCta?->description ?? __('final_cta.default_description', [], app()->getLocale());
$ctaButtonLabel = $finalCta?->button_label ?? __('final_cta.default_button_label', [], app()->getLocale());
$ctaButtonUrl = $finalCta?->button_url ?? '/';
@endphp

<!-- Final CTA Section -->
<section class="py-20 bg-gradient-to-r from-slate-900 via-purple-900 to-slate-900">
    <div class="container mx-auto px-4">
        <div class="max-w-4xl mx-auto text-center">
            <div class="w-20 h-20 bg-white/10 rounded-3xl flex items-center justify-center mb-8 mx-auto">
                <svg class="h-10 w-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                </svg>
            </div>

            <h2 class="text-4xl md:text-5xl font-bold mb-8 text-white text-balance"
                style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                {{ $ctaTitle }}
            </h2>

            @if($ctaDescription)
            <p class="text-xl text-gray-300 leading-relaxed mb-12 max-w-2xl mx-auto text-pretty"
                style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                {{ $ctaDescription }}
            </p>
            @endif

            <!-- CTA Button -->
            <div class="flex justify-center space-x-4 mb-8">
                <a href="{{ $ctaButtonUrl }}"
                    class="inline-flex items-center px-8 py-4 bg-white text-purple-900 font-semibold rounded-xl hover:bg-gray-100 transition-colors shadow-lg hover:shadow-xl"
                    style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                    {{ $ctaButtonLabel }}
                    <svg class="ml-2 w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </a>

                <a href="/pricing"
                    class="inline-flex items-center px-8 py-4 border-2 border-white/30 text-white font-semibold rounded-xl hover:bg-white/10 transition-colors"
                    style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                    {{ __('final_cta.view_pricing', [], app()->getLocale()) }}
                </a>
            </div>

            <!-- Trust indicators -->
            <div class="flex items-center justify-center space-x-8 text-white/70 text-sm">
                <div class="flex items-center space-x-2">
                    <svg class="w-4 h-4 text-green-400" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z" />
                        <path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="2" fill="none" />
                    </svg>
                    <span>{{ __('final_cta.trust_ssl', [], app()->getLocale()) }}</span>
                </div>
                <div class="flex items-center space-x-2">
                    <svg class="w-4 h-4 text-green-400" fill="currentColor" viewBox="0 0 24 24">
                        <path
                            d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
                    </svg>
                    <span>{{ __('final_cta.trust_gdpr', [], app()->getLocale()) }}</span>
                </div>
                <div class="flex items-center space-x-2">
                    <svg class="w-4 h-4 text-green-400" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>{{ __('final_cta.trust_no_signup', [], app()->getLocale()) }}</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Alternative CTA - Card Style -->
<!--
<section class="py-20 bg-gray-50">
    <div class="container mx-auto px-4">
        <div class="max-w-4xl mx-auto">
            <div class="bg-white rounded-3xl shadow-2xl overflow-hidden">
                <div class="p-12 text-center">
                    <div
                        class="w-16 h-16 bg-gradient-to-r from-blue-500 to-purple-600 rounded-2xl flex items-center justify-center mb-8 mx-auto">
                        <svg class="h-8 w-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 100 4m0-4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 100 4m0-4v2m0-6V4">
                            </path>
                        </svg>
                    </div>
                 
                    <h3
                        class="text-3xl font-bold mb-4 text-gray-900"
                        style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;"
                    >
                        {{ __('final_cta.ready_to_start', [], app()->getLocale()) }}
                    </h3>

                    <p
                        class="text-lg text-gray-600 mb-8 max-w-2xl mx-auto"
                        style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;"
                    >
                        {{ __('final_cta.ready_description', [], app()->getLocale()) }}
                    </p>

             
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                        <div class="text-center">
                            <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center mb-3 mx-auto">
                                <svg class="w-6 h-6 text-green-600" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </div>
                            <div class="font-semibold text-gray-900">{{ __('final_cta.feature_1', [], app()->getLocale()) }}</div>
                        </div>

                        <div class="text-center">
                            <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center mb-3 mx-auto">
                                <svg class="w-6 h-6 text-blue-600" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/>
                                </svg>
                            </div>
                            <div class="font-semibold text-gray-900">{{ __('final_cta.feature_2', [], app()->getLocale()) }}</div>
                        </div>

                        <div class="text-center">
                            <div class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center mb-3 mx-auto">
                                <svg class="w-6 h-6 text-purple-600" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                </svg>
                            </div>
                            <div class="font-semibold text-gray-900">{{ __('final_cta.feature_3', [], app()->getLocale()) }}</div>
                        </div>
                    </div>

                    <a
                        href="{{ $ctaButtonUrl }}"
                        class="inline-flex items-center px-8 py-4 bg-gradient-to-r from-blue-600 to-purple-600 text-white font-semibold rounded-xl hover:from-blue-700 hover:to-purple-700 transition-all duration-200 shadow-lg hover:shadow-xl transform hover:-translate-y-1"
                        style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;"
                    >
                        {{ $ctaButtonLabel }}
                        <svg class="ml-2 w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </a>
                </div>
                </div>
            </div>
        </div>
</section>
-->