@props(['entry' => null])

@php
$features = $entry?->features ?? [];
// Handle both arrays and collections
$hasFeatures = is_array($features) ? !empty($features) : $features->isNotEmpty();
@endphp

@if($hasFeatures)
<section class="py-20 content-section -mt-16">
    <div class="container mx-auto px-4">
        <!-- Trust Indicators -->
        <div class="flex items-center justify-center space-x-6 mb-16">
            <div class="flex items-center space-x-2 text-gray-600 text-sm">
                <svg class="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z" />
                    <path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="2" fill="none" />
                </svg>
                <span>{{ __('hero.trust_ssl', [], app()->getLocale()) }}</span>
            </div>
            <div class="flex items-center space-x-2 text-gray-600 text-sm">
                <svg class="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 24 24">
                    <path
                        d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
                </svg>
                <span>{{ __('hero.trust_gdpr', [], app()->getLocale()) }}</span>
            </div>
            <div class="flex items-center space-x-2 text-gray-600 text-sm">
                <svg class="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z" />
                    <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" stroke="currentColor" stroke-width="2" fill="none" />
                </svg>
                <span>{{ __('hero.trust_auto_delete', [], app()->getLocale()) }}</span>
            </div>
        </div>

        <div class="text-center mb-16">
            @php
                $locale = app()->getLocale();
                $sectionTitle = __('features.default_section_title', [], $locale);
                $sectionSubtitle = __('features.default_section_subtitle', [], $locale);
            @endphp

            <h2 class="text-4xl md:text-5xl font-bold mb-6 text-balance text-gray-900"
                style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                {{ $sectionTitle }}
            </h2>
            <p class="text-xl text-gray-600 max-w-3xl mx-auto text-pretty"
                style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                {{ $sectionSubtitle }}
            </p>
        </div>

        <div class="space-y-20 mb-32">
            @foreach($features as $index => $feature)
            @php
            $isImageLeft = ($feature['alignment'] ?? 'left') === 'left' ? true : ($index % 2 === 0);
            $featureTitle = $feature['title'] ?? __('features.default_title', [], app()->getLocale());
            $featureText = $feature['text'] ?? __('features.default_description', [], app()->getLocale());
            $featureImage = $feature['image'] ?? null;
            // Use localized image helper: searches for image_nl.webp first, falls back to image.webp
            $featureImageUrl = $featureImage ? image_localized_url($featureImage) : '/placeholder.svg';
            @endphp

            <div class="flex flex-col lg:flex-row items-center gap-12 {{ !$isImageLeft ? 'lg:flex-row-reverse' : '' }}">
                <div class="flex-1">
                    <img src="{{ $featureImageUrl }}" alt="{{ $featureTitle }}"
                        class="w-full h-80 object-cover rounded-2xl shadow-lg" loading="lazy" />
                </div>
                <div class="flex-1 space-y-6">
                    <h3 class="text-3xl font-bold text-black"
                        style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                        {{ $featureTitle }}
                    </h3>
                    <div class="text-lg text-gray-600 leading-relaxed [&_a]:text-blue-600 [&_a]:underline [&_a:hover]:text-blue-800"
                        style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                        {!! \Illuminate\Support\Str::markdown($featureText) !!}
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>

<!-- Why Choose Us Section -->
<section class="py-20 bg-gradient-to-br from-gray-50 to-gray-100">
    <div class="container mx-auto px-4">
        <div class="text-center mb-16">
            <h2 class="text-4xl md:text-5xl font-bold mb-6 text-gray-900">
                {{ __('features.why_choose_title', [], app()->getLocale()) }}
            </h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <!-- Voorbeeld fallback blok -->
            <div class="bg-white rounded-2xl p-8 shadow-lg">
                <div class="mb-6">
                    <div class="w-16 h-16 bg-blue-100 rounded-xl flex items-center justify-center mb-4">
                        <svg class="w-8 h-8 text-blue-600" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z" />
                            <path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="2" fill="none" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold mb-3 text-gray-900">
                        {{ __('features.security_title', [], app()->getLocale()) }}</h3>
                    <p class="text-gray-600 leading-relaxed">
                        {{ __('features.security_description', [], app()->getLocale()) }}</p>
                </div>
            </div>
            {{-- Je kunt hier de andere 5 hardcoded fallback-items laten staan --}}
        </div>
    </div>
</section>

@else
<!-- Fallback when no features are configured -->
<section class="py-20 content-section">
    <div class="container mx-auto px-4 text-center">
        <h2 class="text-4xl md:text-5xl font-bold mb-6 text-balance text-gray-900"
            style="font-family: Mark Pro, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
            {{ __('features.default_section_title', [], app()->getLocale()) }}
        </h2>
        <p class="text-xl text-gray-600 max-w-3xl mx-auto text-pretty"
            style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
            {{ __('features.default_section_subtitle', [], app()->getLocale()) }}
        </p>
    </div>
</section>
@endif