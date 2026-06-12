@php
    use Illuminate\Support\Facades\Request;

    $shouldSkip = Request::is('beheer/*', 'cp/*');
    $ga4Id = config('services.google.analytics_id');
    $isProduction = app()->environment('production');
@endphp

@unless($shouldSkip)
    {{-- Klaro CSS --}}
    @vite('resources/css/klaro.css')

    {{-- Google Consent Mode v2 + GA4: loads always, respects consent state --}}
    @if($ga4Id && $isProduction)
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $ga4Id }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}

            gtag('consent', 'default', {
                'analytics_storage': 'denied',
                'ad_storage': 'denied',
                'ad_user_data': 'denied',
                'ad_personalization': 'denied',
                'wait_for_update': 500
            });

            gtag('js', new Date());
            gtag('config', '{{ $ga4Id }}', {
                'anonymize_ip': true
            });
        </script>
    @endif

    {{-- Klaro Config (inline, environment-aware, using Laravel translations) --}}
    <script>
        var klaroConfig = {
            version: 1,
            elementID: 'klaro',
            storageMethod: 'localStorage',
            storageName: 'app_consent',
            htmlTexts: true,
            acceptAll: true,
            hideDeclineAll: false,
            hideLearnMore: false,
            mustConsent: false,
            disablePoweredBy: true,
            privacyPolicy: '/nl/privacydocument',
            lang: document.documentElement.lang || 'en',
            translations: {
                nl: {
                    privacyPolicyUrl: '/nl/privacydocument',
                    consentModal: {
                        title: @json(__('cookies.modal.title', [], 'nl')),
                        description: @json(__('cookies.modal.description', [], 'nl'))
                    },
                    consentNotice: {
                        description: @json(__('cookies.notice.description', [], 'nl')) + ' <a href="/nl/privacydocument">' + @json(__('cookies.notice.learn_more', [], 'nl')) + '</a>'
                    },
                    acceptAll: @json(__('cookies.buttons.accept', [], 'nl')),
                    declineAll: @json(__('cookies.buttons.decline', [], 'nl')),
                    save: @json(__('cookies.buttons.save', [], 'nl')),
                    ok: @json(__('cookies.buttons.accept', [], 'nl')),
                    close: @json(__('cookies.buttons.close', [], 'nl')),
                    purposes: {
                        analytics: @json(__('cookies.purposes.analytics', [], 'nl')),
                        functional: @json(__('cookies.purposes.functional', [], 'nl'))
                    },
                    'google-analytics': {
                        title: @json(__('cookies.services.google_analytics.title', [], 'nl')),
                        description: @json(__('cookies.services.google_analytics.description', [], 'nl'))
                    },
                    'functional': {
                        title: @json(__('cookies.services.functional.title', [], 'nl')),
                        description: @json(__('cookies.services.functional.description', [], 'nl'))
                    }
                },
                en: {
                    privacyPolicyUrl: '/en/privacy',
                    consentModal: {
                        title: @json(__('cookies.modal.title', [], 'en')),
                        description: @json(__('cookies.modal.description', [], 'en'))
                    },
                    consentNotice: {
                        description: @json(__('cookies.notice.description', [], 'en')) + ' <a href="/en/privacy">' + @json(__('cookies.notice.learn_more', [], 'en')) + '</a>'
                    },
                    acceptAll: @json(__('cookies.buttons.accept', [], 'en')),
                    declineAll: @json(__('cookies.buttons.decline', [], 'en')),
                    save: @json(__('cookies.buttons.save', [], 'en')),
                    ok: @json(__('cookies.buttons.accept', [], 'en')),
                    close: @json(__('cookies.buttons.close', [], 'en')),
                    purposes: {
                        analytics: @json(__('cookies.purposes.analytics', [], 'en')),
                        functional: @json(__('cookies.purposes.functional', [], 'en'))
                    },
                    'google-analytics': {
                        title: @json(__('cookies.services.google_analytics.title', [], 'en')),
                        description: @json(__('cookies.services.google_analytics.description', [], 'en'))
                    },
                    'functional': {
                        title: @json(__('cookies.services.functional.title', [], 'en')),
                        description: @json(__('cookies.services.functional.description', [], 'en'))
                    }
                }
            },
            services: [
                @if($isProduction)
                {
                    name: 'google-analytics',
                    title: 'Google Analytics 4',
                    purposes: ['analytics'],
                    cookies: ['_ga', /^_ga_.*$/, '_gid', '_gat'],
                    required: false,
                    default: true,
                    onlyOnce: true,
                    callback: function(consent, service) {
                        if (typeof gtag === 'function') {
                            gtag('consent', 'update', {
                                'analytics_storage': consent ? 'granted' : 'denied'
                            });
                        }
                    }
                },
                @endif
                {
                    name: 'functional',
                    purposes: ['functional'],
                    required: true,
                    default: true
                }
            ]
        };
    </script>

    {{-- Klaro Library --}}
    <script defer src="{{ asset('vendor/klaro/klaro-no-css.js') }}"></script>
@endunless
