<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'settings',
        'billing_country_code',
        'currency_preference',
        'vat_number',
        'vat_validated_at',
        'ipregistry_country_code',
        'ipregistry_checked_at',
        'is_trusted',
    ];

    protected $casts = [
        'settings' => 'array',
        'vat_validated_at' => 'datetime',
        'ipregistry_checked_at' => 'datetime',
        'is_trusted' => 'boolean',
    ];

    /**
     * Boot the model and auto-generate slug if not provided
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($organization) {
            if (empty($organization->slug)) {
                $slug = Str::slug($organization->name);
                $count = 1;

                // Ensure unique slug
                while (static::where('slug', $slug)->exists()) {
                    $slug = Str::slug($organization->name).'-'.$count;
                    $count++;
                }

                $organization->slug = $slug;
            }
        });
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'organization_user')
            ->withPivot('role', 'joined_at')
            ->withTimestamps()
            ->using(OrganizationUser::class);
    }

    public function creditPool()
    {
        return $this->hasOne(OrganizationCreditPool::class);
    }

    public function creditLedger()
    {
        return $this->hasMany(OrganizationCreditLedger::class);
    }

    /**
     * Get all licenses for this organization
     */
    public function organizationLicenses()
    {
        return $this->hasMany(OrganizationLicense::class);
    }

    /**
     * Get the current active licenses for this organization
     */
    public function currentLicenses()
    {
        return $this->organizationLicenses()
            ->active()
            ->current()
            ->with('license');
    }

    /**
     * Get all domains for this organization
     */
    public function domains()
    {
        return $this->hasMany(OrganizationDomain::class);
    }

    /**
     * Get all orders for this organization
     */
    public function orders()
    {
        return $this->hasMany(Order::class, 'payer_id')->where('payer_type', 'organization');
    }

    /**
     * Get all invitations for this organization
     */
    public function invitations()
    {
        return $this->hasMany(Invitation::class);
    }

    public function senderConfig()
    {
        return $this->hasOne(OrganizationSenderConfig::class);
    }

    public function senderLogs()
    {
        return $this->hasMany(OrganizationSenderLog::class);
    }

    /**
     * Check if a user is an owner of this organization
     */
    public function isAdmin(User $user): bool
    {
        return $this->users()
            ->where('user_id', $user->id)
            ->wherePivot('role', OrganizationRole::Owner)
            ->exists();
    }

    /**
     * Get all owner users for this organization
     */
    public function admins()
    {
        return $this->users()->wherePivot('role', OrganizationRole::Owner);
    }

    /**
     * Custom filter buttons for organization list view
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

                    <!-- Trusted Filters -->
                    <div class="mr-3">
                        <span class="small text-muted">Trusted:</span>
                        <a href="'.$buildUrl(['is_trusted' => '1']).'" class="btn btn-sm '.(request('is_trusted') === '1' ? 'btn-success' : 'btn-outline-success').' ml-1">Yes</a>
                        <a href="'.$buildUrl(['is_trusted' => '0']).'" class="btn btn-sm '.(request('is_trusted') === '0' ? 'btn-secondary' : 'btn-outline-secondary').' ml-1">No</a>
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
