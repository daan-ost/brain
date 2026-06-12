<?php

namespace App\Models;

use App\Enums\DemoItemPriority;
use App\Enums\DemoItemStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DemoItem extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'status',
        'priority',
        'amount',
        'due_date',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DemoItemStatus::class,
            'priority' => DemoItemPriority::class,
            'amount' => 'decimal:2',
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeWithStatus($query, DemoItemStatus $status)
    {
        return $query->where('status', $status);
    }

    public function scopeWithPriority($query, DemoItemPriority $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('due_date')
            ->where('due_date', '<', now()->startOfDay())
            ->whereNotIn('status', [DemoItemStatus::Completed->value, DemoItemStatus::Cancelled->value]);
    }

    // =========================================================================
    // STATE MACHINE
    // =========================================================================

    public function transitionTo(DemoItemStatus $newStatus): void
    {
        if (! $this->status->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Cannot transition from {$this->status->value} to {$newStatus->value}"
            );
        }

        $this->status = $newStatus;

        if ($newStatus === DemoItemStatus::Completed) {
            $this->completed_at = now();
        }

        if ($newStatus !== DemoItemStatus::Completed) {
            $this->completed_at = null;
        }

        $this->save();
    }
}
