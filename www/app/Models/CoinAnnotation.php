<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A manual label on a promising period or rule-fire (brain DB). Drives rule discovery:
 * the categories (te snelle stijging, te volatiel, exchange-niet-uitvoerbaar, ...) mark
 * promising trades that won't work in practice, so we can find features that exclude them.
 */
class CoinAnnotation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'target_datetime' => 'datetime',
    ];

    /** The pulldown vocabulary (extends the legacy free-text remarks). */
    public const CATEGORIES = [
        'te snelle stijging',
        'te volatiel / schokkerig',
        'te laat',
        'stijgt niet door',
        'exchange: niet uitvoerbaar',
        'voorkomen (algemeen)',
        'goed / top',
        'anders (zie opmerking)',
    ];
}
