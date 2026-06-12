<?php

use App\Services\Virusscan\ScanResult;
use App\Services\Virusscan\ScanStatus;
use Carbon\Carbon;

// ---------------------------------------------------------------------------
// ScanStatus Enum Tests
// ---------------------------------------------------------------------------

describe('ScanStatus enum', function () {
    it('has all expected values', function () {
        $statuses = ScanStatus::cases();

        expect($statuses)->toHaveCount(6);
        expect(ScanStatus::CLEAN->value)->toBe('clean');
        expect(ScanStatus::INFECTED->value)->toBe('infected');
        expect(ScanStatus::ERROR->value)->toBe('error');
        expect(ScanStatus::TIMEOUT->value)->toBe('timeout');
        expect(ScanStatus::DISABLED->value)->toBe('disabled');
        expect(ScanStatus::SKIPPED->value)->toBe('skipped');
    });

    it('isSuccessful returns true for CLEAN and INFECTED', function () {
        expect(ScanStatus::CLEAN->isSuccessful())->toBeTrue();
        expect(ScanStatus::INFECTED->isSuccessful())->toBeTrue();
        expect(ScanStatus::ERROR->isSuccessful())->toBeFalse();
        expect(ScanStatus::TIMEOUT->isSuccessful())->toBeFalse();
        expect(ScanStatus::DISABLED->isSuccessful())->toBeFalse();
        expect(ScanStatus::SKIPPED->isSuccessful())->toBeFalse();
    });

    it('isFailure returns true for ERROR and TIMEOUT', function () {
        expect(ScanStatus::ERROR->isFailure())->toBeTrue();
        expect(ScanStatus::TIMEOUT->isFailure())->toBeTrue();
        expect(ScanStatus::CLEAN->isFailure())->toBeFalse();
        expect(ScanStatus::INFECTED->isFailure())->toBeFalse();
        expect(ScanStatus::DISABLED->isFailure())->toBeFalse();
        expect(ScanStatus::SKIPPED->isFailure())->toBeFalse();
    });

    it('label returns human-readable text', function () {
        expect(ScanStatus::CLEAN->label())->toBe('Clean');
        expect(ScanStatus::INFECTED->label())->toBe('Virus Detected');
        expect(ScanStatus::ERROR->label())->toBe('Scan Error');
        expect(ScanStatus::TIMEOUT->label())->toBe('Scan Timeout');
        expect(ScanStatus::DISABLED->label())->toBe('Scanning Disabled');
        expect(ScanStatus::SKIPPED->label())->toBe('Skipped');
    });
});

// ---------------------------------------------------------------------------
// ScanResult Factory Methods Tests
// ---------------------------------------------------------------------------

describe('ScanResult factory methods', function () {
    it('clean() creates a clean result', function () {
        $result = ScanResult::clean(
            sha256: 'abc123',
            engineVersion: 'ClamAV 0.103.8',
            scanDurationMs: 150
        );

        expect($result->status)->toBe(ScanStatus::CLEAN);
        expect($result->sha256)->toBe('abc123');
        expect($result->engineVersion)->toBe('ClamAV 0.103.8');
        expect($result->scanDurationMs)->toBe(150);
        expect($result->threat)->toBeNull();
        expect($result->error)->toBeNull();
    });

    it('infected() creates an infected result', function () {
        $result = ScanResult::infected(
            threat: 'Eicar-Signature',
            sha256: 'def456',
            engineVersion: 'ClamAV 0.103.8',
            scanDurationMs: 200
        );

        expect($result->status)->toBe(ScanStatus::INFECTED);
        expect($result->threat)->toBe('Eicar-Signature');
        expect($result->sha256)->toBe('def456');
        expect($result->engineVersion)->toBe('ClamAV 0.103.8');
        expect($result->scanDurationMs)->toBe(200);
        expect($result->error)->toBeNull();
    });

    it('error() creates an error result', function () {
        $result = ScanResult::error('Connection timeout', 'ghi789');

        expect($result->status)->toBe(ScanStatus::ERROR);
        expect($result->error)->toBe('Connection timeout');
        expect($result->sha256)->toBe('ghi789');
        expect($result->threat)->toBeNull();
    });

    it('error() works without sha256', function () {
        $result = ScanResult::error('File not found');

        expect($result->status)->toBe(ScanStatus::ERROR);
        expect($result->error)->toBe('File not found');
        expect($result->sha256)->toBeNull();
    });

    it('timeout() creates a timeout result', function () {
        $result = ScanResult::timeout('jkl012');

        expect($result->status)->toBe(ScanStatus::TIMEOUT);
        expect($result->error)->toBe('Scan timed out');
        expect($result->sha256)->toBe('jkl012');
    });

    it('disabled() creates a disabled result', function () {
        $result = ScanResult::disabled();

        expect($result->status)->toBe(ScanStatus::DISABLED);
        expect($result->threat)->toBeNull();
        expect($result->error)->toBeNull();
        expect($result->sha256)->toBeNull();
    });

    it('skipped() creates a skipped result', function () {
        $result = ScanResult::skipped('User tier not eligible');

        expect($result->status)->toBe(ScanStatus::SKIPPED);
        expect($result->error)->toBe('User tier not eligible');
    });

    it('skipped() has default reason', function () {
        $result = ScanResult::skipped();

        expect($result->error)->toBe('Scan skipped');
    });
});

