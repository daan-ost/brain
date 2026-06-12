<?php

namespace App\Services\Virusscan;

/**
 * Virus scan status enumeration
 */
enum ScanStatus: string
{
    case CLEAN = 'clean';
    case INFECTED = 'infected';
    case ERROR = 'error';
    case TIMEOUT = 'timeout';
    case DISABLED = 'disabled';
    case SKIPPED = 'skipped';

    /**
     * Check if this status represents a successful scan
     */
    public function isSuccessful(): bool
    {
        return in_array($this, [self::CLEAN, self::INFECTED]);
    }

    /**
     * Check if this status represents a failure
     */
    public function isFailure(): bool
    {
        return in_array($this, [self::ERROR, self::TIMEOUT]);
    }

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::CLEAN => 'Clean',
            self::INFECTED => 'Virus Detected',
            self::ERROR => 'Scan Error',
            self::TIMEOUT => 'Scan Timeout',
            self::DISABLED => 'Scanning Disabled',
            self::SKIPPED => 'Skipped',
        };
    }
}
