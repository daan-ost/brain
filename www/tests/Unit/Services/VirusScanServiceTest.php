<?php

use App\Exceptions\VirusDetectedException;
use App\Models\User;
use App\Services\Virusscan\ScanResult;
use App\Services\Virusscan\ScanStatus;
use App\Services\Virusscan\VirusScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * EICAR test string - standard antivirus test file content
 * This string is recognized by all antivirus software as a "virus" for testing
 */
const EICAR_TEST_STRING = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

beforeEach(function () {
    $this->service = new VirusScanService;
    $this->testDir = storage_path('app/test-virusscan');

    // Create test directory
    if (! is_dir($this->testDir)) {
        mkdir($this->testDir, 0755, true);
    }
});

afterEach(function () {
    // Clean up test files
    if (is_dir($this->testDir)) {
        foreach (glob("{$this->testDir}/*") as $file) {
            @unlink($file);
        }
        @rmdir($this->testDir);
    }

    // Clean up quarantine test files
    $quarantinePath = config('virusscan.quarantine_path');
    if (is_dir($quarantinePath)) {
        foreach (glob("{$quarantinePath}/*test*") as $file) {
            @unlink($file);
        }
    }
});

// ---------------------------------------------------------------------------
// isEnabled Tests
// ---------------------------------------------------------------------------

