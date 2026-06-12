<?php

use App\Exceptions\VirusDetectedException;

// ---------------------------------------------------------------------------
// Constructor Tests
// ---------------------------------------------------------------------------

describe('VirusDetectedException constructor', function () {
    it('creates exception with message', function () {
        $exception = new VirusDetectedException('Virus detected in upload');

        expect($exception->getMessage())->toBe('Virus detected in upload');
        expect($exception->getInfectedFiles())->toBe([]);
    });

    it('creates exception with infected files', function () {
        $infectedFiles = [
            ['file' => 'malware.exe', 'threat' => 'Trojan.GenericKD'],
            ['file' => 'virus.doc', 'threat' => 'Macro.Virus'],
        ];

        $exception = new VirusDetectedException(
            'Multiple viruses detected',
            $infectedFiles
        );

        expect($exception->getInfectedFiles())->toBe($infectedFiles);
        expect($exception->getInfectedFiles())->toHaveCount(2);
    });

    it('preserves previous exception', function () {
        $previous = new \RuntimeException('Original error');
        $exception = new VirusDetectedException('Virus detected', [], $previous);

        expect($exception->getPrevious())->toBe($previous);
        expect($exception->getPrevious()->getMessage())->toBe('Original error');
    });
});

// ---------------------------------------------------------------------------
// getInfectedFiles() Tests
// ---------------------------------------------------------------------------

describe('getInfectedFiles', function () {
    it('returns empty array when no files provided', function () {
        $exception = new VirusDetectedException('Virus detected');

        expect($exception->getInfectedFiles())->toBe([]);
    });

    it('returns all infected files', function () {
        $files = [
            ['file' => 'test1.exe', 'threat' => 'Eicar-Signature'],
            ['file' => 'test2.com', 'threat' => 'Trojan.Generic'],
            ['file' => 'test3.bat', 'threat' => 'Script.Downloader'],
        ];

        $exception = new VirusDetectedException('Multiple threats', $files);

        expect($exception->getInfectedFiles())->toHaveCount(3);
        expect($exception->getInfectedFiles()[0]['file'])->toBe('test1.exe');
        expect($exception->getInfectedFiles()[1]['threat'])->toBe('Trojan.Generic');
    });
});

// ---------------------------------------------------------------------------
// getThreats() Tests
// ---------------------------------------------------------------------------

describe('getThreats', function () {
    it('returns empty array when no files', function () {
        $exception = new VirusDetectedException('No files');

        expect($exception->getThreats())->toBe([]);
    });

    it('extracts threat names from infected files', function () {
        $files = [
            ['file' => 'a.exe', 'threat' => 'Eicar-Signature'],
            ['file' => 'b.exe', 'threat' => 'Trojan.Generic'],
        ];

        $exception = new VirusDetectedException('Threats found', $files);

        $threats = $exception->getThreats();

        expect($threats)->toBe(['Eicar-Signature', 'Trojan.Generic']);
    });

    it('returns Unknown for missing threat names', function () {
        $files = [
            ['file' => 'a.exe', 'threat' => 'KnownVirus'],
            ['file' => 'b.exe'], // No threat key
        ];

        $exception = new VirusDetectedException('Mixed threats', $files);

        $threats = $exception->getThreats();

        expect($threats)->toBe(['KnownVirus', 'Unknown']);
    });
});

// ---------------------------------------------------------------------------
// render() Tests
// ---------------------------------------------------------------------------

describe('render', function () {
    it('returns JSON response', function () {
        $files = [
            ['file' => 'malware.exe', 'threat' => 'Trojan.GenericKD'],
        ];

        $exception = new VirusDetectedException('Virus detected', $files);

        $response = $exception->render();

        expect($response->getStatusCode())->toBe(422);
        expect($response->headers->get('Content-Type'))->toContain('application/json');
    });

    it('includes correct JSON structure', function () {
        $files = [
            ['file' => 'test.exe', 'threat' => 'Eicar-Signature'],
        ];

        $exception = new VirusDetectedException('Test message', $files);

        $response = $exception->render();
        $data = json_decode($response->getContent(), true);

        expect($data)->toHaveKeys(['error', 'message', 'type', 'infected_files']);
        expect($data['message'])->toBe('Test message');
        expect($data['type'])->toBe('virus_detected');
    });

    it('includes simplified infected files in response', function () {
        $files = [
            ['file' => 'virus.exe', 'threat' => 'Trojan', 'path' => '/tmp/uploads/virus.exe', 'sha256' => 'abc123'],
        ];

        $exception = new VirusDetectedException('Virus found', $files);

        $response = $exception->render();
        $data = json_decode($response->getContent(), true);

        // Should only include file and threat, not internal details like path/sha256
        expect($data['infected_files'][0])->toHaveKeys(['file', 'threat']);
        expect($data['infected_files'][0]['file'])->toBe('virus.exe');
        expect($data['infected_files'][0]['threat'])->toBe('Trojan');
    });

    it('handles missing file/threat in infected_files gracefully', function () {
        $files = [
            [], // Empty entry
            ['file' => 'known.exe'], // Missing threat
        ];

        $exception = new VirusDetectedException('Test', $files);

        $response = $exception->render();
        $data = json_decode($response->getContent(), true);

        expect($data['infected_files'][0]['file'])->toBe('Unknown');
        expect($data['infected_files'][0]['threat'])->toBe('Unknown');
        expect($data['infected_files'][1]['file'])->toBe('known.exe');
        expect($data['infected_files'][1]['threat'])->toBe('Unknown');
    });
});

// ---------------------------------------------------------------------------
// Exception Behavior Tests
// ---------------------------------------------------------------------------

describe('exception behavior', function () {
    it('can be thrown and caught', function () {
        $caught = false;
        $infectedFiles = [];

        try {
            throw new VirusDetectedException(
                'Virus detected in upload',
                [['file' => 'malware.exe', 'threat' => 'Trojan']]
            );
        } catch (VirusDetectedException $e) {
            $caught = true;
            $infectedFiles = $e->getInfectedFiles();
        }

        expect($caught)->toBeTrue();
        expect($infectedFiles)->toHaveCount(1);
    });

    it('is an instance of Exception', function () {
        $exception = new VirusDetectedException('Test');

        expect($exception)->toBeInstanceOf(\Exception::class);
    });

    it('has code 0 by default', function () {
        $exception = new VirusDetectedException('Test');

        expect($exception->getCode())->toBe(0);
    });
});
