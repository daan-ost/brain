<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationSenderLog extends Model
{
    protected $fillable = [
        'organization_id',
        'recipient_email',
        'template_alias',
        'tag',
        'status',
        'postmark_message_id',
        'error_message',
        'error_code',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function log(
        int $organizationId,
        string $recipientEmail,
        string $status,
        ?string $templateAlias = null,
        ?string $tag = null,
        ?string $postmarkMessageId = null,
        ?string $errorMessage = null,
        ?string $errorCode = null
    ): self {
        return self::create([
            'organization_id' => $organizationId,
            'recipient_email' => $recipientEmail,
            'template_alias' => $templateAlias,
            'tag' => $tag,
            'status' => $status,
            'postmark_message_id' => $postmarkMessageId,
            'error_message' => $errorMessage,
            'error_code' => $errorCode,
        ]);
    }

    public static function logSent(int $organizationId, string $recipientEmail, ?string $templateAlias = null, ?string $tag = null, ?string $postmarkMessageId = null): self
    {
        return self::log($organizationId, $recipientEmail, 'sent', $templateAlias, $tag, $postmarkMessageId);
    }

    public static function logFailed(int $organizationId, string $recipientEmail, string $errorMessage, ?string $errorCode = null, ?string $templateAlias = null, ?string $tag = null): self
    {
        return self::log($organizationId, $recipientEmail, 'failed', $templateAlias, $tag, null, $errorMessage, $errorCode);
    }

    public static function logRateLimited(int $organizationId, string $recipientEmail, ?string $templateAlias = null, ?string $tag = null): self
    {
        return self::log($organizationId, $recipientEmail, 'rate_limited', $templateAlias, $tag);
    }

    public static function logBounced(int $organizationId, string $recipientEmail, ?string $templateAlias = null, ?string $tag = null): self
    {
        return self::log($organizationId, $recipientEmail, 'bounced', $templateAlias, $tag);
    }

    public static function getStats(int $organizationId, int $days = 7): array
    {
        $logs = self::where('organization_id', $organizationId)
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'sent' => $logs['sent'] ?? 0,
            'failed' => $logs['failed'] ?? 0,
            'rate_limited' => $logs['rate_limited'] ?? 0,
            'bounced' => $logs['bounced'] ?? 0,
            'total' => array_sum($logs),
        ];
    }
}
