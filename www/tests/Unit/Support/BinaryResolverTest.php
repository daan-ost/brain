<?php

namespace Tests\Unit\Support;

use App\Support\BinaryResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BinaryResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // De zoekpaden zijn macOS/Linux-only; op Windows is dit niet van toepassing.
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('BinaryResolver zoekpaden zijn Unix-only.');
        }
    }

    /** Een binary die op elke macOS/Linux host bestaat. */
    private function knownBinary(): string
    {
        $path = BinaryResolver::resolve('sh');
        $this->assertNotNull($path, 'Test-omgeving mist /bin/sh — kan niet draaien.');

        return $path;
    }

    #[Test]
    public function it_resolves_a_known_binary_to_an_absolute_executable_path(): void
    {
        $path = $this->knownBinary();

        $this->assertStringStartsWith('/', $path);
        $this->assertTrue(is_executable($path));
    }

    #[Test]
    public function it_returns_null_for_an_unknown_binary(): void
    {
        $this->assertNull(BinaryResolver::resolve('this-binary-does-not-exist-xyz'));
    }

    #[Test]
    public function it_resolves_independently_of_the_process_path(): void
    {
        // Dit is het eigenlijke #4-scenario: php-fpm met een lege/minimale PATH.
        // De resolver mag NIET van PATH afhangen — hij zoekt absolute dirs af.
        $originalPath = getenv('PATH');

        try {
            putenv('PATH=');
            $path = BinaryResolver::resolve('sh');

            $this->assertNotNull($path, 'Resolver moet sh vinden zelfs met lege PATH.');
            $this->assertTrue(is_executable($path));
        } finally {
            putenv($originalPath === false ? 'PATH' : "PATH={$originalPath}");
        }
    }

    #[Test]
    public function it_prefers_an_executable_config_override(): void
    {
        $known = $this->knownBinary();
        config(['services.binaries.sh' => $known]);

        $this->assertSame($known, BinaryResolver::resolve('sh', 'services.binaries.sh'));
    }

    #[Test]
    public function it_ignores_a_non_existent_config_override(): void
    {
        config(['services.binaries.sh' => '/path/that/does/not/exist']);
        $path = BinaryResolver::resolve('sh', 'services.binaries.sh');

        $this->assertNotSame('/path/that/does/not/exist', $path);
        $this->assertTrue(is_executable($path));
    }

    #[Test]
    public function it_ignores_an_existing_but_non_executable_config_override(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'binres');
        chmod($file, 0o644); // bestaat wel, maar niet executable

        try {
            config(['services.binaries.sh' => $file]);
            $path = BinaryResolver::resolve('sh', 'services.binaries.sh');

            $this->assertNotSame($file, $path, 'Niet-executable override mag niet gekozen worden.');
            $this->assertTrue(is_executable($path));
        } finally {
            @unlink($file);
        }
    }

    #[Test]
    public function it_ignores_an_empty_or_non_string_config_override(): void
    {
        config(['services.binaries.sh' => null]);
        $this->assertNotNull(BinaryResolver::resolve('sh', 'services.binaries.sh'));

        config(['services.binaries.sh' => ['/a', '/b']]);
        $this->assertNotNull(BinaryResolver::resolve('sh', 'services.binaries.sh'));
    }
}
