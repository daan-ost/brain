<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RemoveDuplicateAlpine
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only process HTML responses
        if ($response->headers->get('Content-Type') &&
            str_contains($response->headers->get('Content-Type'), 'text/html')) {

            $content = $response->getContent();

            // Remove the Alpine CDN script that Livewire injects (with the comment)
            // Livewire 3 already bundles Alpine, so the CDN causes "multiple instances" error
            $content = preg_replace(
                '/\s*<!-- Alpine\.js for interactivity -->\s*<script defer src="https:\/\/unpkg\.com\/alpinejs@3\.x\.x\/dist\/cdn\.min\.js"><\/script>/',
                '',
                $content
            );

            $response->setContent($content);
        }

        return $response;
    }
}
