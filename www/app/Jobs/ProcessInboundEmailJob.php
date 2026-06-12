<?php

namespace App\Jobs;

use App\Models\InboundEmail;
use App\Models\InboundEmailAttachment;
use App\Services\InboundEmailEncryptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessInboundEmailJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 2;

    public function __construct(
        private InboundEmail $inboundEmail,
        private array $payload = []
    ) {
        $this->onQueue('default');
    }

    public function handle(InboundEmailEncryptionService $encryptionService): void
    {
        Log::info('ProcessInboundEmailJob started', [
            'inbound_email_id' => $this->inboundEmail->id,
            'user_id' => $this->inboundEmail->user_id,
            'action_type' => $this->inboundEmail->action_type,
        ]);

        try {
            // Mark as processing
            $this->inboundEmail->markAsProcessing();

            // Process attachments
            if (isset($this->payload['Attachments']) && ! empty($this->payload['Attachments'])) {
                $this->processAttachments($this->payload['Attachments'], $encryptionService);
            }

            // Process nested emails (email-in-email) up to 1 level deep
            if ($this->inboundEmail->nested_email_count > 0) {
                $this->processNestedEmails($encryptionService);
            }

            // TODO: In the future, dispatch action-specific processing here
            // For now, we just store the email and attachments
            // Example:
            // match ($this->inboundEmail->action_type) {
            //     'merge' => dispatch(new MergePdfAction($this->inboundEmail)),
            //     'convert' => dispatch(new ConvertToPdfAction($this->inboundEmail)),
            //     default => Log::info('No action handler for type', ['type' => $this->inboundEmail->action_type]),
            // };

            // Mark as processed
            $this->inboundEmail->markAsProcessed();

            Log::info('ProcessInboundEmailJob completed', [
                'inbound_email_id' => $this->inboundEmail->id,
            ]);
        } catch (\Exception $e) {
            Log::error('ProcessInboundEmailJob failed', [
                'inbound_email_id' => $this->inboundEmail->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->inboundEmail->markAsFailed($e->getMessage());

            throw $e;
        }
    }

    /**
     * Process email attachments
     */
    private function processAttachments(array $attachments, InboundEmailEncryptionService $encryptionService): void
    {
        $maxAttachments = config('inbound.limits.max_attachments_per_email', 20);
        $attachmentCount = min(count($attachments), $maxAttachments);

        for ($i = 0; $i < $attachmentCount; $i++) {
            try {
                $this->processAttachment($attachments[$i], $encryptionService);
            } catch (\Exception $e) {
                Log::error('Failed to process attachment', [
                    'inbound_email_id' => $this->inboundEmail->id,
                    'attachment_index' => $i,
                    'error' => $e->getMessage(),
                ]);
                $this->inboundEmail->addProcessingNote("Failed to process attachment #{$i}: {$e->getMessage()}");
            }
        }

        Log::info('Attachment processing completed', [
            'inbound_email_id' => $this->inboundEmail->id,
            'attachments_processed' => $attachmentCount,
        ]);
    }

    /**
     * Process a single attachment
     */
    private function processAttachment(array $attachmentData, InboundEmailEncryptionService $encryptionService): void
    {
        $filename = $attachmentData['Name'] ?? 'unknown';
        $contentType = $attachmentData['ContentType'] ?? 'application/octet-stream';
        $content = base64_decode($attachmentData['Content'] ?? '');
        $contentId = $attachmentData['ContentID'] ?? null;

        // Check file size limit (25MB default)
        $maxSize = config('inbound.limits.max_attachment_size_mb', 25) * 1024 * 1024;
        $size = strlen($content);

        if ($size > $maxSize) {
            $this->inboundEmail->addProcessingNote("Attachment too large: {$filename} ({$size} bytes)");
            Log::warning('Attachment too large, skipping', [
                'filename' => $filename,
                'size' => $size,
                'max_size' => $maxSize,
            ]);

            return;
        }

        // Generate unique stored filename
        $storedFilename = Str::uuid()->toString().'_'.time();
        $storagePath = "inbound-attachments/{$this->inboundEmail->id}/{$storedFilename}";

        // Encrypt and store file
        $encrypted = $encryptionService->encrypt($content);
        Storage::disk('local')->put($storagePath, $encrypted);

        // Create attachment record
        InboundEmailAttachment::create([
            'inbound_email_id' => $this->inboundEmail->id,
            'original_filename' => $filename,
            'stored_filename' => $storedFilename,
            'mime_type' => $contentType,
            'file_size' => $size,
            'file_path' => $storagePath,
            'content_id' => $contentId,
            'is_inline' => ! empty($contentId),
            'virus_scan_status' => InboundEmailAttachment::VIRUS_SCAN_PENDING,
        ]);

        Log::info('Attachment stored', [
            'inbound_email_id' => $this->inboundEmail->id,
            'filename' => $filename,
            'size' => $size,
            'storage_path' => $storagePath,
        ]);

        // TODO: When ClamAV is installed, dispatch virus scan job here
        // dispatch(new ScanAttachmentForVirusJob($attachment));
    }

    /**
     * Process nested emails (email-in-email) up to 1 level deep
     */
    private function processNestedEmails(InboundEmailEncryptionService $encryptionService): void
    {
        $this->inboundEmail->addProcessingNote('Processing nested emails (up to 1 level deep)');

        // Get attachments that are nested emails
        $nestedEmailAttachments = $this->inboundEmail->attachments()
            ->where(function ($query) {
                $query->where('mime_type', 'message/rfc822')
                    ->orWhere('original_filename', 'like', '%.eml');
            })
            ->limit(config('inbound.limits.max_nested_email_depth', 1))
            ->get();

        foreach ($nestedEmailAttachments as $attachment) {
            Log::info('Processing nested email', [
                'attachment_id' => $attachment->id,
                'filename' => $attachment->original_filename,
            ]);

            // Parse and extract nested email content
            // This would require additional email parsing logic
            // For now, we just log that we found a nested email
            $this->inboundEmail->addProcessingNote("Found nested email: {$attachment->original_filename}");
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessInboundEmailJob failed permanently', [
            'inbound_email_id' => $this->inboundEmail->id,
            'user_id' => $this->inboundEmail->user_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        $this->inboundEmail->markAsFailed('Job failed after '.$this->tries.' attempts: '.$exception->getMessage());
    }
}
