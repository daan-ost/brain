<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>API Documentation - {{ config('app.name') }}</title>

    <!-- Swagger UI CSS -->
    <link rel="stylesheet" type="text/css" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">

    <style>
        html {
            box-sizing: border-box;
            overflow: -moz-scrollbars-vertical;
            overflow-y: scroll;
        }

        *,
        *:before,
        *:after {
            box-sizing: inherit;
        }

        body {
            margin: 0;
            background: #fafafa;
        }

        /* Custom header */
        .api-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .api-header h1 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .api-header a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 4px;
            transition: background 0.2s;
        }

        .api-header a:hover {
            background: rgba(255,255,255,0.1);
        }

        .api-header .nav-links {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        /* Swagger UI customizations */
        .swagger-ui .topbar {
            display: none;
        }

        .swagger-ui .info {
            margin: 30px 0;
        }

        .swagger-ui .info .title {
            color: #3b4151;
        }

        .swagger-ui .scheme-container {
            background: #fff;
            box-shadow: 0 1px 2px 0 rgba(0,0,0,.15);
            padding: 20px;
            margin: 0 0 20px;
        }

        /* Auth section styling */
        .swagger-ui .auth-wrapper {
            display: flex;
            justify-content: flex-end;
        }

        .swagger-ui .btn.authorize {
            background-color: #667eea;
            border-color: #667eea;
            color: white;
        }

        .swagger-ui .btn.authorize:hover {
            background-color: #5a6fd6;
        }

        .swagger-ui .btn.authorize svg {
            fill: white;
        }

        /* Token info box */
        .token-info {
            background: #e8f4fd;
            border: 1px solid #b8daff;
            border-radius: 8px;
            padding: 1rem 1.5rem;
            margin: 1rem 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .token-info svg {
            flex-shrink: 0;
            color: #004085;
        }

        .token-info p {
            margin: 0;
            color: #004085;
        }

        .token-info a {
            color: #004085;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <!-- Custom Header -->
    <div class="api-header">
        <h1>{{ config('app.name') }} API v1</h1>
        <div class="nav-links">
            @auth
                <a href="{{ route('profile.api-tokens') }}">Manage Tokens</a>
                <a href="{{ route('dashboard') }}">Dashboard</a>
            @else
                <a href="{{ route('login') }}">Login</a>
                <a href="{{ route('register') }}">Sign Up</a>
            @endauth
        </div>
    </div>

    <!-- Token Info -->
    @auth
        <div class="token-info">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="16" x2="12" y2="12"></line>
                <line x1="12" y1="8" x2="12.01" y2="8"></line>
            </svg>
            <p>
                To make API requests, you need an API token.
                <a href="{{ route('profile.api-tokens') }}">Generate your API token</a> in your profile settings,
                then click the "Authorize" button below to authenticate.
            </p>
        </div>
    @else
        <div class="token-info">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="16" x2="12" y2="12"></line>
                <line x1="12" y1="8" x2="12.01" y2="8"></line>
            </svg>
            <p>
                <a href="{{ route('login') }}">Login</a> or <a href="{{ route('register') }}">create an account</a>
                to generate API tokens and start making API requests.
            </p>
        </div>
    @endauth

    <!-- Swagger UI -->
    <div id="swagger-ui"></div>

    <!-- Swagger UI JS -->
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js"></script>
    <script>
        window.onload = function() {
            const ui = SwaggerUIBundle({
                url: "/api-specs/openapi-v1.yaml",
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIStandalonePreset
                ],
                plugins: [
                    SwaggerUIBundle.plugins.DownloadUrl
                ],
                layout: "StandaloneLayout",
                persistAuthorization: true,
                displayRequestDuration: true,
                filter: true,
                showExtensions: true,
                showCommonExtensions: true,
                defaultModelsExpandDepth: 1,
                defaultModelExpandDepth: 1,
                docExpansion: 'list',
                syntaxHighlight: {
                    activate: true,
                    theme: 'monokai'
                }
            });

            window.ui = ui;
        };
    </script>
</body>
</html>
