<?php

if (! function_exists('linkify')) {
    /**
     * Convert plain text URLs to clickable links.
     *
     * This function takes already-escaped text and converts URLs to anchor tags.
     * IMPORTANT: Always escape user input BEFORE passing to this function.
     *
     * @param  string  $text  The text with URLs (should be pre-escaped with e())
     * @param  string  $class  Optional CSS classes for the link
     * @return string Text with URLs converted to clickable links
     *
     * @example
     * // Safe usage with user content:
     * {!! linkify(e($userContent)) !!}
     *
     * // With custom classes:
     * {!! linkify(e($userContent), 'text-blue-500 hover:underline') !!}
     */
    function linkify(string $text, string $class = 'underline'): string
    {
        // Pattern matches http:// and https:// URLs
        // Stops at whitespace or common sentence-ending characters
        $pattern = '~(https?://[^\s<>"\')\]]+)~i';

        return preg_replace(
            $pattern,
            '<a href="$1" target="_blank" rel="noopener nofollow" class="'.$class.'">$1</a>',
            $text
        );
    }
}

if (! function_exists('strip_html_for_preview')) {
    /**
     * Strip HTML tags and decode entities for plain text preview.
     *
     * Useful for email previews or notifications where HTML is not supported.
     *
     * @param  string  $html  The HTML content to strip
     * @param  int  $limit  Optional character limit
     * @return string Plain text content
     */
    function strip_html_for_preview(string $html, int $limit = 0): string
    {
        // Convert common block elements to newlines
        $text = preg_replace('/<(br|p|div|li)[^>]*>/i', "\n", $html);

        // Strip all remaining HTML tags
        $text = strip_tags($text);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s*\n/', "\n\n", $text);
        $text = trim($text);

        // Apply limit if specified
        if ($limit > 0) {
            $text = \Illuminate\Support\Str::limit($text, $limit);
        }

        return $text;
    }
}
