<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ServerMonitoringService
{
    /**
     * Get CPU usage percentage
     */
    public function getCpuUsage(): ?float
    {
        try {
            // Get load average (1 minute)
            $load = sys_getloadavg();
            if ($load === false) {
                return null;
            }

            // Get CPU core count
            $cpuCores = $this->getCpuCoreCount();

            // Calculate CPU usage percentage based on load average
            // Load average / cores * 100 = percentage
            return $cpuCores > 0 ? round(($load[0] / $cpuCores) * 100, 2) : null;
        } catch (\Exception $e) {
            Log::error('Failed to get CPU usage', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Get CPU core count
     */
    public function getCpuCoreCount(): int
    {
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $output = shell_exec('wmic cpu get NumberOfCores');
                if ($output) {
                    preg_match_all('/\d+/', $output, $matches);

                    return array_sum(array_map('intval', $matches[0]));
                }
            } else {
                // Linux/Unix
                $output = shell_exec('nproc');
                if ($output) {
                    return (int) trim($output);
                }

                // Fallback: read from /proc/cpuinfo
                $output = shell_exec('grep -c ^processor /proc/cpuinfo');
                if ($output) {
                    return (int) trim($output);
                }
            }

            return 1; // Fallback
        } catch (\Exception $e) {
            Log::error('Failed to get CPU core count', ['error' => $e->getMessage()]);

            return 1;
        }
    }

    /**
     * Get load averages (1, 5, 15 minutes)
     */
    public function getLoadAverage(): array
    {
        try {
            $load = sys_getloadavg();
            if ($load === false) {
                return [0, 0, 0];
            }

            return [
                '1min' => round($load[0], 2),
                '5min' => round($load[1], 2),
                '15min' => round($load[2], 2),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get load average', ['error' => $e->getMessage()]);

            return [0, 0, 0];
        }
    }

    /**
     * Get memory usage in MB and percentage
     */
    public function getMemoryUsage(): array
    {
        try {
            $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
            $memoryUsage = memory_get_usage(true);
            $memoryPeak = memory_get_peak_usage(true);

            $percentUsed = $memoryLimit > 0 ? round(($memoryUsage / $memoryLimit) * 100, 2) : 0;

            return [
                'used_mb' => round($memoryUsage / 1024 / 1024, 2),
                'peak_mb' => round($memoryPeak / 1024 / 1024, 2),
                'limit_mb' => round($memoryLimit / 1024 / 1024, 2),
                'percent_used' => $percentUsed,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get memory usage', ['error' => $e->getMessage()]);

            return [
                'used_mb' => 0,
                'peak_mb' => 0,
                'limit_mb' => 0,
                'percent_used' => 0,
            ];
        }
    }

    /**
     * Parse PHP memory limit string to bytes
     */
    private function parseMemoryLimit(string $size): int
    {
        $unit = strtolower(substr($size, -1));
        $value = (int) substr($size, 0, -1);

        switch ($unit) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
    }

    /**
     * Get disk space usage for storage paths
     */
    public function getDiskUsage(): array
    {
        try {
            $storagePath = storage_path();

            $totalSpace = disk_total_space($storagePath);
            $freeSpace = disk_free_space($storagePath);
            $usedSpace = $totalSpace - $freeSpace;

            $percentUsed = $totalSpace > 0 ? round(($usedSpace / $totalSpace) * 100, 2) : 0;

            return [
                'total_gb' => round($totalSpace / 1024 / 1024 / 1024, 2),
                'used_gb' => round($usedSpace / 1024 / 1024 / 1024, 2),
                'free_gb' => round($freeSpace / 1024 / 1024 / 1024, 2),
                'percent_used' => $percentUsed,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get disk usage', ['error' => $e->getMessage()]);

            return [
                'total_gb' => 0,
                'used_gb' => 0,
                'free_gb' => 0,
                'percent_used' => 0,
            ];
        }
    }

    /**
     * Get server uptime
     */
    public function getServerUptime(): ?string
    {
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                // Windows: use systeminfo
                $output = shell_exec('systeminfo | find "System Boot Time"');
                if ($output) {
                    return trim($output);
                }
            } else {
                // Linux/Unix: use uptime command
                $output = shell_exec('uptime -p');
                if ($output) {
                    return trim($output);
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Failed to get server uptime', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Get all server metrics in one call
     */
    public function getAllMetrics(): array
    {
        return [
            'cpu' => $this->getCpuUsage(),
            'load_average' => $this->getLoadAverage(),
            'memory' => $this->getMemoryUsage(),
            'disk' => $this->getDiskUsage(),
            'uptime' => $this->getServerUptime(),
        ];
    }
}
