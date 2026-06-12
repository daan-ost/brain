<?php

namespace Tests\Unit\Services;

use App\Services\AnalyticsService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class AnalyticsServiceTest extends TestCase
{
    /**
     * Helper to call the private normalizeReferrer method
     */
    private function callNormalizeReferrer(?string $referrer): ?string
    {
        $method = new ReflectionMethod(AnalyticsService::class, 'normalizeReferrer');
        $method->setAccessible(true);

        return $method->invoke(null, $referrer);
    }

    #[Test]
    public function normalizes_internal_referrer_to_path_only(): void
    {
        $appUrl = config('app.url');

        $result = $this->callNormalizeReferrer($appUrl.'/profile/organization');

        $this->assertEquals('/profile/organization', $result);
    }

    #[Test]
    public function keeps_full_url_for_external_referrer(): void
    {
        $result = $this->callNormalizeReferrer('https://google.com/search?q=pdf+converter');

        $this->assertEquals('https://google.com/search?q=pdf+converter', $result);
    }

    #[Test]
    public function preserves_query_string_for_internal_referrer(): void
    {
        $appUrl = config('app.url');

        $result = $this->callNormalizeReferrer($appUrl.'/profile/transactions?tab=api&page=2');

        $this->assertEquals('/profile/transactions?tab=api&page=2', $result);
    }

    #[Test]
    public function returns_null_for_null_input(): void
    {
        $result = $this->callNormalizeReferrer(null);

        $this->assertNull($result);
    }

    #[Test]
    public function returns_null_for_empty_string(): void
    {
        $result = $this->callNormalizeReferrer('');

        $this->assertNull($result);
    }

    #[Test]
    public function handles_root_path_correctly(): void
    {
        $appUrl = config('app.url');

        $result = $this->callNormalizeReferrer($appUrl.'/');

        $this->assertEquals('/', $result);
    }

    #[Test]
    public function handles_path_without_trailing_slash(): void
    {
        $appUrl = config('app.url');

        $result = $this->callNormalizeReferrer($appUrl);

        // When no path is provided, parse_url returns null for path
        $this->assertEquals('/', $result);
    }

    #[Test]
    public function handles_subdomain_as_external(): void
    {
        // If app is at app:8890, api.app:8890 should be external
        config(['app.url' => 'https://www.example.com']);

        $result = $this->callNormalizeReferrer('https://api.example.com/webhook');

        $this->assertEquals('https://api.example.com/webhook', $result);
    }

    #[Test]
    public function handles_same_host_different_port_as_internal(): void
    {
        // Note: Ports are not compared - only the host matters
        // This is intentional: localhost:3000 and localhost:8080 are considered the same host
        config(['app.url' => 'https://localhost:8080']);

        $result = $this->callNormalizeReferrer('https://localhost:3000/page');

        // Same host (localhost), so normalized to path only
        $this->assertEquals('/page', $result);
    }

    #[Test]
    public function handles_http_vs_https_same_domain(): void
    {
        config(['app.url' => 'https://example.com']);

        // HTTP referrer on HTTPS app - same host, should normalize
        $result = $this->callNormalizeReferrer('http://example.com/page');

        $this->assertEquals('/page', $result);
    }

    #[Test]
    public function preserves_fragment_in_external_url(): void
    {
        $result = $this->callNormalizeReferrer('https://docs.google.com/document#section1');

        $this->assertEquals('https://docs.google.com/document#section1', $result);
    }

    #[Test]
    public function handles_complex_query_string(): void
    {
        $appUrl = config('app.url');

        $result = $this->callNormalizeReferrer($appUrl.'/search?q=test&filter[type]=pdf&sort=desc');

        $this->assertEquals('/search?q=test&filter[type]=pdf&sort=desc', $result);
    }

    #[Test]
    public function handles_encoded_characters_in_path(): void
    {
        $appUrl = config('app.url');

        $result = $this->callNormalizeReferrer($appUrl.'/files/my%20document.pdf');

        $this->assertEquals('/files/my%20document.pdf', $result);
    }

    #[Test]
    public function handles_malformed_url_gracefully(): void
    {
        // Malformed URL without proper scheme
        $result = $this->callNormalizeReferrer('not-a-valid-url');

        // Should return as-is since parse_url can't extract host
        $this->assertEquals('not-a-valid-url', $result);
    }
}
