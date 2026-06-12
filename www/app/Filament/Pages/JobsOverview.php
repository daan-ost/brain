<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class JobsOverview extends Page
{

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static string $view = 'filament.pages.jobs-overview';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 101;

    protected static ?string $title = 'Jobs Overview';

    public array $stats = [];

    public string $activeTab = 'pending';

    public function mount(): void
    {
        $this->loadStats();
    }

    protected function loadStats(): void
    {
        $this->stats = [
            'pending' => DB::table('jobs')->count(),
            'failed' => DB::table('failed_jobs')->count(),
            'batches_total' => 0,
            'batches_pending' => 0,
            'batches_completed' => 0,
            'batches_failed' => 0,
            'jobs_per_hour' => 0,
            'avg_wait_time' => $this->getAverageWaitTime(),
        ];
    }

    protected function getJobsPerHour(): int
    {
        // Not available without batches table
        return 0;
    }

    protected function getAverageWaitTime(): string
    {
        $oldestJob = DB::table('jobs')
            ->orderBy('created_at', 'asc')
            ->first();

        if (! $oldestJob) {
            return '0s';
        }

        $seconds = now()->diffInSeconds($oldestJob->created_at);

        if ($seconds < 60) {
            return $seconds.'s';
        }
        if ($seconds < 3600) {
            return round($seconds / 60).'m';
        }

        return round($seconds / 3600, 1).'h';
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function retryFailedJob(int $id): void
    {
        $failedJob = DB::table('failed_jobs')->where('id', $id)->first();

        if ($failedJob) {
            // Push the job back to the queue
            DB::table('jobs')->insert([
                'queue' => $failedJob->queue,
                'payload' => $failedJob->payload,
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);

            // Delete the failed job
            DB::table('failed_jobs')->where('id', $id)->delete();

            $this->loadStats();

            $this->dispatch('notify', [
                'type' => 'success',
                'message' => 'Job queued for retry',
            ]);
        }
    }

    public function deleteFailedJob(int $id): void
    {
        DB::table('failed_jobs')->where('id', $id)->delete();
        $this->loadStats();

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'Failed job deleted',
        ]);
    }

    public function retryAllFailed(): void
    {
        $failedJobs = DB::table('failed_jobs')->get();

        foreach ($failedJobs as $failedJob) {
            DB::table('jobs')->insert([
                'queue' => $failedJob->queue,
                'payload' => $failedJob->payload,
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);
        }

        DB::table('failed_jobs')->truncate();
        $this->loadStats();

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'All failed jobs queued for retry',
        ]);
    }

    public function clearAllFailed(): void
    {
        DB::table('failed_jobs')->truncate();
        $this->loadStats();

        $this->dispatch('notify', [
            'type' => 'success',
            'message' => 'All failed jobs cleared',
        ]);
    }

    public function refresh(): void
    {
        $this->loadStats();
    }

    public function getPendingJobs(): array
    {
        return DB::table('jobs')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($job) {
                $payload = json_decode($job->payload, true);

                return [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'job_name' => $payload['displayName'] ?? 'Unknown',
                    'attempts' => $job->attempts,
                    'created_at' => \Carbon\Carbon::createFromTimestamp($job->created_at)->diffForHumans(),
                ];
            })
            ->toArray();
    }

    public function getFailedJobs(): array
    {
        return DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($job) {
                $payload = json_decode($job->payload, true);

                return [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'job_name' => $payload['displayName'] ?? 'Unknown',
                    'exception' => \Str::limit($job->exception, 200),
                    'failed_at' => \Carbon\Carbon::parse($job->failed_at)->diffForHumans(),
                ];
            })
            ->toArray();
    }

    public function getBatches(): array
    {
        return DB::table('batches')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($batch) {
                $progress = $batch->total_jobs > 0
                    ? round((($batch->total_jobs - $batch->pending_jobs) / $batch->total_jobs) * 100)
                    : 0;

                return [
                    'id' => $batch->id,
                    'name' => $batch->name ?? 'Unnamed Batch',
                    'total_jobs' => $batch->total_jobs,
                    'pending_jobs' => $batch->pending_jobs,
                    'failed_jobs' => $batch->failed_jobs,
                    'progress' => $progress,
                    'status' => $this->getBatchStatus($batch),
                    'created_at' => \Carbon\Carbon::createFromTimestamp($batch->created_at)->diffForHumans(),
                ];
            })
            ->toArray();
    }

    protected function getBatchStatus($batch): string
    {
        if ($batch->cancelled_at) {
            return 'cancelled';
        }
        if ($batch->finished_at) {
            return $batch->failed_jobs > 0 ? 'failed' : 'completed';
        }

        return 'pending';
    }
}
