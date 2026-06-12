<?php

namespace App\Console\Commands;

use App\Mail\AdminReplyNotification;
use App\Models\MessageThread;
use App\Models\ThreadMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendUnreadMessageNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'messages:send-unread-notifications
                            {--dry-run : Run without sending emails}
                            {--delay=30 : Minutes to wait before sending notification}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send email notifications for admin replies that users have not read after 30 minutes';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $delayMinutes = (int) $this->option('delay');

        if ($dryRun) {
            $this->info('Running in dry-run mode - no emails will be sent');
        }

        $this->info("Processing unread message notifications (delay: {$delayMinutes} minutes)...");

        $stats = [
            'notifications_sent' => 0,
            'already_read' => 0,
            'already_notified' => 0,
            'errors' => 0,
        ];

        // Find admin messages that:
        // 1. Are from admin
        // 2. Were created more than X minutes ago
        // 3. Haven't had a notification sent yet
        // 4. Thread still has unread messages for user
        $messages = ThreadMessage::with(['thread.user'])
            ->where('sender_type', MessageThread::SENDER_ADMIN)
            ->where('created_at', '<=', now()->subMinutes($delayMinutes))
            ->whereNull('notification_sent_at')
            ->whereHas('thread', function ($query) {
                $query->where('unread_count_user', '>', 0);
            })
            ->get();

        $this->info("Found {$messages->count()} messages to process");

        foreach ($messages as $message) {
            try {
                $thread = $message->thread;
                $user = $thread->user;

                if (! $user) {
                    Log::warning('SendUnreadMessageNotifications: No user found for thread', [
                        'thread_id' => $thread->id,
                        'message_id' => $message->id,
                    ]);
                    $stats['errors']++;

                    continue;
                }

                // Double-check: user still hasn't read?
                if ($thread->unread_count_user <= 0) {
                    $stats['already_read']++;
                    // Mark as notified anyway to prevent re-processing
                    $message->update(['notification_sent_at' => now()]);

                    continue;
                }

                // Send email notification
                if (! $dryRun) {
                    Mail::to($user->email)->send(new AdminReplyNotification($thread, $message));

                    $message->update(['notification_sent_at' => now()]);
                }

                $stats['notifications_sent']++;

                Log::info('SendUnreadMessageNotifications: Email sent', [
                    'thread_id' => $thread->id,
                    'message_id' => $message->id,
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'dry_run' => $dryRun,
                ]);

                $this->line("  - Sent notification for thread #{$thread->id} to {$user->email}");

            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('SendUnreadMessageNotifications: Error processing message', [
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("  - Error processing message #{$message->id}: {$e->getMessage()}");
            }
        }

        // Output summary
        $this->newLine();
        $this->info('=== Summary ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Notifications sent', $stats['notifications_sent']],
                ['Already read (skipped)', $stats['already_read']],
                ['Errors', $stats['errors']],
            ]
        );

        Log::info('SendUnreadMessageNotifications completed', $stats);

        return Command::SUCCESS;
    }
}
