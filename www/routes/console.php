<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Business Logic Schedule
|--------------------------------------------------------------------------
*/

// License credit processing - runs hourly to check for resets and expirations
Schedule::command('license:process-credits')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->sentryMonitor()
    ->after(fn () => Cache::put('schedule:last_run:license-process-credits', now(), now()->addDays(2)))
    ->onFailure(fn () => Log::critical('Scheduled task FAILED: license:process-credits'));

// License notifications - runs daily at 09:00 CET
Schedule::command('license:send-notifications')
    ->dailyAt('09:00')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping()
    ->runInBackground()
    ->sentryMonitor()
    ->after(fn () => Cache::put('schedule:last_run:license-send-notifications', now(), now()->addDays(2)))
    ->onFailure(fn () => Log::critical('Scheduled task FAILED: license:send-notifications'));

// Invoice renewal processing - runs daily at 08:00 CET
Schedule::command('license:process-invoice-renewals')
    ->dailyAt('08:00')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping()
    ->runInBackground()
    ->sentryMonitor()
    ->after(fn () => Cache::put('schedule:last_run:license-process-invoice-renewals', now(), now()->addDays(2)))
    ->onFailure(fn () => Log::critical('Scheduled task FAILED: license:process-invoice-renewals'));

// Unread message notifications - runs every 5 minutes
// Sends email to users who haven't read admin replies after 30 minutes
Schedule::command('messages:send-unread-notifications')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->sentryMonitor()
    ->after(fn () => Cache::put('schedule:last_run:messages-send-unread-notifications', now(), now()->addHours(1)))
    ->onFailure(fn () => Log::critical('Scheduled task FAILED: messages:send-unread-notifications'));

// Price changes: Send notifications and apply effective price changes (daily at 09:00)
Schedule::command('license:process-price-changes --apply-effective')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->sentryMonitor()
    ->after(fn () => Cache::put('schedule:last_run:license-process-price-changes', now(), now()->addDays(2)))
    ->onFailure(fn () => Log::critical('Scheduled task FAILED: license:process-price-changes'));

/*
|--------------------------------------------------------------------------
| File Cleanup Schedule
|--------------------------------------------------------------------------
|
| These commands handle cleanup of temporary files, uploads, and conversions.
| See config/cleanup.php for retention settings.
|
*/

// Uploads: Remove files older than 1 hour (every 5 minutes)
Schedule::command('cleanup:uploads')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->sentryMonitor()
    ->after(fn () => Cache::put('schedule:last_run:cleanup-uploads', now(), now()->addHours(1)))
    ->onFailure(fn () => Log::critical('Scheduled task FAILED: cleanup:uploads'));

// Workflow temp: Remove completed/orphaned workflow files (every 15 minutes)
Schedule::command('cleanup:workflow-temp')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Temp files: Remove cover pages and extraction temp (hourly)
Schedule::command('cleanup:temp')
    ->hourly()
    ->withoutOverlapping();

// Expired batches: Remove batches past expires_at (daily at 03:00 CET)
Schedule::command('cleanup:expired-batches')
    ->dailyAt('03:00')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping()
    ->runInBackground()
    ->sentryMonitor()
    ->after(fn () => Cache::put('schedule:last_run:cleanup-expired-batches', now(), now()->addDays(2)))
    ->onFailure(fn () => Log::critical('Scheduled task FAILED: cleanup:expired-batches'));

// Conversions: Remove based on retention period (daily at 03:15)
Schedule::command('cleanup:conversions')
    ->dailyAt('03:15')
    ->withoutOverlapping();

// Failed conversions: Remove after 7 days (daily at 03:30)
Schedule::command('cleanup:failed-conversions')
    ->dailyAt('03:30')
    ->withoutOverlapping();

// API sessions: Remove expired sessions (daily at 03:45)
Schedule::command('cleanup:api-sessions')
    ->dailyAt('03:45')
    ->withoutOverlapping();

// Inbound emails: Remove expired result files (daily at 04:00)
Schedule::command('inbound:cleanup')
    ->dailyAt('04:00')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Database Cleanup Schedule
|--------------------------------------------------------------------------
*/

// Schedule cleanup of expired email change requests (daily)
Schedule::command('email:cleanup-expired-changes')->daily();

// Login codes: prune expired/used codes daily at 03:30 CET (M7)
Schedule::command('model:prune', ['--model' => [\App\Models\LoginCode::class]])
    ->dailyAt('03:30')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping()
    ->runInBackground();

// Schedule cleanup of expired invitations (daily at 03:00 CET)
Schedule::command('cleanup:expired-invitations')
    ->dailyAt('03:00')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping()
    ->runInBackground();

// Orphaned file_keys cleanup - runs weekly on Sunday at 04:00 CET
Schedule::command('cleanup:orphaned-file-keys')
    ->weeklyOn(0, '04:00')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping()
    ->runInBackground();

// Analytics sessions cleanup - runs daily at 03:00 CET
Schedule::command('analytics:cleanup-sessions')
    ->dailyAt('03:00')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping()
    ->runInBackground();

// Analytics events cleanup - runs daily at 03:30 CET
Schedule::command('analytics:cleanup-events')
    ->dailyAt('03:30')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping()
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Sender Email Schedule
|--------------------------------------------------------------------------
*/

// Check pending sender verifications (signatures + domains) - runs every 5 minutes
Schedule::command('sender:check-verifications')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Cleanup old sender logs - runs daily at 04:00 CET
Schedule::command('sender:cleanup-logs')
    ->dailyAt('04:00')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping()
    ->runInBackground();

// Generate daily stats rollup for yesterday - runs daily at 02:00 CET
Schedule::command('stats:generate')
    ->dailyAt('02:00')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping()
    ->runInBackground()
    ->sentryMonitor();
