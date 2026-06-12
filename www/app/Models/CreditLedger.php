<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreditLedger extends Model
{
    use HasFactory;

    protected $table = 'credit_ledger';

    // Enable created_at timestamp, but disable updated_at (ledger entries are immutable)
    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'delta',
        'reason',
        'balance_after',
        'meta',
        'expires_at',
        'created_at',
    ];

    protected $casts = [
        'delta' => 'integer',
        'balance_after' => 'integer',
        'meta' => 'array',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to filter by license assignment ID from meta JSON
     */
    public function scopeByLicenseAssignmentId($query, int $licenseAssignmentId)
    {
        return $query->whereRaw("JSON_EXTRACT(meta, '$.license_assignment_id') = ?", [$licenseAssignmentId]);
    }

    /**
     * Scope to filter by purchase reasons (purchase, refund, adjust)
     */
    public function scopePurchaseReasons($query)
    {
        return $query->whereIn('reason', ['purchase', 'refund', 'adjust']);
    }

    /**
     * Scope to filter by spend reason
     */
    public function scopeSpendReason($query)
    {
        return $query->where('reason', 'spend');
    }

    /**
     * Get metadata summary for Backpack CRUD display
     */
    public function getMetaSummary()
    {
        if (! $this->meta) {
            return 'No metadata';
        }
        if (is_array($this->meta)) {
            $summary = [];
            foreach ($this->meta as $key => $value) {
                $summary[] = $key.': '.(is_string($value) ? $value : json_encode($value));
            }

            return implode(', ', array_slice($summary, 0, 3)).(count($summary) > 3 ? '...' : '');
        }

        return 'Invalid data';
    }

    /**
     * Get detailed metadata display for Backpack CRUD show page
     */
    public function getMetaDisplay()
    {
        if (! $this->meta) {
            return 'No metadata';
        }
        if (is_array($this->meta)) {
            $output = '<ul>';
            foreach ($this->meta as $key => $value) {
                $displayValue = is_string($value) ? $value : json_encode($value);
                $output .= '<li><strong>'.htmlspecialchars($key).':</strong> '.htmlspecialchars($displayValue).'</li>';
            }
            $output .= '</ul>';

            return $output;
        }

        return 'Invalid data';
    }
}
