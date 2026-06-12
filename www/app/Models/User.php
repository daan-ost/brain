<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use App\Notifications\WelcomeConfirmEmail;
use App\Traits\TwoFactorAuthenticatable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'email_verified_at',
        'preferred_language',
        'pending_license_assignment',
        'credits',
        'credits_updated_at',
        'credits_exhausted_at',
        'last_confirmation_sent_at',
        'email_bounced_at',
        'email_bounce_type',
        'email_bounce_reason',
        'last_postmark_message_id',
        'billing_country_code',
        'country',
        'currency_preference',
        'vat_number',
        'vat_validated_at',
        'ipregistry_country_code',
        'ipregistry_checked_at',
        'timezone',
        'date_format',
        'time_format',
        'datetime_format',
        'decimal_separator',
        'first_day_of_week',
        'locale_manually_set',
        'pending_email',
        'email_change_token',
        'email_change_requested_at',
        'email_change_token_expires_at',
        'last_email_change_request_at',
        'newsletter_subscribed',
        'newsletter_unsubscribe_token',
        'newsletter_unsubscribed_at',
        'last_login_at',
        // NOTE: google_id, google_token, google_refresh_token are intentionally
        // NOT in $fillable. They're set via direct property assignment in
        // SocialiteController only — preventing mass-assignment OAuth hijack
        // (a malicious profile-update request setting google_id to an
        // attacker's value would link the attacker's Google account to any
        // user via the next callback).
        'avatar',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'google_token',
        'google_refresh_token',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'credits' => 'integer',
            'credits_updated_at' => 'datetime',
            'credits_exhausted_at' => 'datetime',
            'last_confirmation_sent_at' => 'datetime',
            'email_bounced_at' => 'datetime',
            'password' => 'hashed',
            'vat_validated_at' => 'datetime',
            'ipregistry_checked_at' => 'datetime',
            'first_day_of_week' => 'integer',
            'locale_manually_set' => 'boolean',
            'email_change_requested_at' => 'datetime',
            'email_change_token_expires_at' => 'datetime',
            'last_email_change_request_at' => 'datetime',
            'newsletter_subscribed' => 'boolean',
            'newsletter_unsubscribed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_code_timestamp' => 'integer',
            // H1: Google OAuth tokens encrypted at rest (same pattern as 2FA secret).
            'google_token' => 'encrypted',
            'google_refresh_token' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->newsletter_unsubscribe_token)) {
                $user->newsletter_unsubscribe_token = bin2hex(random_bytes(32));
            }
        });
    }

    // =========================================================================
    // NEWSLETTER METHODS
    // =========================================================================

    public function canReceiveNewsletter(): bool
    {
        return $this->newsletter_subscribed
            && $this->email_verified_at !== null
            && $this->email_bounced_at === null;
    }

    public function scopeNewsletterSubscribed($query)
    {
        return $query->where('newsletter_subscribed', true)
            ->whereNotNull('email_verified_at')
            ->whereNull('email_bounced_at');
    }

    public function newsletterRecipients()
    {
        return $this->hasMany(NewsletterRecipient::class);
    }

    // =========================================================================
    // EMAIL STATUS METHODS
    // =========================================================================

    public function isEmailConfirmed(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isEmailBounced(): bool
    {
        return $this->email_verified_at === null && $this->email_bounced_at !== null;
    }

    public function isEmailUnconfirmed(): bool
    {
        return ! $this->isEmailConfirmed() && ! $this->isEmailBounced();
    }

    public function getEmailStatus(): string
    {
        if ($this->isEmailConfirmed()) {
            return 'confirmed';
        }

        if ($this->isEmailBounced()) {
            return 'bounced';
        }

        return 'unconfirmed';
    }

    public function canResendConfirmation(): bool
    {
        if ($this->isEmailConfirmed()) {
            return false;
        }

        if (! $this->last_confirmation_sent_at) {
            return true;
        }

        return $this->last_confirmation_sent_at->diffInMinutes(now()) >= 1;
    }

    // =========================================================================
    // EMAIL CHANGE METHODS
    // =========================================================================

    public function hasPendingEmailChange(): bool
    {
        return $this->pending_email !== null
            && $this->email_change_token !== null
            && $this->email_change_token_expires_at !== null
            && $this->email_change_token_expires_at > now();
    }

    public function canRequestEmailChange(): bool
    {
        if (! $this->last_email_change_request_at) {
            return true;
        }

        return $this->last_email_change_request_at->diffInMinutes(now()) >= 5;
    }

    public function cancelPendingEmailChange(): void
    {
        $this->update([
            'pending_email' => null,
            'email_change_token' => null,
            'email_change_requested_at' => null,
            'email_change_token_expires_at' => null,
        ]);
    }

    // =========================================================================
    // PASSWORDLESS / SOCIAL LOGIN
    // =========================================================================

    public function hasGoogleLinked(): bool
    {
        return $this->google_id !== null;
    }

    public function hasPassword(): bool
    {
        // L4: a valid bcrypt/argon hash is at least 60 chars. This rejects
        // empty strings AND any short bogus value that ! empty() would accept.
        if (! is_string($this->password) || strlen($this->password) < 60) {
            return false;
        }

        // L4-extra: Laravel's `hashed` cast transforms `password=""` (set by
        // anonymizeAndDelete) into bcrypt('') which IS 60+ chars but is not a
        // usable password. Reject hashes that match empty input.
        if (password_verify('', $this->password)) {
            return false;
        }

        return true;
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function organizations()
    {
        return $this->belongsToMany(Organization::class, 'organization_user')
            ->withPivot('role', 'joined_at')
            ->withTimestamps()
            ->using(OrganizationUser::class);
    }

    public function hasOrganizationRole(Organization $org, OrganizationRole $role): bool
    {
        return $this->organizations()
            ->where('organization_id', $org->id)
            ->wherePivot('role', $role)
            ->exists();
    }

    public function creditLedger()
    {
        return $this->hasMany(CreditLedger::class);
    }

    public function userLicenses()
    {
        return $this->hasMany(UserLicense::class);
    }

    public function currentLicenses()
    {
        return $this->userLicenses()
            ->active()
            ->current()
            ->with('license');
    }

    public function freeUserLicense()
    {
        return $this->userLicenses()
            ->whereHas('license', function ($query) {
                $query->where('slug', 'free_user');
            })
            ->active()
            ->current()
            ->with('license')
            ->first();
    }

    public function messageThreads()
    {
        return $this->hasMany(MessageThread::class);
    }

    public function analyticsEvents()
    {
        return $this->hasMany(AnalyticsEvent::class);
    }

    public function webhooks()
    {
        return $this->hasMany(Webhook::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'payer_id')->where('payer_type', 'user');
    }

    public function sentInvitations()
    {
        return $this->hasMany(Invitation::class, 'invited_by');
    }

    public function inboundEmailPreference()
    {
        return $this->hasOne(UserInboundEmailPreference::class);
    }

    public function inboundEmails()
    {
        return $this->hasMany(InboundEmail::class);
    }

    public function demoItems()
    {
        return $this->hasMany(DemoItem::class);
    }

    // =========================================================================
    // LICENSE METHODS
    // =========================================================================

    public function getCurrentLicense(): ?License
    {
        // Single query with eager loading - no duplicate queries
        $allCurrentLicenses = $this->currentLicenses()
            ->whereHas('license', function ($query) {
                $query->where('active', true);
            })
            ->with('license')
            ->get();

        if ($allCurrentLicenses->isEmpty()) {
            return null;
        }

        if ($allCurrentLicenses->count() > 1) {
            $bestLicense = $allCurrentLicenses->sortByDesc(function ($userLicense) {
                return $userLicense->license->ordering ?? 0;
            })->first();

            return $bestLicense?->license;
        }

        return $allCurrentLicenses->first()->license;
    }

    public function hasActiveLicense(): bool
    {
        return $this->getCurrentLicense() !== null;
    }

    public function getTier(): ?string
    {
        $license = $this->getCurrentLicense();

        if (! $license) {
            return null;
        }

        return $license->tier;
    }

    public function isFreeTier(): bool
    {
        $personalTier = $this->getTier();

        if ($personalTier !== null && $personalTier !== 'free') {
            return false;
        }

        // Eager load organizations with their current licenses and license details in a single query
        $organizations = $this->organizations()
            ->with(['currentLicenses.license'])
            ->get();

        foreach ($organizations as $organization) {
            foreach ($organization->currentLicenses as $orgLicense) {
                if ($orgLicense->license && $orgLicense->license->tier !== 'free') {
                    return false;
                }
            }
        }

        return true;
    }

    public function hasMinimumTier(?string $requiredTier): bool
    {
        if ($requiredTier === null) {
            return true;
        }

        $currentTier = $this->getTier();

        if ($currentTier === null) {
            return false;
        }

        $tierHierarchy = [
            'free' => 1,
            'test' => 2,
            'onetime' => 3,
            'premium' => 4,
        ];

        $currentLevel = $tierHierarchy[$currentTier] ?? 0;
        $requiredLevel = $tierHierarchy[$requiredTier] ?? 0;

        return $currentLevel >= $requiredLevel;
    }

    public function getUploadLimits(?string $conversionType = null): array
    {
        $license = $this->getCurrentLicense();

        if ($license) {
            $licenseLimits = $license->getUploadLimits($conversionType);
            if (! empty($licenseLimits)) {
                return $licenseLimits;
            }
        }

        return License::getDefaultFreeUserLimits();
    }

    // =========================================================================
    // MULTI-LICENSE METHODS
    // =========================================================================

    public function getAllActiveLicenses()
    {
        $licensePriorityService = app(\App\Services\LicensePriorityService::class);

        return $licensePriorityService->getAllActiveLicenses($this);
    }

    public function getPrimaryActiveLicense()
    {
        $licensePriorityService = app(\App\Services\LicensePriorityService::class);

        return $licensePriorityService->getPrimaryActiveLicense($this);
    }

    public function hasMultipleActiveLicenses(): bool
    {
        $licensePriorityService = app(\App\Services\LicensePriorityService::class);

        return $licensePriorityService->hasMultipleActiveLicenses($this);
    }

    public function getCreditSummary(): array
    {
        $multiLicenseCreditService = app(\App\Services\MultiLicenseCreditService::class);

        return $multiLicenseCreditService->getCreditSummaryForUser($this);
    }

    public function getTotalAvailableCredits(): int
    {
        $multiLicenseCreditService = app(\App\Services\MultiLicenseCreditService::class);

        return $multiLicenseCreditService->getTotalAvailableCredits($this);
    }

    // =========================================================================
    // BACKPACK CRUD DISPLAY METHODS
    // =========================================================================

    public function getVatStatusBadge()
    {
        if ($this->vat_validated_at) {
            return '<span class="badge badge-success">Validated</span>';
        }

        return $this->vat_number ? '<span class="badge badge-warning">Pending</span>' : '<span class="badge badge-secondary">None</span>';
    }

    public function getLastActivityDisplay()
    {
        // Get last activity from analytics events
        $lastActivity = \App\Models\AnalyticsEvent::where('user_id', $this->id)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($lastActivity) {
            return $lastActivity->created_at->diffForHumans();
        }

        // Fallback to last login or account creation
        if ($this->last_login_at) {
            return $this->last_login_at->diffForHumans();
        }

        return $this->created_at->diffForHumans();
    }

    public function getCustomTabsHtml()
    {
        $profileHtml = $this->getProfileTabHtml();
        $billingHtml = $this->getBillingTabHtml();
        $organizationsHtml = $this->getOrganizationsTabHtml();
        $licensesHtml = $this->getLicensesTabHtml();
        $creditHistoryHtml = $this->getCreditHistoryTabHtml();

        return '
        <div class="mt-3">
            <ul class="nav nav-tabs" id="userTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link active" id="profile-tab" data-toggle="tab" href="#profile" role="tab">Profile</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" id="billing-tab" data-toggle="tab" href="#billing" role="tab">Billing & Credits</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" id="organizations-tab" data-toggle="tab" href="#organizations" role="tab">Organizations</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" id="licenses-tab" data-toggle="tab" href="#licenses" role="tab">Licenses</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" id="credit-history-tab" data-toggle="tab" href="#credit-history" role="tab">Credit History</a>
                </li>
            </ul>
            <div class="tab-content mt-3" id="userTabContent">
                <div class="tab-pane fade show active" id="profile" role="tabpanel">
                    '.$profileHtml.'
                </div>
                <div class="tab-pane fade" id="billing" role="tabpanel">
                    '.$billingHtml.'
                </div>
                <div class="tab-pane fade" id="organizations" role="tabpanel">
                    '.$organizationsHtml.'
                </div>
                <div class="tab-pane fade" id="licenses" role="tabpanel">
                    '.$licensesHtml.'
                </div>
                <div class="tab-pane fade" id="credit-history" role="tabpanel">
                    '.$creditHistoryHtml.'
                </div>
            </div>
        </div>';
    }

    protected function getProfileTabHtml()
    {
        $verified = $this->email_verified_at ? '<span class="badge badge-success">Verified</span>' : '<span class="badge badge-warning">Unverified</span>';
        $lastLogin = $this->last_login_at ? $this->last_login_at->diffForHumans() : 'Never';

        return '
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm">
                        <tr><td><strong>ID:</strong></td><td>'.$this->id.'</td></tr>
                        <tr><td><strong>Name:</strong></td><td>'.e($this->name).'</td></tr>
                        <tr><td><strong>Email:</strong></td><td>'.e($this->email).' '.$verified.'</td></tr>
                        <tr><td><strong>Registered:</strong></td><td>'.$this->created_at->format('Y-m-d H:i').'</td></tr>
                        <tr><td><strong>Last Login:</strong></td><td>'.$lastLogin.'</td></tr>
                    </table>
                </div>
            </div>
        ';
    }

    protected function getBillingTabHtml()
    {
        $vatStatus = $this->vat_validated_at ?
            '<span class="badge badge-success">Validated</span>' :
            ($this->vat_number ? '<span class="badge badge-warning">Pending</span>' : '<span class="badge badge-secondary">None</span>');

        $creditsColor = $this->credits > 100 ? 'success' : ($this->credits > 10 ? 'warning' : 'danger');
        $creditsUpdated = $this->credits_updated_at ? $this->credits_updated_at->diffForHumans() : 'Never';

        return '
            <div class="row">
                <div class="col-md-6">
                    <h5>Credits</h5>
                    <table class="table table-sm">
                        <tr><td><strong>Current Balance:</strong></td><td><span class="badge badge-'.$creditsColor.' badge-lg">'.$this->credits.' credits</span></td></tr>
                        <tr><td><strong>Last Updated:</strong></td><td>'.$creditsUpdated.'</td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <h5>Billing Information</h5>
                    <table class="table table-sm">
                        <tr><td><strong>Country:</strong></td><td>'.($this->billing_country_code ?: 'Not set').'</td></tr>
                        <tr><td><strong>Currency:</strong></td><td>'.($this->currency_preference ?: 'Not set').'</td></tr>
                        <tr><td><strong>VAT Number:</strong></td><td>'.($this->vat_number ?: 'None').' '.$vatStatus.'</td></tr>
                    </table>
                </div>
            </div>
        ';
    }

    protected function getOrganizationsTabHtml()
    {
        $orgs = $this->organizations;
        if ($orgs->isEmpty()) {
            return '<div class="alert alert-info">This user is not a member of any organizations.</div>';
        }

        $html = '<div class="table-responsive"><table class="table table-striped">';
        $html .= '<thead><tr><th>Organization</th><th>Role</th><th>Joined</th><th>Credits Available</th></tr></thead><tbody>';

        foreach ($orgs as $org) {
            $role = $org->pivot->role instanceof OrganizationRole ? $org->pivot->role->label() : ucfirst($org->pivot->role ?? 'editor');
            $joined = $org->pivot->joined_at ? date('Y-m-d', strtotime($org->pivot->joined_at)) : 'Unknown';
            $orgCredits = $org->creditPool ? $org->creditPool->balance : 0;
            $roleColor = $org->pivot->role === OrganizationRole::Owner ? 'primary' : 'secondary';

            $html .= '<tr>';
            $html .= '<td><strong>'.e($org->name).'</strong></td>';
            $html .= '<td><span class="badge badge-'.$roleColor.'">'.$role.'</span></td>';
            $html .= '<td>'.$joined.'</td>';
            $html .= '<td>'.$orgCredits.' credits</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }

    protected function getLicensesTabHtml()
    {
        $licenses = $this->userLicenses()->with('license')->get();
        if ($licenses->isEmpty()) {
            return '<div class="alert alert-info">No licenses assigned to this user.</div>';
        }

        $html = '<div class="table-responsive"><table class="table table-striped">';
        $html .= '<thead><tr><th>License</th><th>Tier</th><th>Status</th><th>Period</th><th>Source</th></tr></thead><tbody>';

        foreach ($licenses as $userLicense) {
            $license = $userLicense->license;
            $statusColor = $userLicense->status === 'active' ? 'success' : 'secondary';
            $period = '';
            if ($userLicense->starts_at || $userLicense->ends_at) {
                $start = $userLicense->starts_at ? $userLicense->starts_at->format('Y-m-d') : '∞';
                $end = $userLicense->ends_at ? $userLicense->ends_at->format('Y-m-d') : '∞';
                $period = $start.' → '.$end;
            } else {
                $period = 'Lifetime';
            }

            $html .= '<tr>';
            $html .= '<td><strong>'.e($license->name).'</strong></td>';
            $html .= '<td><span class="badge badge-info">'.e($license->tier).'</span></td>';
            $html .= '<td><span class="badge badge-'.$statusColor.'">'.e($userLicense->status).'</span></td>';
            $html .= '<td>'.$period.'</td>';
            $html .= '<td>'.e($userLicense->source ?: 'manual').'</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }

    protected function getCreditHistoryTabHtml()
    {
        $ledger = $this->creditLedger()->latest()->limit(25)->get();
        if ($ledger->isEmpty()) {
            return '<div class="alert alert-info">No credit transaction history.</div>';
        }

        $html = '<div style="max-height: 400px; overflow-y: auto;"><table class="table table-sm table-striped">';
        $html .= '<thead class="thead-light"><tr><th>Date</th><th>Change</th><th>Reason</th><th>Balance After</th><th>Details</th></tr></thead><tbody>';

        foreach ($ledger as $transaction) {
            $color = $transaction->delta > 0 ? 'success' : 'danger';
            $sign = $transaction->delta > 0 ? '+' : '';
            $details = '';
            if ($transaction->meta) {
                if (isset($transaction->meta['admin_reason'])) {
                    $details = $transaction->meta['admin_reason'];
                }
            }

            $html .= '<tr>';
            $html .= '<td>'.$transaction->created_at->format('Y-m-d H:i').'</td>';
            $html .= '<td><span class="badge badge-'.$color.'">'.$sign.$transaction->delta.'</span></td>';
            $html .= '<td>'.ucwords(str_replace('_', ' ', $transaction->reason)).'</td>';
            $html .= '<td><strong>'.$transaction->balance_after.'</strong></td>';
            $html .= '<td><small class="text-muted">'.e($details).'</small></td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }

    // =========================================================================
    // GDPR
    // =========================================================================

    /**
     * Anonymize personal data and soft-delete the user.
     * Preserves the record for audit/billing purposes while removing PII.
     */
    public function anonymizeAndDelete(): void
    {
        $this->update([
            'name' => 'Deleted User #'.$this->id,
            'email' => "deleted_{$this->id}@removed.invalid",
            'password' => null,
            'pending_email' => null,
            'email_change_token' => null,
            'vat_number' => null,
            'newsletter_subscribed' => false,
            'newsletter_unsubscribe_token' => null,
        ]);

        // Revoke all API tokens
        $this->tokens()->delete();

        $this->delete();
    }

    // =========================================================================
    // EMAIL VERIFICATION
    // =========================================================================

    public function sendEmailVerificationNotification(): void
    {
        $locale = $this->preferred_language ?? app()->getLocale();
        if (! in_array($locale, ['en', 'nl'])) {
            $locale = 'en';
        }

        $this->notify(new WelcomeConfirmEmail($locale));

        $this->update([
            'last_confirmation_sent_at' => now(),
        ]);
    }

    // =========================================================================
    // FILAMENT ADMIN ACCESS
    // =========================================================================

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'admin') {
            // Support both Spatie role and legacy is_admin attribute
            return $this->hasRole('admin') || (bool) $this->is_admin;
        }

        return false;
    }
}
