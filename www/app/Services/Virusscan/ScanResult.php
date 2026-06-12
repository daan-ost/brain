<?php

namespace App\Services\Virusscan;

use Carbon\Carbon;

/**
 * Virus scan result data transfer object
 */
class ScanResult
{
    public function __construct(
        public readonly ScanStatus $status,
        public readonly ?string $threat = null,
        public readonly ?string $error = null,
        public readonly ?string $sha256 = null,
        public readonly ?string $engineVersion = null,
        public readonly ?Carbon $signatureDate = null,
        public readonly ?int $scanDurationMs = null,
    ) {}

    /**
     * Create a clean result
     */
    public static function clean(
        string $sha256,
        ?string $engineVersion = null,
        ?Carbon $signatureDate = null,
        ?int $scanDurationMs = null
    ): self {
        return new self(
            status: ScanStatus::CLEAN,
            sha256: $sha256,
            engineVersion: $engineVersion,
            signatureDate: $signatureDate,
            scanDurationMs: $scanDurationMs,
        );
    }

    /**
     * Create an infected result
     */
    public static function infected(
        string $threat,
        string $sha256,
        ?string $engineVersion = null,
        ?Carbon $signatureDate = null,
        ?int $scanDurationMs = null
    ): self {
        return new self(
            status: ScanStatus::INFECTED,
            threat: $threat,
            sha256: $sha256,
            engineVersion: $engineVersion,
            signatureDate: $signatureDate,
            scanDurationMs: $scanDurationMs,
        );
    }

    /**
     * Create an error result
     */
    public static function error(string $error, ?string $sha256 = null): self
    {
        return new self(
            status: ScanStatus::ERROR,
            error: $error,
            sha256: $sha256,
        );
    }

    /**
     * Create a timeout result
     */
    public static function timeout(?string $sha256 = null): self
    {
        return new self(
            status: ScanStatus::TIMEOUT,
            error: 'Scan timed out',
            sha256: $sha256,
        );
    }

    /**
     * Create a disabled result (scanning turned off)
     */
    public static function disabled(): self
    {
        return new self(status: ScanStatus::DISABLED);
    }

    /**
     * Create a skipped result (e.g., user tier not eligible)
     */
    public static function skipped(string $reason = 'Scan skipped'): self
    {
        return new self(
            status: ScanStatus::SKIPPED,
            error: $reason,
        );
    }

    /**
     * Check if the file is clean
     */
    public function isClean(): bool
    {
        return $this->status === ScanStatus::CLEAN;
    }

    /**
     * Check if a virus was detected
     */
    public function isInfected(): bool
    {
        return $this->status === ScanStatus::INFECTED;
    }

    /**
     * Check if the scan failed (error or timeout)
     */
    public function isFailed(): bool
    {
        return $this->status->isFailure();
    }

    /**
     * Determine if this result should block the file from processing
     *
     * @return bool True if file should be blocked
     */
    public function shouldBlock(): bool
    {
        // Always block infected files
        if ($this->status === ScanStatus::INFECTED) {
            return true;
        }

        // For failures, check the fail policy
        if ($this->status->isFailure()) {
            return config('virusscan.fail_policy') === 'closed';
        }

        return false;
    }

    /**
     * Convert to array for JSON storage
     */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status->value,
            'threat' => $this->threat,
            'error' => $this->error,
            'sha256' => $this->sha256,
            'engine_version' => $this->engineVersion,
            'signature_date' => $this->signatureDate?->toIso8601String(),
            'scan_duration_ms' => $this->scanDurationMs,
            'scanned_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null);
    }

    /**
     * Create from stored array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            status: ScanStatus::from($data['status']),
            threat: $data['threat'] ?? null,
            error: $data['error'] ?? null,
            sha256: $data['sha256'] ?? null,
            engineVersion: $data['engine_version'] ?? null,
            signatureDate: isset($data['signature_date']) ? Carbon::parse($data['signature_date']) : null,
            scanDurationMs: $data['scan_duration_ms'] ?? null,
        );
    }
}
