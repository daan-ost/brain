<?php

namespace App\Exceptions;

use Exception;

class VirusDetectedException extends Exception
{
    private array $infectedFiles;

    public function __construct(string $message, array $infectedFiles = [], ?\Throwable $previous = null)
    {
        $this->infectedFiles = $infectedFiles;
        parent::__construct($message, 0, $previous);
    }

    /**
     * Get the list of infected files
     */
    public function getInfectedFiles(): array
    {
        return $this->infectedFiles;
    }

    /**
     * Get threat names
     */
    public function getThreats(): array
    {
        return array_map(fn ($f) => $f['threat'] ?? 'Unknown', $this->infectedFiles);
    }

    /**
     * Render the exception as an HTTP response
     */
    public function render()
    {
        return response()->json([
            'error' => __('errors.virus_detected'),
            'message' => $this->getMessage(),
            'type' => 'virus_detected',
            'infected_files' => array_map(fn ($f) => [
                'file' => $f['file'] ?? 'Unknown',
                'threat' => $f['threat'] ?? 'Unknown',
            ], $this->infectedFiles),
        ], 422); // Unprocessable Entity
    }
}
