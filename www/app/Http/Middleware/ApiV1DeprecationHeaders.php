<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiV1DeprecationHeaders
{
    /**
     * Add deprecation notice headers to all API v1 responses.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Add deprecation headers
        $response->headers->set(
            'X-API-Deprecation-Notice',
            'v1 API will be shut down on June 1, 2026'
        );

        $response->headers->set(
            'X-API-Shutdown-Date',
            '2026-06-01'
        );

        $response->headers->set(
            'X-API-Migration-Guide',
            config('api.v2_documentation_url', 'https://example.com/developers/api-changes')
        );

        $response->headers->set(
            'X-API-Version',
            '1.0'
        );

        return $response;
    }
}
