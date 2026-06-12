<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class OrganizationCreditPool extends Model
{
    use BelongsToOrganization;
    protected $table = 'organization_credit_pool';

    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'balance_credits',
        'updated_at',
    ];

    protected $casts = [
        'balance_credits' => 'integer',
        'updated_at' => 'datetime',
    ];

    public function getBalanceAttribute()
    {
        return $this->balance_credits;
    }
}
