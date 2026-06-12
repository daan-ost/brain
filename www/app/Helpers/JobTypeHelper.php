<?php

namespace App\Helpers;

class JobTypeHelper
{
    /**
     * Extract job class name safely from payload JSON
     * NO unserialize - security first!
     */
    public static function getJobClass($payload): string
    {
        if (empty($payload)) {
            return 'Unknown';
        }

        $decoded = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return 'Invalid Payload';
        }

        return $decoded['displayName'] ?? 'Unknown';
    }

    /**
     * Get human-readable job type name
     */
    public static function getReadableName(string $jobClass): string
    {
        $mapping = config('job_types.mapping', []);

        // Check if we have a direct match
        if (isset($mapping[$jobClass])) {
            return $mapping[$jobClass]['name'];
        }

        // Fallback: extract class name from full namespace
        $parts = explode('\\', $jobClass);
        $className = end($parts);

        // Remove "Job" suffix if present
        return str_replace('Job', '', $className);
    }

    /**
     * Get badge color class for job type
     */
    public static function getBadgeColor(string $jobClass): string
    {
        $mapping = config('job_types.mapping', []);

        if (isset($mapping[$jobClass])) {
            return $mapping[$jobClass]['badge'];
        }

        // Default color
        return 'secondary';
    }

    /**
     * Get both name and badge for a job payload
     * Convenience method
     */
    public static function getJobInfo($payload): array
    {
        $jobClass = self::getJobClass($payload);

        return [
            'class' => $jobClass,
            'name' => self::getReadableName($jobClass),
            'badge' => self::getBadgeColor($jobClass),
        ];
    }
}
