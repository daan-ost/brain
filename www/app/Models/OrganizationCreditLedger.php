<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class OrganizationCreditLedger extends Model
{
    use BelongsToOrganization;
    protected $table = 'organization_credit_ledger';

    // Enable created_at timestamp, but disable updated_at (ledger entries are immutable)
    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'user_id',
        'batch_id',
        'workflow_id',
        'delta',
        'reason',
        'balance_after',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'delta' => 'integer',
        'balance_after' => 'integer',
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function batch()
    {
        return $this->belongsTo(Batch::class, 'batch_id', 'id');
    }

    public function workflow()
    {
        return $this->belongsTo(Workflow::class);
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

    /**
     * Generate custom filter buttons for Backpack Free
     */
    public function getCustomFilterButtons($crud = false)
    {
        $currentUrl = request()->url();
        $currentParams = request()->query();

        // Build filter URLs
        $buildUrl = function ($params) use ($currentUrl, $currentParams) {
            $merged = array_merge($currentParams, $params);

            return $currentUrl.'?'.http_build_query($merged);
        };

        return '
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center">
                    <strong class="mr-3">Filters:</strong>
                    
                    <!-- Reason Filters -->
                    <div class="mr-3">
                        <span class="small text-muted">Reason:</span>
                        <a href="'.$buildUrl(['reason' => 'purchase']).'" class="btn btn-sm '.((! request('reason') || request('reason') == 'purchase') ? 'btn-primary' : 'btn-outline-primary').' ml-1">Purchase</a>
                        <a href="'.$buildUrl(['reason' => 'spend']).'" class="btn btn-sm '.(request('reason') == 'spend' ? 'btn-primary' : 'btn-outline-primary').' ml-1">Spend</a>
                        <a href="'.$buildUrl(['reason' => 'refund']).'" class="btn btn-sm '.(request('reason') == 'refund' ? 'btn-primary' : 'btn-outline-primary').' ml-1">Refund</a>
                        <a href="'.$buildUrl(['reason' => 'adjust']).'" class="btn btn-sm '.(request('reason') == 'adjust' ? 'btn-primary' : 'btn-outline-primary').' ml-1">Adjust</a>
                        <a href="'.$buildUrl(['reason' => 'bonus']).'" class="btn btn-sm '.(request('reason') == 'bonus' ? 'btn-primary' : 'btn-outline-primary').' ml-1">Bonus</a>
                    </div>
                    
                    <!-- Transaction Type Filters -->
                    <div class="mr-3">
                        <span class="small text-muted">Type:</span>
                        <a href="'.$buildUrl(['delta_type' => 'positive']).'" class="btn btn-sm '.(request('delta_type') == 'positive' ? 'btn-success' : 'btn-outline-success').' ml-1">Credits Added (+)</a>
                        <a href="'.$buildUrl(['delta_type' => 'negative']).'" class="btn btn-sm '.(request('delta_type') == 'negative' ? 'btn-success' : 'btn-outline-success').' ml-1">Credits Spent (-)</a>
                    </div>
                    
                    <!-- Clear Filters -->
                    <div>
                        <a href="'.$currentUrl.'" class="btn btn-sm btn-outline-secondary">Clear All</a>
                    </div>
                </div>
            </div>
        </div>';
    }
}
