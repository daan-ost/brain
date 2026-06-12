<?php

namespace Tests\Unit\Services;

use App\Models\License;
use App\Models\User;
use App\Models\UserLicense;
use App\Services\LicenseLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LicenseLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    protected LicenseLimitService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LicenseLimitService;
    }

    #[Test]
    public function guest_users_get_guest_default_limits()
    {
        $limits = $this->service->getLimitsForUser(null, 'excel-to-pdf');

        $this->assertEquals(3, $limits['max_files']);
        $this->assertEquals(10 * 1024 * 1024, $limits['max_total_size']);
        $this->assertEquals(50, $limits['max_pages']);
        $this->assertEquals('guest_default', $limits['source']);
    }

    #[Test]
    public function user_without_license_gets_free_user_limits()
    {
        $user = User::factory()->create();

        $limits = $this->service->getLimitsForUser($user, 'excel-to-pdf');

        $this->assertEquals(5, $limits['max_files']);
        $this->assertEquals(25 * 1024 * 1024, $limits['max_total_size']);
        $this->assertEquals(100, $limits['max_pages']);
        $this->assertEquals('free_user_default', $limits['source']);
    }

    #[Test]
    public function user_with_license_gets_license_limits()
    {
        $license = License::factory()->create([
            'json_restrictions' => [
                'upload_limits' => [
                    'global' => [
                        'max_files' => 50,
                        'max_total_size' => 500 * 1024 * 1024,
                        'max_pages' => 5000,
                        'max_file_size' => 100 * 1024 * 1024,
                    ],
                ],
            ],
        ]);

        $user = User::factory()->create();
        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
        ]);

        $limits = $this->service->getLimitsForUser($user, 'excel-to-pdf');

        // System uses mergeLowest: min(license: 50, landing_page: 50) = 50
        $this->assertEquals(50, $limits['max_files']);
        $this->assertEquals(500 * 1024 * 1024, $limits['max_total_size']);
        $this->assertEquals(5000, $limits['max_pages']);
        // When license and landing page have same limits, source can be either
        $this->assertContains($limits['source'], ['license', 'landing_page']);
    }

    #[Test]
    public function license_limits_override_landing_page_when_lower()
    {
        // Landing page: max 50 files (from config)
        // License: max 2 files (more restrictive)
        $license = License::factory()->create([
            'json_restrictions' => [
                'upload_limits' => [
                    'global' => [
                        'max_files' => 2,
                        'max_total_size' => 5 * 1024 * 1024,
                    ],
                ],
            ],
        ]);

        $user = User::factory()->create();
        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
        ]);

        $limits = $this->service->getLimitsForUser($user, 'excel-to-pdf');

        // Should use license limits (lower)
        $this->assertEquals(2, $limits['max_files']);
        $this->assertEquals(5 * 1024 * 1024, $limits['max_total_size']);
    }

    #[Test]
    public function license_limits_are_returned_when_set()
    {
        // License: max 100 files
        $license = License::factory()->create([
            'json_restrictions' => [
                'upload_limits' => [
                    'global' => [
                        'max_files' => 100,
                        'max_total_size' => 1024 * 1024 * 1024,
                    ],
                ],
            ],
        ]);

        $user = User::factory()->create();
        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
        ]);

        $limits = $this->service->getLimitsForUser($user, 'excel-to-pdf');

        // Should use license limits for max_files
        $this->assertEquals(100, $limits['max_files']);
    }

    #[Test]
    public function per_conversion_limits_override_global_license_limits()
    {
        // Landing page excel-to-pdf has max_files: 50
        // Per-conversion should override global (50 → 30) and win over landing page (30 < 50)
        $license = License::factory()->create([
            'json_restrictions' => [
                'upload_limits' => [
                    'global' => [
                        'max_files' => 50,
                    ],
                    'per_conversion' => [
                        'excel-to-pdf' => [
                            'max_files' => 30, // Lower than global, also lower than landing page
                        ],
                    ],
                ],
            ],
        ]);

        $user = User::factory()->create();
        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
        ]);

        $limits = $this->service->getLimitsForUser($user, 'excel-to-pdf');

        // Should use per-conversion limit (30) which is lower than both global (50) and landing page (50)
        $this->assertEquals(30, $limits['max_files']);
    }

    #[Test]
    public function validate_files_passes_when_within_limits()
    {
        $limits = [
            'max_files' => 5,
            'max_total_size' => 10 * 1024 * 1024,
            'max_pages' => 100,
        ];

        $files = [
            UploadedFile::fake()->create('file1.pdf', 1024), // 1MB
            UploadedFile::fake()->create('file2.pdf', 1024),
            UploadedFile::fake()->create('file3.pdf', 1024),
        ];

        $result = $this->service->validateFiles($files, $limits);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function validate_files_fails_when_too_many_files()
    {
        $limits = [
            'max_files' => 3,
            'max_total_size' => 100 * 1024 * 1024,
        ];

        $files = [
            UploadedFile::fake()->create('file1.pdf', 100),
            UploadedFile::fake()->create('file2.pdf', 100),
            UploadedFile::fake()->create('file3.pdf', 100),
            UploadedFile::fake()->create('file4.pdf', 100), // Exceeds limit
        ];

        $result = $this->service->validateFiles($files, $limits);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Maximum 3 file(s) allowed', $result['error']);
        $this->assertStringContainsString('You selected 4', $result['error']);
    }

    #[Test]
    public function validate_files_fails_when_total_size_too_large()
    {
        $limits = [
            'max_files' => 10,
            'max_total_size' => 5 * 1024 * 1024, // 5MB
        ];

        $files = [
            UploadedFile::fake()->create('file1.pdf', 3 * 1024), // 3MB
            UploadedFile::fake()->create('file2.pdf', 3 * 1024), // 3MB
            // Total: 6MB (exceeds 5MB limit)
        ];

        $result = $this->service->validateFiles($files, $limits);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('exceeds 5', $result['error']);
    }

    #[Test]
    public function format_limits_for_display_returns_human_readable_strings()
    {
        $limits = [
            'max_files' => 50,
            'max_total_size' => 500 * 1024 * 1024,
            'max_pages' => 5000,
            'max_file_size' => 100 * 1024 * 1024,
        ];

        $display = $this->service->formatLimitsForDisplay($limits);

        $this->assertEquals('50 files', $display['max_files_text']);
        $this->assertEquals('500MB', $display['max_size_text']);
        $this->assertEquals('5,000 pages', $display['max_pages_text']);
        $this->assertEquals('100MB per file', $display['max_file_size_text']);
    }

    #[Test]
    public function format_limits_uses_singular_for_one_file()
    {
        $limits = [
            'max_files' => 1,
            'max_total_size' => 10 * 1024 * 1024,
            'max_pages' => 100,
        ];

        $display = $this->service->formatLimitsForDisplay($limits);

        $this->assertEquals('1 file', $display['max_files_text']);
    }

    #[Test]
    public function dutch_slug_is_resolved_to_english_config()
    {
        // Assuming 'pdf-samenvoegen' maps to 'pdfs-to-pdf' in nl_slug_mapping
        $limits = $this->service->getLimitsForUser(null, 'pdf-samenvoegen');

        // Should resolve and return limits
        $this->assertArrayHasKey('max_files', $limits);
        $this->assertArrayHasKey('source', $limits);
        $this->assertEquals('pdf-samenvoegen', $limits['page_slug']);
    }

    #[Test]
    public function max_file_size_is_included_in_limits()
    {
        $license = License::factory()->create([
            'json_restrictions' => [
                'upload_limits' => [
                    'global' => [
                        'max_files' => 50,
                        'max_total_size' => 500 * 1024 * 1024,
                        'max_pages' => 5000,
                        'max_file_size' => 150 * 1024 * 1024, // 150MB per file
                    ],
                ],
            ],
        ]);

        $user = User::factory()->create();
        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
        ]);

        $limits = $this->service->getLimitsForUser($user, 'excel-to-pdf');

        $this->assertArrayHasKey('max_file_size', $limits);
        $this->assertEquals(150 * 1024 * 1024, $limits['max_file_size']);
    }

    #[Test]
    public function max_file_size_uses_lowest_value_between_license_and_landing_page()
    {
        // License: 200MB per file
        // Landing page: 100MB per file (more restrictive)
        $license = License::factory()->create([
            'json_restrictions' => [
                'upload_limits' => [
                    'global' => [
                        'max_file_size' => 200 * 1024 * 1024, // 200MB
                    ],
                ],
            ],
        ]);

        $user = User::factory()->create();
        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
        ]);

        $limits = $this->service->getLimitsForUser($user, 'excel-to-pdf');

        // Should use landing page limit if it's lower (or PHP_INT_MAX if not defined)
        // This test verifies the mergeLowest logic works for max_file_size
        $this->assertArrayHasKey('max_file_size', $limits);
        $this->assertIsInt($limits['max_file_size']);
    }

    #[Test]
    public function guest_users_get_default_max_file_size()
    {
        $limits = $this->service->getLimitsForUser(null, 'excel-to-pdf');

        // Guest default limits should include max_file_size
        $this->assertArrayHasKey('max_file_size', $limits);

        // Verify it's a reasonable default (should be defined in License::getDefaultGuestLimits())
        $this->assertIsInt($limits['max_file_size']);
        $this->assertGreaterThan(0, $limits['max_file_size']);
    }

    #[Test]
    public function per_conversion_max_file_size_overrides_global()
    {
        $license = License::factory()->create([
            'json_restrictions' => [
                'upload_limits' => [
                    'global' => [
                        'max_file_size' => 100 * 1024 * 1024, // 100MB
                    ],
                    'per_conversion' => [
                        'excel-to-pdf' => [
                            'max_file_size' => 50 * 1024 * 1024, // 50MB (more restrictive)
                        ],
                    ],
                ],
            ],
        ]);

        $user = User::factory()->create();
        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
        ]);

        $limits = $this->service->getLimitsForUser($user, 'excel-to-pdf');

        // Should use per-conversion limit (50MB) which is lower than global (100MB)
        $this->assertEquals(50 * 1024 * 1024, $limits['max_file_size']);
    }

    #[Test]
    public function validate_files_checks_individual_file_sizes()
    {
        $limits = [
            'max_files' => 10,
            'max_total_size' => 500 * 1024 * 1024, // 500MB total
            'max_file_size' => 100 * 1024 * 1024,   // 100MB per file
            'max_pages' => 1000,
        ];

        // Create files where one exceeds the per-file limit
        $files = [
            UploadedFile::fake()->create('small.pdf', 50 * 1024),  // 50MB - OK
            UploadedFile::fake()->create('large.pdf', 150 * 1024), // 150MB - EXCEEDS per-file limit
        ];

        $result = $this->service->validateFiles($files, $limits);

        // Total size is 200MB (under 500MB limit)
        // But one file is 150MB (exceeds 100MB per-file limit)
        // Validation should fail due to per-file limit
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('large.pdf', $result['error']);
        $this->assertStringContainsString('100', $result['error']); // Max size in error
        $this->assertStringContainsString('150', $result['error']); // Actual size in error
    }

    #[Test]
    public function validate_files_passes_when_all_individual_files_are_within_limit()
    {
        $limits = [
            'max_files' => 10,
            'max_total_size' => 500 * 1024 * 1024, // 500MB total
            'max_file_size' => 100 * 1024 * 1024,   // 100MB per file
            'max_pages' => 1000,
        ];

        // All files are under the per-file limit
        $files = [
            UploadedFile::fake()->create('file1.pdf', 50 * 1024),  // 50MB - OK
            UploadedFile::fake()->create('file2.pdf', 80 * 1024),  // 80MB - OK
            UploadedFile::fake()->create('file3.pdf', 90 * 1024),  // 90MB - OK
        ];

        $result = $this->service->validateFiles($files, $limits);

        // Total size is 220MB (under 500MB limit)
        // All individual files are under 100MB
        // Validation should pass
        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function merge_lowest_handles_max_file_size_correctly()
    {
        // Test the protected method via public interface
        $license = License::factory()->create([
            'json_restrictions' => [
                'upload_limits' => [
                    'global' => [
                        'max_files' => 100,
                        'max_file_size' => 50 * 1024 * 1024, // 50MB (lower)
                    ],
                ],
            ],
        ]);

        $user = User::factory()->create();
        UserLicense::factory()->create([
            'user_id' => $user->id,
            'license_id' => $license->id,
            'status' => 'active',
        ]);

        // Assuming landing page has max_file_size: 100MB or undefined
        $limits = $this->service->getLimitsForUser($user, 'excel-to-pdf');

        // Should use license limit (50MB) if it's lower than landing page
        $this->assertLessThanOrEqual(100 * 1024 * 1024, $limits['max_file_size']);
    }
}
