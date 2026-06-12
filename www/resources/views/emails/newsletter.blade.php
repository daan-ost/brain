<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 16px;
            line-height: 1.6;
            color: #1f2937;
            background-color: #f3f4f6;
        }
        .wrapper {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .email-container {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .header {
            background-color: {{ config('newsletter.brand_color', '#53b3ae') }};
            color: #ffffff;
            padding: 30px 40px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .content {
            padding: 40px;
        }
        .content h2 {
            color: #111827;
            font-size: 20px;
            margin-top: 24px;
            margin-bottom: 12px;
        }
        .content p {
            margin: 0 0 16px 0;
        }
        .content ul, .content ol {
            margin: 0 0 16px 0;
            padding-left: 24px;
        }
        .content li {
            margin-bottom: 8px;
        }
        .content a {
            color: {{ config('newsletter.brand_color', '#53b3ae') }};
            text-decoration: underline;
        }
        .footer {
            padding: 30px 40px;
            background-color: #f9fafb;
            text-align: center;
            border-top: 1px solid #e5e7eb;
        }
        .footer p {
            margin: 0 0 8px 0;
            font-size: 14px;
            color: #6b7280;
        }
        .unsubscribe-link {
            font-size: 12px;
            color: #9ca3af;
        }
        .unsubscribe-link a {
            color: #9ca3af;
        }
        @media (max-width: 640px) {
            .wrapper {
                padding: 10px;
            }
            .header, .content, .footer {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="email-container">
            <div class="header">
                <h1>{{ config('app.name') }}</h1>
            </div>

            <div class="content">
                {!! $body !!}
            </div>

            <div class="footer">
                <p>&copy; {{ date('Y') }} {{ config('app.name') }}. {{ $locale === 'nl' ? 'Alle rechten voorbehouden.' : 'All rights reserved.' }}</p>
                @if($unsubscribeUrl)
                    <p class="unsubscribe-link">
                        @if($locale === 'nl')
                            <a href="{{ $unsubscribeUrl }}">Uitschrijven van nieuwsbrief</a>
                        @else
                            <a href="{{ $unsubscribeUrl }}">Unsubscribe from newsletter</a>
                        @endif
                    </p>
                @endif
            </div>
        </div>
    </div>
</body>
</html>
