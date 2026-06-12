<?php

namespace App\Services\Virusscan;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class VirusScanService
{
    /**
     * Check if virus scanning is enabled
     */
    public function isEnabled(): bool
    {
        return config('virusscan.enabled', false);
    }

    /**
     * Scan a file for viruses
     */
    public function scan(string $filePath): ScanResult
    {
        // Check if scanning is enabled
        if (! $this->isEnabled()) {
            return ScanResult::disabled();
        }

        // Validate file exists
        if (! file_exists($filePath)) {
            return ScanResult::error("File not found: {$filePath}");
        }

        // Calculate SHA256 hash before scanning
        $sha256 = hash_file('sha256', $filePath);

        try {
            $result = $this->performScan($filePath, $sha256);

            // Log performance if enabled
            if (config('virusscan.log_performance', true)) {
                Log::channel('daily')->info('Virus scan completed', [
                    'file' => basename($filePath),
                    'status' => $result->status->value,
                    'duration_ms' => $result->scanDurationMs,
                    'sha256' => $sha256,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Virus scan error', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return ScanResult::error($e->getMessage(), $sha256);
        }
    }

    /**
     * Perform the actual scan via ClamAV socket
     */
    private function performScan(string $filePath, string $sha256): ScanResult
    {
        $socketPath = config('virusscan.socket');
        $timeout = config('virusscan.timeout', 30);

        // Create socket connection
        $socket = @socket_create(AF_UNIX, SOCK_STREAM, 0);
        if ($socket === false) {
            return ScanResult::error('Failed to create socket: '.socket_strerror(socket_last_error()));
        }

        // Set timeout
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $timeout, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $timeout, 'usec' => 0]);

        // Connect to ClamAV daemon
        $connected = @socket_connect($socket, $socketPath);
        if (! $connected) {
            socket_close($socket);

            return ScanResult::error('Failed to connect to ClamAV: '.socket_strerror(socket_last_error()));
        }

        $startTime = microtime(true);

        try {
            // Use SCAN command (simpler than INSTREAM for file paths)
            $command = "SCAN {$filePath}\n";
            socket_write($socket, $command, strlen($command));

            // Read response
            $response = '';
            while ($buffer = socket_read($socket, 8192)) {
                $response .= $buffer;
                if (strpos($response, "\n") !== false) {
                    break;
                }
            }

            socket_close($socket);

            $duration = (int) ((microtime(true) - $startTime) * 1000);

            return $this->parseResponse($response, $sha256, $duration);
        } catch (\Exception $e) {
            socket_close($socket);

            if (strpos($e->getMessage(), 'timed out') !== false) {
                return ScanResult::timeout($sha256);
            }

            throw $e;
        }
    }

    /**
     * Parse ClamAV response
     */
    private function parseResponse(string $response, string $sha256, int $durationMs): ScanResult
    {
        $response = trim($response);

        // Engine version (fetch separately if needed)
        $engineVersion = $this->getEngineVersion();

        // Response format: "/path/to/file: OK" or "/path/to/file: VirusName FOUND"
        if (str_ends_with($response, 'OK')) {
            return ScanResult::clean(
                sha256: $sha256,
                engineVersion: $engineVersion,
                scanDurationMs: $durationMs,
            );
        }

        if (str_contains($response, 'FOUND')) {
            // Extract virus name
            // Format: "/path/to/file: Eicar-Signature FOUND"
            preg_match('/:\s*(.+)\s+FOUND$/', $response, $matches);
            $threat = $matches[1] ?? 'Unknown threat';

            return ScanResult::infected(
                threat: $threat,
                sha256: $sha256,
                engineVersion: $engineVersion,
                scanDurationMs: $durationMs,
            );
        }

        if (str_contains($response, 'ERROR')) {
            return ScanResult::error("ClamAV error: {$response}", $sha256);
        }

        // Unknown response
        return ScanResult::error("Unknown ClamAV response: {$response}", $sha256);
    }

    /**
     * Get ClamAV engine version
     */
    public function getEngineVersion(): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $socketPath = config('virusscan.socket');
            $socket = @socket_create(AF_UNIX, SOCK_STREAM, 0);
            if ($socket === false) {
                return null;
            }

            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);

            if (! @socket_connect($socket, $socketPath)) {
                socket_close($socket);

                return null;
            }

            socket_write($socket, "VERSION\n", 8);
            $response = socket_read($socket, 1024);
            socket_close($socket);

