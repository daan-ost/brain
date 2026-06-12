<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Batch;
use App\Models\FileKey;
use Illuminate\Support\Collection;

class AuditService
{
    /**
     * Log a file encryption event
     */
    public function logFileEncrypted(FileKey $fileKey, ?array $metadata = null): AuditEvent
    {
        return $this->logEvent(AuditEvent::TYPE_FILE_ENCRYPTED, [
            'file_key_id' => $fileKey->id,
            'user_id' => $fileKey->user_id,
            'guest_sid' => $fileKey->guest_sid,
            'organization_id' => $fileKey->organization_id,
            'batch_id' => $fileKey->batch_id,
            'metadata' => array_merge($metadata ?? [], [
                'original_path' => $fileKey->original_path,
                'file_size' => $fileKey->file_size,
            ]),
        ]);
    }

    /**
     * Log a file download event
     */
    public function logDownload(FileKey $fileKey, ?array $metadata = null): AuditEvent
    {
        return $this->logEvent(AuditEvent::TYPE_DOWNLOAD, [
            'file_key_id' => $fileKey->id,
            'user_id' => $fileKey->user_id,
            'guest_sid' => $fileKey->guest_sid,
            'organization_id' => $fileKey->organization_id,
            'batch_id' => $fileKey->batch_id,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log a file deletion event
     */
    public function logFileDeleted(FileKey $fileKey, ?array $metadata = null): AuditEvent
    {
        return $this->logEvent(AuditEvent::TYPE_FILE_DELETED, [
            'file_key_id' => $fileKey->id,
            'user_id' => $fileKey->user_id,
            'guest_sid' => $fileKey->guest_sid,
            'organization_id' => $fileKey->organization_id,
            'batch_id' => $fileKey->batch_id,
            'metadata' => array_merge($metadata ?? [], [
                'file_path' => $fileKey->file_path,
            ]),
        ]);
    }

    /**
     * Log a share creation event
     */
    public function logShareCreated(Batch $batch, ?array $metadata = null): AuditEvent
    {
        return $this->logEvent(AuditEvent::TYPE_SHARE_CREATED, [
            'batch_id' => $batch->id,
            'share_id' => $batch->share_token,
            'user_id' => $batch->user_id,
            'guest_sid' => $batch->guest_sid,
            'organization_id' => $batch->organization_id,
            'metadata' => array_merge($metadata ?? [], [
                'share_token' => $batch->share_token,
                'share_expires_at' => $batch->share_expires_at?->toDateTimeString(),
                'share_max_downloads' => $batch->share_max_downloads,
            ]),
        ]);
    }

    /**
     * Log a share access event
     */
    public function logShareAccessed(Batch $batch, ?array $metadata = null): AuditEvent
    {
        return $this->logEvent(AuditEvent::TYPE_SHARE_ACCESSED, [
            'batch_id' => $batch->id,
            'share_id' => $batch->share_token,
            'organization_id' => $batch->organization_id,
            'metadata' => array_merge($metadata ?? [], [
                'share_token' => $batch->share_token,
            ]),
        ]);
    }

    /**
     * Log a share revocation event
     */
    public function logShareRevoked(Batch $batch, ?array $metadata = null): AuditEvent
    {
        return $this->logEvent(AuditEvent::TYPE_SHARE_REVOKED, [
            'batch_id' => $batch->id,
            'share_id' => $batch->share_token,
            'user_id' => $batch->share_revoked_by,
            'organization_id' => $batch->organization_id,
            'metadata' => array_merge($metadata ?? [], [
                'share_token' => $batch->share_token,
            ]),
        ]);
    }

    /**
     * Log a rate limit event
     */
    public function logRateLimited(string $identifier, string $type, ?array $metadata = null): AuditEvent
    {
        return $this->logEvent(AuditEvent::TYPE_RATE_LIMITED, [
            'metadata' => array_merge($metadata ?? [], [
                'identifier' => $identifier,
                'limit_type' => $type,
            ]),
        ]);
    }

    /**
     * Get audit events for a file key
     */
    public function getFileKeyEvents(string $fileKeyId): Collection
    {
        return AuditEvent::where('file_key_id', $fileKeyId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get audit events for a batch
     */
    public function getBatchEvents(string $batchId): Collection
    {
        return AuditEvent::where('batch_id', $batchId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get audit events for an organization
     */
    public function getOrganizationEvents(int $organizationId, ?string $eventType = null): Collection
    {
        $query = AuditEvent::where('organization_id', $organizationId)
            ->orderBy('created_at', 'desc');

        if ($eventType) {
            $query->where('event_type', $eventType);
        }

        return $query->get();
    }

    /**
     * Generic event logging method
     */
    public function logEvent(string $eventType, array $attributes = []): AuditEvent
    {
        $request = request();

        return AuditEvent::create([
            'event_type' => $eventType,
            'user_id' => $attributes['user_id'] ?? auth()->id(),
            'guest_sid' => $attributes['guest_sid'] ?? null,
            'organization_id' => $attributes['organization_id'] ?? null,
            'file_key_id' => $attributes['file_key_id'] ?? null,
            'batch_id' => $attributes['batch_id'] ?? null,
            'share_id' => $attributes['share_id'] ?? null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => $attributes['metadata'] ?? null,
        ]);
    }
}
