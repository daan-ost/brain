<?php

namespace App\Services;

use App\Models\MessageCategory;
use App\Models\MessageThread;
use App\Models\ThreadMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ThreadService
{
    /**
     * Create a new thread with initial message
     */
    public function createThread(
        User $user,
        string $categorySlug,
        ?string $content = null,
        ?string $thumb = null,
        ?string $source = null,
        array $context = [],
        array $attachments = [],
        ?string $customTitle = null
    ): MessageThread {
        return DB::transaction(function () use ($user, $categorySlug, $content, $thumb, $source, $context, $attachments, $customTitle) {
            $category = MessageCategory::where('slug', $categorySlug)->first();

            // Use custom title if provided, otherwise generate
            $title = $customTitle ?? $this->generateTitle($category, $source, $thumb);

            $thread = MessageThread::create([
                'user_id' => $user->id,
                'category_id' => $category?->id,
                'title' => $title,
                'status' => MessageThread::STATUS_OPEN,
                'source' => $source,
                'thumb' => $thumb,
                'context_json' => $context,
                'settings_json' => $this->getDefaultSettings($category),
                'last_message_at' => now(),
                'last_message_from' => MessageThread::SENDER_USER,
                'unread_count_admin' => 0, // addMessage() will increment this
            ]);

            // Create initial message if content provided
            if ($content || ! empty($attachments)) {
                $this->addMessage($thread, $user, $content ?? '', $attachments);
            }

            Log::info('ThreadService: Created new thread', [
                'thread_id' => $thread->id,
                'user_id' => $user->id,
                'category' => $categorySlug,
                'source' => $source,
                'thumb' => $thumb,
            ]);

            return $thread;
        });
    }

    /**
     * Create a conversion feedback thread (convenience method for MVP)
     */
    public function createConversionFeedback(
        User $user,
        string $thumb,
        ?string $content = null,
        array $context = [],
        array $attachments = []
    ): MessageThread {
        $source = isset($context['converter_type'])
            ? 'converter:'.$context['converter_type']
            : 'converter:unknown';

        return $this->createThread(
            $user,
            'conversion-feedback',
            $content,
            $thumb,
            $source,
            $context,
            $attachments
        );
    }

    /**
     * Add a message to an existing thread
     */
    public function addMessage(
        MessageThread $thread,
        ?User $sender,
        string $content,
        array $attachments = [],
        string $senderType = MessageThread::SENDER_USER
    ): ThreadMessage {
        return DB::transaction(function () use ($thread, $sender, $content, $attachments, $senderType) {
            $message = ThreadMessage::create([
                'thread_id' => $thread->id,
                'sender_id' => $sender?->id,
                'sender_type' => $senderType,
                'content' => $content,
                'attachments' => ! empty($attachments) ? $attachments : null,
                'is_read' => false,
            ]);

            // Update thread
            $thread->updateLastMessage($senderType);

            // Update unread counts
            if ($senderType === MessageThread::SENDER_USER) {
                $thread->incrementUnreadForAdmin();
            } else {
                $thread->incrementUnreadForUser();
            }

            Log::info('ThreadService: Added message to thread', [
                'thread_id' => $thread->id,
                'message_id' => $message->id,
                'sender_type' => $senderType,
                'has_attachments' => ! empty($attachments),
            ]);

            return $message;
        });
    }

    /**
     * Add admin reply to thread
     *
     * Note: Email notification is sent by the scheduled command
     * `messages:send-unread-notifications` after 30 minutes if user hasn't read.
     */
    public function addAdminReply(
        MessageThread $thread,
        User $admin,
        string $content,
        array $attachments = []
    ): ThreadMessage {
        $message = $this->addMessage($thread, $admin, $content, $attachments, MessageThread::SENDER_ADMIN);

        // Update status to waiting for user
        $thread->update(['status' => MessageThread::STATUS_WAITING_FOR_USER]);

        return $message;
    }

    /**
     * Close a thread
     */
    public function closeThread(MessageThread $thread, ?int $rating = null): void
    {
        $updateData = ['status' => MessageThread::STATUS_CLOSED];

        if ($rating !== null) {
            $updateData['rating'] = $rating;
        }

        $thread->update($updateData);

        Log::info('ThreadService: Thread closed', [
            'thread_id' => $thread->id,
            'rating' => $rating,
        ]);
    }

    /**
     * Reopen a thread
     */
    public function reopenThread(MessageThread $thread): void
    {
        $thread->update(['status' => MessageThread::STATUS_OPEN]);

        Log::info('ThreadService: Thread reopened', [
            'thread_id' => $thread->id,
        ]);
    }

    /**
     * Get threads for a user
     */
    public function getUserThreads(User $user, ?string $status = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = $user->messageThreads()
            ->with(['category', 'messages' => fn ($q) => $q->latest()->limit(1)])
            ->orderByDesc('last_message_at');

        if ($status) {
            $query->where('status', $status);
        }

        return $query->get();
    }

    /**
     * Get unread thread count for user
     */
    public function getUnreadCountForUser(User $user): int
    {
        return $user->messageThreads()
            ->where('unread_count_user', '>', 0)
            ->count();
    }

    /**
     * Generate thread title based on context
     */
    private function generateTitle(?MessageCategory $category, ?string $source, ?string $thumb): string
    {
        $locale = app()->getLocale();

        if ($thumb === MessageThread::THUMB_DOWN) {
            return $locale === 'nl' ? 'Negatieve feedback' : 'Negative feedback';
        }

        if ($thumb === MessageThread::THUMB_UP) {
            return $locale === 'nl' ? 'Positieve feedback' : 'Positive feedback';
        }

        if ($category) {
            return $category->name;
        }

        return $locale === 'nl' ? 'Nieuw gesprek' : 'New conversation';
    }

    /**
     * Get default settings for a thread based on category
     */
    private function getDefaultSettings(?MessageCategory $category): array
    {
        $defaults = [
            'llm_auto_reply' => false,
            'llm_suggestions_enabled' => false,
            'admin_only' => false,
            'allow_attachments' => true,
            'user_can_close' => true,
            'max_messages_user' => 50,
        ];

        if ($category) {
            $categorySettings = $category->settings_json ?? [];
            if (isset($categorySettings['llm_allowed']) && $categorySettings['llm_allowed']) {
                $defaults['llm_suggestions_enabled'] = true;
            }
        }

        return $defaults;
    }
}