            // Response: "ClamAV 0.103.8/26832/Wed Mar 13 09:28:01 2024"
            return trim($response) ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Ping ClamAV to check if it's running
     */
    public function ping(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        try {
            $socketPath = config('virusscan.socket');
            $socket = @socket_create(AF_UNIX, SOCK_STREAM, 0);
            if ($socket === false) {
                return false;
            }

            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);

            if (! @socket_connect($socket, $socketPath)) {
                socket_close($socket);

                return false;
            }

            socket_write($socket, "PING\n", 5);
            $response = socket_read($socket, 1024);
            socket_close($socket);

            return trim($response) === 'PONG';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Move infected file to quarantine
     */
    public function quarantine(string $filePath, ScanResult $result): ?string
    {
        if (! file_exists($filePath)) {
            return null;
        }

        $quarantinePath = config('virusscan.quarantine_path');

        // Ensure quarantine directory exists
        if (! is_dir($quarantinePath)) {
            mkdir($quarantinePath, 0750, true);
        }

        // Create unique filename in quarantine
        $quarantineFile = sprintf(
            '%s/%s_%s_%s',
            $quarantinePath,
            date('Y-m-d_His'),
            $result->sha256 ? substr($result->sha256, 0, 8) : 'unknown',
            basename($filePath)
        );

        // Move file to quarantine
        if (rename($filePath, $quarantineFile)) {
            Log::warning('File quarantined', [
                'original_path' => $filePath,
                'quarantine_path' => $quarantineFile,
                'threat' => $result->threat,
                'sha256' => $result->sha256,
            ]);

            return $quarantineFile;
        }

        Log::error('Failed to quarantine file', [
            'file' => $filePath,
        ]);

        return null;
    }

    /**
     * Delete old quarantined files
     */
    public function cleanupQuarantine(): int
    {
        $quarantinePath = config('virusscan.quarantine_path');
        $retentionDays = config('virusscan.quarantine_retention_days', 30);

        if (! is_dir($quarantinePath)) {
            return 0;
        }

        $deleted = 0;
        $cutoff = now()->subDays($retentionDays)->timestamp;

        foreach (glob("{$quarantinePath}/*") as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }

        if ($deleted > 0) {
            Log::info("Quarantine cleanup: deleted {$deleted} files");
        }

        return $deleted;
    }

    /**
     * Get scan statistics for a date range
     */
    public function getStatistics(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? now()->subDays(30);
        $to = $to ?? now();

        $logs = \DB::table('virus_scan_logs')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'clean' => $logs['clean'] ?? 0,
            'infected' => $logs['infected'] ?? 0,
            'error' => $logs['error'] ?? 0,
            'timeout' => $logs['timeout'] ?? 0,
            'total' => array_sum($logs),
            'period' => [
                'from' => $from->toDateTimeString(),
                'to' => $to->toDateTimeString(),
            ],
        ];
    }

    /**
     * Check if user should have virus scanning based on tier
     */
    public function shouldScanForUser(?int $userId): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $requiredTier = config('virusscan.required_tier');

        // No tier restriction - scan for everyone
        if ($requiredTier === null) {
            return true;
        }

        // No user - depends on config (guests)
        if ($userId === null) {
            // For now, scan guests too if enabled globally
            return true;
        }

        // Check user's license tier
        $user = \App\Models\User::find($userId);
        if (! $user) {
            return true;
        }

        // Check if user has required tier
        $tiers = (array) $requiredTier;
        $userLicense = $user->licenses()->first();

        if ($userLicense && in_array($userLicense->tier, $tiers)) {
            return true;
        }

        return false;
    }

    /**
     * Scan multiple files and return aggregated result
     *
     * @param  array  $filePaths  Array of file paths (absolute or storage-relative)
     * @param  bool  $stopOnInfected  Stop scanning when first infected file is found
     * @return array{clean: bool, results: array, infected_files: array, error_files: array}
     */
    public function scanFiles(array $filePaths, bool $stopOnInfected = false): array
    {
        $results = [];
        $infectedFiles = [];
        $errorFiles = [];
        $allClean = true;

        if (! $this->isEnabled()) {
            return [
                'clean' => true,
                'skipped' => true,
                'results' => [],
                'infected_files' => [],
                'error_files' => [],
            ];
        }

        foreach ($filePaths as $filePath) {
            // Handle storage-relative paths
            $absolutePath = str_starts_with($filePath, '/')
                ? $filePath
                : Storage::path($filePath);

            $result = $this->scan($absolutePath);

            $results[] = [
                'file' => basename($filePath),
                'path' => $filePath,
                'status' => $result->status->value,
                'threat' => $result->threat,
                'sha256' => $result->sha256,
            ];

            if ($result->isInfected()) {
                $allClean = false;
                $infectedFiles[] = [
                    'file' => basename($filePath),
                    'path' => $filePath,
                    'threat' => $result->threat,
                ];

                // Quarantine infected file
                $this->quarantine($absolutePath, $result);

                if ($stopOnInfected) {
                    break;
                }
            } elseif ($result->isFailed()) {
                $errorFiles[] = [
                    'file' => basename($filePath),
                    'path' => $filePath,
                    'error' => $result->error,
                ];

                // Check fail policy for errors
                if (config('virusscan.fail_policy') === 'closed') {
                    $allClean = false;
                }
            }
        }

        Log::info('Batch virus scan completed', [
            'total_files' => count($filePaths),
            'scanned' => count($results),
            'infected' => count($infectedFiles),
            'errors' => count($errorFiles),
            'all_clean' => $allClean,
        ]);

        return [
            'clean' => $allClean,
            'skipped' => false,
            'results' => $results,
            'infected_files' => $infectedFiles,
            'error_files' => $errorFiles,
        ];
    }

    /**
     * Scan files and throw exception if infected (for use in controllers)
     *
     * @param  array  $filePaths  Array of file paths
     * @param  int|null  $userId  User ID for tier checking
     * @throws \App\Exceptions\VirusDetectedException
     */
    public function scanFilesOrFail(array $filePaths, ?int $userId = null): void
    {
        // Check if scanning should happen for this user
        if (! $this->shouldScanForUser($userId)) {
            return;
        }

        $scanResult = $this->scanFiles($filePaths);

        if ($scanResult['skipped']) {
            return;
        }

        if (! $scanResult['clean']) {
            $threats = array_map(
                fn ($f) => "{$f['file']}: {$f['threat']}",
                $scanResult['infected_files']
            );

            throw new \App\Exceptions\VirusDetectedException(
                'Virus detected in uploaded files: '.implode(', ', $threats),
                $scanResult['infected_files']
            );
        }
    }
}