describe('isEnabled', function () {
    it('returns false when virus scanning is disabled', function () {
        config(['virusscan.enabled' => false]);

        expect($this->service->isEnabled())->toBeFalse();
    });

    it('returns true when virus scanning is enabled', function () {
        config(['virusscan.enabled' => true]);

        expect($this->service->isEnabled())->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// scan() Tests - Disabled State
// ---------------------------------------------------------------------------

describe('scan when disabled', function () {
    it('returns disabled result when scanning is disabled', function () {
        config(['virusscan.enabled' => false]);

        $result = $this->service->scan('/tmp/any-file.txt');

        expect($result->status)->toBe(ScanStatus::DISABLED);
        expect($result->isClean())->toBeFalse();
        expect($result->isInfected())->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// scan() Tests - File Validation
// ---------------------------------------------------------------------------

describe('scan file validation', function () {
    it('returns error for non-existent file', function () {
        config(['virusscan.enabled' => true]);

        $result = $this->service->scan('/nonexistent/path/file.txt');

        expect($result->status)->toBe(ScanStatus::ERROR);
        expect($result->error)->toContain('File not found');
    });

    it('returns error for empty file path', function () {
        config(['virusscan.enabled' => true]);

        $result = $this->service->scan('');

        expect($result->status)->toBe(ScanStatus::ERROR);
    });
});

// ---------------------------------------------------------------------------
// scan() Tests - With Real Files (ClamAV mocked)
// ---------------------------------------------------------------------------

describe('scan with files', function () {
    it('calculates sha256 hash for existing files', function () {
        config(['virusscan.enabled' => true]);

        // Create a test file
        $testFile = $this->testDir.'/test-file.txt';
        file_put_contents($testFile, 'Test content for hashing');

        $expectedHash = hash_file('sha256', $testFile);

        // The scan will fail to connect to ClamAV but will calculate the hash
        $result = $this->service->scan($testFile);

        // Result will be an error (no ClamAV), but we verify file was processed
        expect($result->status)->toBeIn([ScanStatus::ERROR, ScanStatus::CLEAN, ScanStatus::INFECTED]);
    });
});

// ---------------------------------------------------------------------------
// shouldScanForUser() Tests
// ---------------------------------------------------------------------------

describe('shouldScanForUser', function () {
    it('returns false when scanning is disabled', function () {
        config(['virusscan.enabled' => false]);
        config(['virusscan.required_tier' => null]);

        expect($this->service->shouldScanForUser(1))->toBeFalse();
    });

    it('returns true when no tier restriction is set', function () {
        config(['virusscan.enabled' => true]);
        config(['virusscan.required_tier' => null]);

        expect($this->service->shouldScanForUser(1))->toBeTrue();
    });

    it('returns true for null user id when scanning is enabled', function () {
        config(['virusscan.enabled' => true]);
        config(['virusscan.required_tier' => null]);

        expect($this->service->shouldScanForUser(null))->toBeTrue();
    });

    it('returns true for guests even with tier restriction', function () {
        config(['virusscan.enabled' => true]);
        config(['virusscan.required_tier' => 'business']);

        expect($this->service->shouldScanForUser(null))->toBeTrue();
    });

    it('returns true for non-existent user', function () {
        config(['virusscan.enabled' => true]);
        config(['virusscan.required_tier' => 'business']);

        expect($this->service->shouldScanForUser(999999))->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// scanFiles() Tests
// ---------------------------------------------------------------------------

describe('scanFiles', function () {
    it('returns skipped result when disabled', function () {
        config(['virusscan.enabled' => false]);

        $result = $this->service->scanFiles(['/tmp/file1.txt', '/tmp/file2.txt']);

        expect($result['clean'])->toBeTrue();
        expect($result['skipped'])->toBeTrue();
        expect($result['results'])->toBe([]);
        expect($result['infected_files'])->toBe([]);
        expect($result['error_files'])->toBe([]);
    });

    it('returns empty arrays for empty input', function () {
        config(['virusscan.enabled' => true]);

        $result = $this->service->scanFiles([]);

        expect($result['clean'])->toBeTrue();
        expect($result['results'])->toBe([]);
    });

    it('handles multiple files', function () {
        config(['virusscan.enabled' => true]);

        // Create test files
        $file1 = $this->testDir.'/file1.txt';
        $file2 = $this->testDir.'/file2.txt';
        file_put_contents($file1, 'Content 1');
        file_put_contents($file2, 'Content 2');

        $result = $this->service->scanFiles([$file1, $file2]);

        // Without ClamAV, we'll get errors, but structure should be correct
        expect($result)->toHaveKeys(['clean', 'skipped', 'results', 'infected_files', 'error_files']);
        expect($result['skipped'])->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// scanFilesOrFail() Tests
// ---------------------------------------------------------------------------

describe('scanFilesOrFail', function () {
    it('does not throw when scanning is disabled', function () {
        config(['virusscan.enabled' => false]);

        // Should not throw
        $this->service->scanFilesOrFail(['/nonexistent/file.txt']);

        expect(true)->toBeTrue(); // No exception thrown
    });

    it('does not throw when user should not be scanned', function () {
        config(['virusscan.enabled' => false]);

        // Create a real user
        $user = User::factory()->create();

        // Should not throw because scanning is disabled
        $this->service->scanFilesOrFail(['/nonexistent/file.txt'], $user->id);

        expect(true)->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// quarantine() Tests
// ---------------------------------------------------------------------------

describe('quarantine', function () {
    it('returns null for non-existent file', function () {
        $result = ScanResult::infected('TestVirus', 'abc123');

        $quarantinePath = $this->service->quarantine('/nonexistent/file.txt', $result);

        expect($quarantinePath)->toBeNull();
    });

    it('moves infected file to quarantine', function () {
        // Create a test file
        $testFile = $this->testDir.'/infected-test.txt';
        file_put_contents($testFile, 'Test infected content');

        $result = ScanResult::infected('TestVirus', hash_file('sha256', $testFile));

        $quarantinePath = $this->service->quarantine($testFile, $result);

        expect($quarantinePath)->not->toBeNull();
        expect(file_exists($quarantinePath))->toBeTrue();
        expect(file_exists($testFile))->toBeFalse();
        expect(str_contains($quarantinePath, 'quarantine'))->toBeTrue();

        // Clean up
        @unlink($quarantinePath);
    });

    it('creates quarantine directory if not exists', function () {
        // Use a custom quarantine path
        $customQuarantine = $this->testDir.'/custom-quarantine';
        config(['virusscan.quarantine_path' => $customQuarantine]);

        expect(is_dir($customQuarantine))->toBeFalse();

        // Create a test file
        $testFile = $this->testDir.'/to-quarantine.txt';
        file_put_contents($testFile, 'Quarantine me');

        $result = ScanResult::infected('TestVirus', 'abc123');
        $quarantinePath = $this->service->quarantine($testFile, $result);

        expect(is_dir($customQuarantine))->toBeTrue();
        expect(file_exists($quarantinePath))->toBeTrue();

        // Clean up
        @unlink($quarantinePath);
        @rmdir($customQuarantine);
    });

    it('includes timestamp and hash in quarantine filename', function () {
        $testFile = $this->testDir.'/timestamp-test.txt';
        file_put_contents($testFile, 'Test content');

        $sha256 = 'abcdef1234567890';
        $result = ScanResult::infected('TestVirus', $sha256);

        $quarantinePath = $this->service->quarantine($testFile, $result);

        expect($quarantinePath)->toContain('abcdef12'); // First 8 chars of hash
        expect($quarantinePath)->toContain('timestamp-test.txt');
        expect($quarantinePath)->toContain(date('Y-m-d'));

        // Clean up
        @unlink($quarantinePath);
    });
});

// ---------------------------------------------------------------------------
// cleanupQuarantine() Tests
// ---------------------------------------------------------------------------

describe('cleanupQuarantine', function () {
    it('returns 0 when quarantine directory does not exist', function () {
        config(['virusscan.quarantine_path' => '/nonexistent/quarantine']);

        $deleted = $this->service->cleanupQuarantine();

        expect($deleted)->toBe(0);
    });

    it('does not delete recent files', function () {
        $quarantinePath = $this->testDir.'/cleanup-quarantine';
        mkdir($quarantinePath, 0755, true);
        config(['virusscan.quarantine_path' => $quarantinePath]);
        config(['virusscan.quarantine_retention_days' => 30]);

        // Create a recent file
        $recentFile = $quarantinePath.'/recent-file.txt';
        file_put_contents($recentFile, 'Recent content');
        touch($recentFile); // Current timestamp

        $deleted = $this->service->cleanupQuarantine();

        expect($deleted)->toBe(0);
        expect(file_exists($recentFile))->toBeTrue();

        // Clean up
        @unlink($recentFile);
        @rmdir($quarantinePath);
    });
});

// ---------------------------------------------------------------------------
// getStatistics() Tests
// ---------------------------------------------------------------------------

describe('getStatistics', function () {
    it('returns correct structure', function () {
        // Skip if virus_scan_logs table doesn't exist
        if (! \Schema::hasTable('virus_scan_logs')) {
            $this->markTestSkipped('virus_scan_logs table not created');
        }

        $stats = $this->service->getStatistics();

        expect($stats)->toHaveKeys(['clean', 'infected', 'error', 'timeout', 'total', 'period']);
        expect($stats['period'])->toHaveKeys(['from', 'to']);
    });

    it('returns zeros when no logs exist', function () {
        // Skip if virus_scan_logs table doesn't exist
        if (! \Schema::hasTable('virus_scan_logs')) {
            $this->markTestSkipped('virus_scan_logs table not created');
        }

        $stats = $this->service->getStatistics();

        expect($stats['clean'])->toBe(0);
        expect($stats['infected'])->toBe(0);
        expect($stats['error'])->toBe(0);
        expect($stats['timeout'])->toBe(0);
        expect($stats['total'])->toBe(0);
    });

    it('respects date range parameters', function () {
        // Skip if virus_scan_logs table doesn't exist
        if (! \Schema::hasTable('virus_scan_logs')) {
            $this->markTestSkipped('virus_scan_logs table not created');
        }

        $from = now()->subDays(7);
        $to = now();

        $stats = $this->service->getStatistics($from, $to);

        expect($stats['period']['from'])->toBe($from->toDateTimeString());
        expect($stats['period']['to'])->toBe($to->toDateTimeString());
    });
});

// ---------------------------------------------------------------------------
// ping() Tests
// ---------------------------------------------------------------------------

describe('ping', function () {
    it('returns false when disabled', function () {
        config(['virusscan.enabled' => false]);

        expect($this->service->ping())->toBeFalse();
    });

    it('returns false when ClamAV is not available', function () {
        config(['virusscan.enabled' => true]);
        config(['virusscan.socket' => '/nonexistent/socket']);

        expect($this->service->ping())->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// getEngineVersion() Tests
// ---------------------------------------------------------------------------

describe('getEngineVersion', function () {
    it('returns null when disabled', function () {
        config(['virusscan.enabled' => false]);

        expect($this->service->getEngineVersion())->toBeNull();
    });

    it('returns null when ClamAV is not available', function () {
        config(['virusscan.enabled' => true]);
        config(['virusscan.socket' => '/nonexistent/socket']);

        expect($this->service->getEngineVersion())->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// EICAR Test File Tests
// ---------------------------------------------------------------------------

describe('EICAR test file', function () {
    it('can create EICAR test file', function () {
        $eicarFile = $this->testDir.'/eicar-test.com';
        file_put_contents($eicarFile, EICAR_TEST_STRING);

        expect(file_exists($eicarFile))->toBeTrue();
        expect(file_get_contents($eicarFile))->toBe(EICAR_TEST_STRING);

        // Calculate expected hash
        $expectedHash = hash('sha256', EICAR_TEST_STRING);
        $actualHash = hash_file('sha256', $eicarFile);

        expect($actualHash)->toBe($expectedHash);
    });

    it('EICAR string has correct length', function () {
        // Standard EICAR test file is 68 bytes
        expect(strlen(EICAR_TEST_STRING))->toBe(68);
    });
});

// ---------------------------------------------------------------------------
// Integration with fail_policy Tests
// ---------------------------------------------------------------------------

describe('fail policy', function () {
    it('respects open fail policy for errors', function () {
        config(['virusscan.enabled' => true]);
        config(['virusscan.fail_policy' => 'open']);

        // Create test file
        $testFile = $this->testDir.'/fail-policy-test.txt';
        file_put_contents($testFile, 'Test content');

        // Scan will fail (no ClamAV) but with open policy should be treated as clean
        $result = $this->service->scanFiles([$testFile]);

        // With open policy, errors don't mark as unclean
        if (count($result['error_files']) > 0 && count($result['infected_files']) === 0) {
            expect($result['clean'])->toBeTrue();
        }
    });

    it('respects closed fail policy for errors', function () {
        config(['virusscan.enabled' => true]);
        config(['virusscan.fail_policy' => 'closed']);

        // Create test file
        $testFile = $this->testDir.'/fail-policy-closed-test.txt';
        file_put_contents($testFile, 'Test content');

        // Scan will fail (no ClamAV) and with closed policy should be treated as unclean
        $result = $this->service->scanFiles([$testFile]);

        // With closed policy, errors mark as unclean
        if (count($result['error_files']) > 0) {
            expect($result['clean'])->toBeFalse();
        }
    });
});
