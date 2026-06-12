<?php

namespace Tests\Unit\Concerns;

use App\Http\Controllers\Concerns\ValidatesRedirectUrl;
use Tests\TestCase;

class ValidatesRedirectUrlTest extends TestCase
{
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://example.test']);

        $this->subject = new class {
            use ValidatesRedirectUrl {
                validateRedirectUrl as public;
            }
        };
    }

    public static function safeUrls(): array
    {
        return [
            ['/', '/'],
            ['/dashboard', '/dashboard'],
            ['/foo/bar?x=1', '/foo/bar?x=1'],
            ['https://example.test/foo', 'https://example.test/foo'],
            ['https://EXAMPLE.test/foo', 'https://EXAMPLE.test/foo'], // case-insensitive host
        ];
    }

    public static function unsafeUrls(): array
    {
        return [
            'protocol-relative'         => ['//evil.com'],
            'protocol-relative-path'    => ['//evil.com/x'],
            'backslash-protocol-rel'    => ['\\\\evil.com'],
            'forward-then-back'         => ['/\\evil.com'],
            'forward-back-slash'        => ['/\\/evil.com'],
            'tab-injected'              => ["/\tevil.com"],
            'newline-injected'          => ["/\nevil.com"],
            'cr-injected'               => ["/\r/evil.com"],
            'absolute-other-host'       => ['https://evil.com/x'],
            'javascript-scheme'         => ['javascript:alert(1)'],
            'data-scheme'               => ['data:text/html,<script>alert(1)</script>'],
            'file-scheme'               => ['file:///etc/passwd'],
            'empty'                     => [''],
            'whitespace-only'           => ['   '],
            'no-leading-slash'          => ['dashboard'],
        ];
    }

    /** @dataProvider safeUrls */
    public function test_safe_urls_pass_through(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->subject->validateRedirectUrl($input));
    }

    /** @dataProvider unsafeUrls */
    public function test_unsafe_urls_fall_back_to_root(string $input): void
    {
        $this->assertSame('/', $this->subject->validateRedirectUrl($input));
    }
}
