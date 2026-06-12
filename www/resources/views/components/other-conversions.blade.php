@php
    // Get all conversions from config
    $allConversions = config('conversions.conversions', []);

    // Get page-specific settings
    $enabled = $other_conversions['enabled'] ?? false;
    $title = $other_conversions['title'] ?? 'Other Conversions';
    $subtitle = $other_conversions['subtitle'] ?? 'Convert between PDF and various document formats. Our converter preserves formatting and embedded content.';
    $selectedConversions = $other_conversions['selected_conversions'] ?? [];

    // Build display conversions from config based on selected slugs
    $displayConversions = [];

    if ($enabled && !empty($selectedConversions)) {
        foreach ($selectedConversions as $slug) {
            // Find conversion in config by slug
            if (isset($allConversions[$slug])) {
                $conversion = $allConversions[$slug];

                // Get localized name and description
                $locale = app()->getLocale();
                $name = __($conversion['trans']['name'] ?? 'conversions.default.name', [], $locale);
                $description = __($conversion['trans']['description'] ?? 'conversions.default.description', [], $locale);

                // Get localized slug
                $localizedSlug = $conversion['locales'][$locale] ?? $conversion['slug'];

                // Build display item
                $displayConversions[] = [
                    'id' => $slug,
                    'title' => $name,
                    'description' => $description,
                    'icon_text' => $conversion['menu']['icon_text'] ?? 'PDF',
                    'icon_color' => $conversion['menu']['color'] ?? '#3B82F6',
                    'link' => '/' . $localizedSlug,
                ];
            }
        }
    }
@endphp

@if($enabled && !empty($displayConversions))
<section class="py-16 bg-gray-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-12">
            <h2 class="text-3xl font-bold text-gray-900 mb-4">{{ $title }}</h2>
            <p class="text-lg text-gray-600 max-w-3xl mx-auto">
                {{ $subtitle }}
            </p>
        </div>

        <div class="grid md:grid-cols-3 gap-6">
            @foreach($displayConversions as $conversion)
            <div class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow">
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center mr-4"
                         style="background-color: {{ $conversion['icon_color'] }}20;">
                        <span class="text-lg font-bold"
                              style="color: {{ $conversion['icon_color'] }};">{{ $conversion['icon_text'] }}</span>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">{{ $conversion['title'] }}</h3>
                        <p class="text-sm text-gray-600">{{ $conversion['description'] }}</p>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="{{ $conversion['link'] }}" class="inline-flex items-center text-sm font-medium hover:underline transition-colors"
                       style="color: {{ $conversion['icon_color'] }};">
                        {{ __('common.learn_more', [], app()->getLocale()) }}
                        <svg class="ml-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </a>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>
@endif
