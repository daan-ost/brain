@props(['entry' => null])

@php
    $heroTitle = $entry?->hero?->title ?? __('hero.default_title', [], app()->getLocale());
    $heroSubtitle = $entry?->hero?->subtitle ?? __('hero.default_subtitle', [], app()->getLocale());
    $heroCtaLabel = $entry?->hero?->cta_label ?? __('hero.default_cta_label', [], app()->getLocale());
    $heroCtaUrl = $entry?->hero?->cta_url ?? '#';

    $stat1Value = '150K+';
    $stat1Label = __('stats.happy_clients', ['default' => 'Happy clients']);
    $stat2Value = '1M+';
    $stat2Label = __('stats.files_processed', ['default' => 'Files processed']);
    $stat3Value = '100%';
    $stat3Label = __('stats.for_business', ['default' => 'For business users']);
@endphp

<!-- Hero Section - Compact -->
<div class="container mx-auto px-4 py-4 relative z-10">
    <!-- Hero Content -->
    <div class="text-center mb-4">
        <h1
            class="text-[40px] md:text-[48px] font-black text-white mb-3 text-balance leading-[0.9] tracking-tight"
            style="font-family: MarkPro, ui-sans-serif, system-ui, 'Segoe UI', Roboto, Helvetica, Arial;"
        >
            {{ $heroTitle }}
        </h1>

        @if($heroSubtitle)
        <p
            class="text-lg text-white/90 max-w-2xl mx-auto mb-4 text-pretty leading-relaxed"
            style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;"
        >
            {{ $heroSubtitle }}
        </p>
        @endif

        <!-- Horizontal Stats Row - More Compact -->
        <div class="flex items-center justify-center mb-4 space-x-6 md:space-x-8 text-sm">
            <div class="text-center">
                <div class="text-xl md:text-2xl font-bold text-white mb-1">{{ $stat1Value }}</div>
                <div class="text-xs text-white/80">{{ $stat1Label }}</div>
            </div>
            <div class="text-center">
                <div class="text-xl md:text-2xl font-bold text-white mb-1">{{ $stat2Value }}</div>
                <div class="text-xs text-white/80">{{ $stat2Label }}</div>
            </div>
            <div class="text-center">
                <div class="text-xl md:text-2xl font-bold text-white mb-1">{{ $stat3Value }}</div>
                <div class="text-xs text-white/80">{{ $stat3Label }}</div>
            </div>
        </div>
    </div>
</div>