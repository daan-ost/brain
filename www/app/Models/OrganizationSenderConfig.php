<?php

namespace App\Models;

use App\Enums\SenderConfigStatus;
use App\Enums\SenderLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationSenderConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'sender_level',
        'status',
        'from_email',
        'from_name',
        'reply_to_email',
        'domain',
        'postmark_signature_id',
        'postmark_domain_id',
        'dns_records',
        'verified_at',
        'failure_reason',
    ];

    protected $casts = [
        'sender_level' => SenderLevel::class,
        'status' => SenderConfigStatus::class,
        'dns_records' => 'array',
        'verified_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isUsable(): bool
    {
        if ($this->sender_level === SenderLevel::ReplyTo) {
            return $this->status === SenderConfigStatus::Active;
        }

        return $this->status === SenderConfigStatus::Verified;
    }

    public function scopeUsable($query)
    {
        return $query->where(function ($q) {
            $q->where('status', SenderConfigStatus::Active)
                ->orWhere('status', SenderConfigStatus::Verified);
        });
    }
}
