<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InboundEmailRule extends Model
{
    protected $fillable = [
        'name',
        'conditions',
        'actions',
        'priority',
        'active',
    ];

    protected $casts = [
        'conditions' => 'array',
        'actions' => 'array',
        'priority' => 'integer',
        'active' => 'boolean',
    ];

    /**
     * Scope to only active rules
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope to rules ordered by priority
     */
    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'asc');
    }

    /**
     * Check if rule is active
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Evaluate if this rule matches the given email
     */
    public function matches(InboundEmail $email): bool
    {
        foreach ($this->conditions as $condition) {
            $field = $condition['field'] ?? null;
            $operator = $condition['operator'] ?? 'equals';
            $value = $condition['value'] ?? null;

            if (! $field || $value === null) {
                continue;
            }

            $emailValue = $email->$field ?? null;

            $match = match ($operator) {
                'equals' => $emailValue === $value,
                'contains' => str_contains($emailValue ?? '', $value),
                'starts_with' => str_starts_with($emailValue ?? '', $value),
                'ends_with' => str_ends_with($emailValue ?? '', $value),
                'not_equals' => $emailValue !== $value,
                default => false,
            };

            if (! $match) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get action description for a specific language
     */
    public function getActionDescription(string $locale = 'en'): ?string
    {
        return $this->actions['description'][$locale] ?? $this->actions['description']['en'] ?? null;
    }
}
