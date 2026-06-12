<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsletterClick extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'recipient_id',
        'url',
        'clicked_at',
    ];

    protected $casts = [
        'clicked_at' => 'datetime',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(NewsletterRecipient::class, 'recipient_id');
    }
}
