{{--
    Analytics Tracker Partial

    Include this in your layout to enable client-side analytics tracking.
    The tracker respects the kill-switch in config/analytics.php.

    Usage: @include('partials.analytics-tracker')
--}}

@if(config('analytics.client_tracking_enabled', true))
    @php
        // Get or create analytics session
        $analyticsSession = \App\Services\SessionTrackingService::getOrCreateSession();
    @endphp

    <script>
        // Analytics configuration (server-provided)
        window.analyticsTrackingEnabled = true;
        window.analyticsSessionId = '{{ $analyticsSession->id }}';
        window.pageLoadTime = Date.now();
    </script>

    {{-- Include the analytics tracker script --}}
    <script src="{{ asset('js/analytics.js') }}" defer></script>
@else
    <script>
        // Analytics tracking disabled via kill-switch
        window.analyticsTrackingEnabled = false;
    </script>
@endif
