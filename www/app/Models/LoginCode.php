<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class LoginCode extends Model
{
    use HasFactory, Prunable;

    protected $fillable = ['email', 'code', 'expires_at', 'used_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->isUsed();
    }

    /**
     * M7: prune query for `php artisan model:prune`. Codes only live 15 min,
     * so 7 days retention is generous (forensics window).
     */
    public function prunable()
    {
        return static::where('expires_at', '<', now()->subDays(7));
    }
}
