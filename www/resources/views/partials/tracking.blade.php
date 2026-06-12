{{--
    Central Tracking Scripts Partial

    Include this once in your layout to enable all tracking scripts.
    Each script is controlled via its own env variable.

    Usage: @include('partials.tracking')
--}}

{{-- Analytics Tracker (AI Factory) --}}
@if(config('analytics.client_tracking_enabled', true))
    @include('partials.analytics-tracker')
@endif

{{-- Google Analytics is managed via Consent Mode v2 in <x-klaro-scripts /> --}}
