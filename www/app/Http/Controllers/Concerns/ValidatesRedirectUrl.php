<?php

namespace App\Http\Controllers\Concerns;

trait ValidatesRedirectUrl
{
    /**
     * Validate redirect URL to prevent open redirect attacks.
     * Only allows safe relative URLs (single leading slash, no backslash,
     * no protocol-relative tricks) or absolute URLs on the same host.
     *
     * Security note (M6): browsers normalise `\\evil.com`, `/\evil.com`,
     * `/\/evil.com`, etc. as protocol-relative URLs in some contexts. We
     * reject any string containing a backslash, control characters, or
     * starting with `//`.
     */
    protected function validateRedirectUrl(string $url): string
    {
        $default = '/';

        $url = trim($url);

        if ($url === '') {
            return $default;
        }

        // Reject any backslash anywhere — never legitimate in a URL path.
        if (str_contains($url, '\\')) {
            return $default;
        }

        // Reject protocol-relative URLs.
        if (str_starts_with($url, '//')) {
            return $default;
        }

        // Reject control characters (incl. \t, \n, \r) that some browsers
        // strip before resolving the URL.
        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return $default;
        }

        $parsed = parse_url($url);

        if ($parsed === false) {
            return $default;
        }

        // Pure relative path: no scheme, no host. Must start with `/`.
        if (! isset($parsed['scheme']) && ! isset($parsed['host'])) {
            return str_starts_with($url, '/') ? $url : $default;
        }

        // Absolute URL: must use http(s) AND point at our host.
        $scheme = strtolower($parsed['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            return $default;
        }

        if (isset($parsed['host'])) {
            $appHost = parse_url(config('app.url'), PHP_URL_HOST);
            if (strcasecmp($parsed['host'], (string) $appHost) !== 0) {
                return $default;
            }
        }

        return $url;
    }
}
