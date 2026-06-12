<?php

namespace App\Services;

use App\Models\MessageThread;
use App\Models\ThreadMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MessageService
{
    // Maximum attachments per message (user)
    public const MAX_ATTACHMENTS_USER = 5;

    // Maximum attachments per message (admin)
    public const MAX_ATTACHMENTS_ADMIN = 10;

    // Maximum file size per attachment - user (20MB)
    public const MAX_FILE_SIZE_KB_USER = 20480;

    // Maximum file size per attachment - admin (50MB)
    public const MAX_FILE_SIZE_KB_ADMIN = 51200;

    // Allowed MIME types
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
    ];

    // Storage disk and path
    protected string $disk = 'local';

    protected string $basePath = 'thread-attachments';

    /**
     * Store attachments and return attachment metadata array.
     *
     * @param  array<UploadedFile>  $files
     * @return array<int, array{path: string, original_name: string, type: string, size_kb: float}>
     */
    public function storeAttachments(array $files, MessageThread $thread, bool $isAdmin = false): array
    {
        $attachments = [];
        $storedCount = 0;
        $maxAttachments = $isAdmin ? self::MAX_ATTACHMENTS_ADMIN : self::MAX_ATTACHMENTS_USER;

        foreach ($files as $file) {
            if ($storedCount >= $maxAttachments) {
                Log::warning('MessageService: Max attachments reached', [
                    'thread_id' => $thread->id,
                    'max' => $maxAttachments,
                ]);
                break;
            }

            if (! $this->isValidAttachment($file, $isAdmin)) {
                Log::warning('MessageService: Invalid attachment skipped', [
                    'thread_id' => $thread->id,
                    'filename' => $file->getClientOriginalName(),
                    'mime' => $file->getMimeType(),
                    'size_kb' => round($file->getSize() / 1024),
                ]);

                continue;
            }

            $attachment = $this->storeAttachment($file, $thread);
            if ($attachment) {
                $attachments[] = $attachment;
                $storedCount++;
            }
        }

        return $attachments;
    }

    /**
     * Store a single attachment (public method for use without a thread).
     *
     * @return array{path: string, original_name: string, type: string, size_kb: float}|null
     */
    public function storeAttachment(UploadedFile $file, ?MessageThread $thread = null): ?array
    {
        try {
            $year = now()->format('Y');
            $month = now()->format('m');
            $filename = Str::ulid().'.'.$file->getClientOriginalExtension();
            $path = "{$this->basePath}/{$year}/{$month}/{$filename}";

            Storage::disk($this->disk)->put($path, file_get_contents($file->getRealPath()));

            $attachment = [
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'type' => $file->getMimeType(),
                'size_kb' => round($file->getSize() / 1024),
            ];

            Log::info('MessageService: Attachment stored', [
                'thread_id' => $thread?->id,
                'path' => $path,
                'type' => $file->getMimeType(),
                'size_kb' => $attachment['size_kb'],
            ]);

            return $attachment;
        } catch (\Exception $e) {
            Log::error('MessageService: Failed to store attachment', [
                'thread_id' => $thread?->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Validate an attachment file
     */
    public function isValidAttachment(UploadedFile $file, bool $isAdmin = false): bool
    {
        // Check file size
        $sizeKb = $file->getSize() / 1024;
        $maxSize = $isAdmin ? self::MAX_FILE_SIZE_KB_ADMIN : self::MAX_FILE_SIZE_KB_USER;
        if ($sizeKb > $maxSize) {
            return false;
        }

        // Check MIME type
        $mimeType = $file->getMimeType();
        if (! in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            return false;
        }

        return true;
    }

    /**
     * Get signed URL for an attachment (valid for 15 minutes)
     */
    public function getSignedUrl(string $path): ?string
    {
        if (! Storage::disk($this->disk)->exists($path)) {
            return null;
        }

        // For local disk, we need to create a route-based signed URL
        // Since local disk doesn't support temporaryUrl natively
        return $this->createLocalSignedUrl($path);
    }

    /**
     * Create a signed URL for local storage
     */
    protected function createLocalSignedUrl(string $path): string
    {
        // Use Laravel's signed route functionality
        return \URL::temporarySignedRoute(
            'thread.attachment.download',
            now()->addMinutes(15),
            ['path' => base64_encode($path)]
        );
    }

    /**
     * Download attachment (for route handler).
     *
     * @param  string  $encodedPath  Base64-encoded storage path
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|null
     */
    public function downloadAttachment(string $encodedPath): ?\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $path = base64_decode($encodedPath, true);

        if ($path === false) {
            return null;
        }

        // Prevent path traversal: must be within allowed base directory and contain no ..
        $normalizedPath = ltrim($path, '/');
        if (! str_starts_with($normalizedPath, $this->basePath . '/') || str_contains($normalizedPath, '..')) {
            Log::warning('MessageService: Blocked invalid attachment path', [
                'path' => $normalizedPath,
            ]);

            return null;
        }

        if (! Storage::disk($this->disk)->exists($normalizedPath)) {
            return null;
        }

        $filename = basename($normalizedPath);

        return Storage::disk($this->disk)->download($normalizedPath, $filename);
    }

    /**
     * Delete attachments for a message
     */
    public function deleteAttachments(ThreadMessage $message): void
    {
        if (! $message->hasAttachments()) {
            return;
        }

        foreach ($message->attachments as $attachment) {
            $path = $attachment['path'] ?? null;
            if ($path && Storage::disk($this->disk)->exists($path)) {
                Storage::disk($this->disk)->delete($path);

                Log::info('MessageService: Attachment deleted', [
                    'message_id' => $message->id,
                    'path' => $path,
                ]);
            }
        }
    }

    /**
     * Clean up old attachments (for scheduled job).
     *
     * @param  int  $daysOld  Number of days after which attachments are considered old
     * @return int  Number of messages whose attachments were cleaned up
     */
    public function cleanupOldAttachments(int $daysOld = 90): int
    {
        $cutoffDate = now()->subDays($daysOld);
        $deletedCount = 0;

        // Process in chunks to avoid loading all records into memory
        ThreadMessage::whereNotNull('attachments')
            ->where('created_at', '<', $cutoffDate)
            ->chunkById(100, function ($messages) use (&$deletedCount) {
                foreach ($messages as $message) {
                    $this->deleteAttachments($message);
                    $message->update(['attachments' => null]);
                    $deletedCount++;
                }
            });

        Log::info('MessageService: Cleaned up old attachments', [
            'messages_processed' => $deletedCount,
            'days_old' => $daysOld,
        ]);

        return $deletedCount;
    }

    /**
     * Get attachment URLs for a message.
     *
     * @return array<int, array{url: string, name: string, type: string, size_kb: int|float}>
     */
    public function getAttachmentUrls(ThreadMessage $message): array
    {
        if (! $message->hasAttachments()) {
            return [];
        }

        $urls = [];
        foreach ($message->attachments as $attachment) {
            $path = $attachment['path'] ?? null;
            if ($path) {
                $url = $this->getSignedUrl($path);
                if ($url) {
                    $urls[] = [
                        'url' => $url,
                        'name' => $attachment['original_name'] ?? basename($path),
                        'type' => $attachment['type'] ?? 'application/octet-stream',
                        'size_kb' => $attachment['size_kb'] ?? 0,
                    ];
                }
            }
        }

        return $urls;
    }
}
