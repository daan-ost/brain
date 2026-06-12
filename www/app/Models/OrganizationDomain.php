<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class OrganizationDomain extends Model
{
    use BelongsToOrganization;
    protected $table = 'organization_domains';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'domain',
        'is_primary',
        'validated',
        'validated_at',
        'validation_token',
        'auto_enroll_with_verified_domain',
        'max_storage_days',
        'support_email',
        'valid_until',
        'active',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'validated' => 'boolean',
        'validated_at' => 'datetime',
        'auto_enroll_with_verified_domain' => 'boolean',
        'max_storage_days' => 'integer',
        'valid_until' => 'date',
        'active' => 'boolean',
    ];

}