// ---------------------------------------------------------------------------
// ScanResult Status Check Methods Tests
// ---------------------------------------------------------------------------

describe('ScanResult status checks', function () {
    it('isClean() returns correct values', function () {
        expect(ScanResult::clean('abc')->isClean())->toBeTrue();
        expect(ScanResult::infected('Virus', 'abc')->isClean())->toBeFalse();
        expect(ScanResult::error('Error')->isClean())->toBeFalse();
        expect(ScanResult::disabled()->isClean())->toBeFalse();
    });

    it('isInfected() returns correct values', function () {
        expect(ScanResult::infected('Virus', 'abc')->isInfected())->toBeTrue();
        expect(ScanResult::clean('abc')->isInfected())->toBeFalse();
        expect(ScanResult::error('Error')->isInfected())->toBeFalse();
        expect(ScanResult::disabled()->isInfected())->toBeFalse();
    });

    it('isFailed() returns correct values', function () {
        expect(ScanResult::error('Error')->isFailed())->toBeTrue();
        expect(ScanResult::timeout()->isFailed())->toBeTrue();
        expect(ScanResult::clean('abc')->isFailed())->toBeFalse();
        expect(ScanResult::infected('Virus', 'abc')->isFailed())->toBeFalse();
        expect(ScanResult::disabled()->isFailed())->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// ScanResult shouldBlock() Tests
// ---------------------------------------------------------------------------

describe('ScanResult shouldBlock', function () {
    it('blocks infected files', function () {
        $result = ScanResult::infected('Eicar-Signature', 'abc123');

        expect($result->shouldBlock())->toBeTrue();
    });

    it('does not block clean files', function () {
        $result = ScanResult::clean('abc123');

        expect($result->shouldBlock())->toBeFalse();
    });

    it('does not block disabled scans', function () {
        $result = ScanResult::disabled();

        expect($result->shouldBlock())->toBeFalse();
    });

    it('blocks errors with closed policy', function () {
        config(['virusscan.fail_policy' => 'closed']);

        $result = ScanResult::error('Connection failed');

        expect($result->shouldBlock())->toBeTrue();
    });

    it('does not block errors with open policy', function () {
        config(['virusscan.fail_policy' => 'open']);

        $result = ScanResult::error('Connection failed');

        expect($result->shouldBlock())->toBeFalse();
    });

    it('blocks timeout with closed policy', function () {
        config(['virusscan.fail_policy' => 'closed']);

        $result = ScanResult::timeout('abc123');

        expect($result->shouldBlock())->toBeTrue();
    });

    it('does not block timeout with open policy', function () {
        config(['virusscan.fail_policy' => 'open']);

        $result = ScanResult::timeout('abc123');

        expect($result->shouldBlock())->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// ScanResult toArray() Tests
// ---------------------------------------------------------------------------

describe('ScanResult toArray', function () {
    it('converts clean result to array', function () {
        $result = ScanResult::clean(
            sha256: 'abc123',
            engineVersion: 'ClamAV 0.103.8',
            scanDurationMs: 150
        );

        $array = $result->toArray();

        expect($array['status'])->toBe('clean');
        expect($array['sha256'])->toBe('abc123');
        expect($array['engine_version'])->toBe('ClamAV 0.103.8');
        expect($array['scan_duration_ms'])->toBe(150);
        expect($array)->toHaveKey('scanned_at');
        expect($array)->not->toHaveKey('threat');
        expect($array)->not->toHaveKey('error');
    });

    it('converts infected result to array', function () {
        $result = ScanResult::infected('Eicar-Signature', 'def456');

        $array = $result->toArray();

        expect($array['status'])->toBe('infected');
        expect($array['threat'])->toBe('Eicar-Signature');
        expect($array['sha256'])->toBe('def456');
    });

    it('converts error result to array', function () {
        $result = ScanResult::error('Connection failed', 'ghi789');

        $array = $result->toArray();

        expect($array['status'])->toBe('error');
        expect($array['error'])->toBe('Connection failed');
        expect($array['sha256'])->toBe('ghi789');
    });

    it('excludes null values', function () {
        $result = ScanResult::disabled();

        $array = $result->toArray();

        expect($array)->toHaveKey('status');
        expect($array)->toHaveKey('scanned_at');
        expect($array)->not->toHaveKey('threat');
        expect($array)->not->toHaveKey('error');
        expect($array)->not->toHaveKey('sha256');
    });

    it('includes signature_date when present', function () {
        $signatureDate = Carbon::parse('2024-03-15 10:00:00');
        $result = new ScanResult(
            status: ScanStatus::CLEAN,
            sha256: 'abc123',
            signatureDate: $signatureDate
        );

        $array = $result->toArray();

        expect($array)->toHaveKey('signature_date');
        expect($array['signature_date'])->toContain('2024-03-15');
    });
});

// ---------------------------------------------------------------------------
// ScanResult fromArray() Tests
// ---------------------------------------------------------------------------

describe('ScanResult fromArray', function () {
    it('creates result from clean array', function () {
        $data = [
            'status' => 'clean',
            'sha256' => 'abc123',
            'engine_version' => 'ClamAV 0.103.8',
            'scan_duration_ms' => 150,
        ];

        $result = ScanResult::fromArray($data);

        expect($result->status)->toBe(ScanStatus::CLEAN);
        expect($result->sha256)->toBe('abc123');
        expect($result->engineVersion)->toBe('ClamAV 0.103.8');
        expect($result->scanDurationMs)->toBe(150);
    });

    it('creates result from infected array', function () {
        $data = [
            'status' => 'infected',
            'threat' => 'Eicar-Signature',
            'sha256' => 'def456',
        ];

        $result = ScanResult::fromArray($data);

        expect($result->status)->toBe(ScanStatus::INFECTED);
        expect($result->threat)->toBe('Eicar-Signature');
        expect($result->sha256)->toBe('def456');
    });

    it('handles missing optional fields', function () {
        $data = [
            'status' => 'disabled',
        ];

        $result = ScanResult::fromArray($data);

        expect($result->status)->toBe(ScanStatus::DISABLED);
        expect($result->threat)->toBeNull();
        expect($result->error)->toBeNull();
        expect($result->sha256)->toBeNull();
    });

    it('parses signature_date correctly', function () {
        $data = [
            'status' => 'clean',
            'sha256' => 'abc123',
            'signature_date' => '2024-03-15T10:00:00+00:00',
        ];

        $result = ScanResult::fromArray($data);

        expect($result->signatureDate)->not->toBeNull();
        expect($result->signatureDate->format('Y-m-d'))->toBe('2024-03-15');
    });

    it('roundtrips correctly through toArray and fromArray', function () {
        $original = ScanResult::infected(
            threat: 'TestVirus',
            sha256: 'abc123def456',
            engineVersion: 'ClamAV 1.0.0',
            scanDurationMs: 250
        );

        $array = $original->toArray();
        $restored = ScanResult::fromArray($array);

        expect($restored->status)->toBe($original->status);
        expect($restored->threat)->toBe($original->threat);
        expect($restored->sha256)->toBe($original->sha256);
        expect($restored->engineVersion)->toBe($original->engineVersion);
        expect($restored->scanDurationMs)->toBe($original->scanDurationMs);
    });
});
