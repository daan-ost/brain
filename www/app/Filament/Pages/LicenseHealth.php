<?php

namespace App\Filament\Pages;

use App\Models\OrganizationLicense;
use App\Models\UserLicense;
use App\Services\LicenseCreditResetService;
use App\Services\LicenseRenewalService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

class LicenseHealth extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static string $view = 'filament.pages.license-health';

    protected static ?string $navigationGroup = 'Licensing';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'License Health';

    public string $activeTab = 'overdue';

    public string $filterTier = '';

    public string $filterType = '';

    public static function getNavigationBadge(): ?string
    {
        $count = static::getOverdueCount() + static::getExpiredNotMarkedCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bulkCheck')
                ->label('Controleer alle licenties')
                ->icon('heroicon-o-magnifying-glass-circle')
                ->color('gray')
                ->action(function () {
                    $this->runBulkCheck();
                }),
        ];
    }

    /**
     * Get the status card data for the dashboard overview.
     *
     * @return array<int, array{label: string, value: int, color: string, icon: string}>
     */
    public function getStatusCards(): array
    {
        $overdueResets = $this->getOverdueResets();
        $expiringSoon = $this->getExpiringSoon();
        $unpaidInvoices = $this->getUnpaidInvoices();
        $healthy = $this->getHealthyCount($overdueResets->count(), $expiringSoon->count());

        return [
            [
                'label' => 'Overdue Resets',
                'value' => $overdueResets->count(),
                'color' => $overdueResets->count() > 0 ? 'danger' : 'success',
                'icon' => 'heroicon-o-clock',
            ],
            [
                'label' => 'Expiring Soon',
                'value' => $expiringSoon->count(),
                'color' => $expiringSoon->count() > 0 ? 'warning' : 'success',
                'icon' => 'heroicon-o-exclamation-triangle',
            ],
            [
                'label' => 'Unpaid Invoices',
                'value' => $unpaidInvoices->count(),
                'color' => $unpaidInvoices->count() > 0 ? 'danger' : 'success',
                'icon' => 'heroicon-o-banknotes',
            ],
            [
                'label' => 'Healthy',
                'value' => $healthy,
                'color' => 'success',
                'icon' => 'heroicon-o-check-circle',
            ],
        ];
    }

    // ==================== OVERDUE RESETS ====================

    /**
     * Get all licenses with overdue credit resets (free + premium user, premium org).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getOverdueResets(): Collection
    {
        $resetService = app(LicenseCreditResetService::class);
        $renewalService = app(LicenseRenewalService::class);
        $overdueItems = collect();

        // User licenses - Free tier
        $freeUserLicenses = UserLicense::with(['license', 'user'])
            ->whereIn('status', ['active', 'trial'])
            ->whereHas('license', fn ($q) => $q->where('tier', 'free')->where('active', true))
            ->get();

        foreach ($freeUserLicenses as $license) {
            if ($resetService->shouldResetFreeCredits($license)) {
                $lastReset = $license->last_credit_reset_at ?? $license->starts_at ?? $license->created_at;
                $expectedReset = $lastReset->copy()->addDays(30);
                // Days overdue = how many days since the expected reset date (positive number)
                $daysOverdue = (int) $expectedReset->diffInDays(now(), false);

                $overdueItems->push([
                    'type' => 'user',
                    'license_id' => $license->id,
                    'name' => $license->user->name ?? $license->user->email,
                    'license_name' => $license->license->name,
                    'tier' => $license->license->tier,
                    'reset_interval' => $license->license->credit_reset_interval ?? '30 days',
                    'last_reset' => $lastReset,
                    'expected_reset' => $expectedReset,
                    'days_overdue' => $daysOverdue,
                    'current_balance' => $license->user->credits ?? 0,
                ]);
            }
        }

        // User licenses - Premium tier
        $premiumUserLicenses = UserLicense::with(['license', 'user'])
            ->where('status', 'active')
            ->whereHas('license', fn ($q) => $q->where('tier', 'premium')->where('active', true))
            ->get();

        foreach ($premiumUserLicenses as $license) {
            if ($resetService->shouldResetPremiumCredits($license)) {
                $resetInterval = $license->license->credit_reset_interval ?? 'monthly';
                $lastReset = $license->last_credit_reset_at ?? $license->starts_at;
                $previousRenewal = $renewalService->getPreviousRenewalDate($license->starts_at, $resetInterval);
                // Days overdue = how many days since the previous renewal date (positive number)
                $daysOverdue = (int) $previousRenewal->diffInDays(now(), false);

                $overdueItems->push([
                    'type' => 'user',
                    'license_id' => $license->id,
                    'name' => $license->user->name ?? $license->user->email,
                    'license_name' => $license->license->name,
                    'tier' => $license->license->tier,
                    'reset_interval' => $resetInterval,
                    'last_reset' => $lastReset,
                    'expected_reset' => $previousRenewal,
                    'days_overdue' => $daysOverdue,
                    'current_balance' => $license->user->credits ?? 0,
                ]);
            }
        }

        // Organization licenses - Premium tier
        $premiumOrgLicenses = OrganizationLicense::with(['license', 'organization.creditPool'])
            ->where('status', 'active')
            ->whereHas('license', fn ($q) => $q->where('tier', 'premium')->where('active', true))
            ->get();

        foreach ($premiumOrgLicenses as $license) {
            $resetInterval = $license->license->credit_reset_interval ?? 'monthly';
            $lastReset = $license->last_credit_reset_at ?? $license->starts_at;
            $previousRenewal = $renewalService->getPreviousRenewalDate($license->starts_at, $resetInterval);

            if ($lastReset->lt($previousRenewal)) {
                // Days overdue = how many days since the previous renewal date (positive number)
                $daysOverdue = (int) $previousRenewal->diffInDays(now(), false);

                $overdueItems->push([
                    'type' => 'organization',
                    'license_id' => $license->id,
                    'name' => $license->organization->name,
                    'license_name' => $license->license->name,
                    'tier' => $license->license->tier,
                    'reset_interval' => $resetInterval,
                    'last_reset' => $lastReset,
                    'expected_reset' => $previousRenewal,
                    'days_overdue' => $daysOverdue,
                    'current_balance' => $license->organization->creditPool->balance_credits ?? 0,
                ]);
            }
        }

        return $overdueItems->sortByDesc('days_overdue')->values();
    }

    // ==================== EXPIRING ====================

    /**
     * Get licenses that are expired-but-not-marked and expiring within 14 days.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getExpiringSoon(): Collection
    {
        $items = collect();

        // Expired but not marked (User)
        $expiredUserLicenses = UserLicense::with(['license', 'user'])
            ->whereIn('status', ['active', 'canceled'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->whereHas('license', fn ($q) => $q->where('active', true))
            ->get();

        foreach ($expiredUserLicenses as $license) {
            // Days remaining = negative value for expired licenses (ends_at is in the past)
            $daysRemaining = (int) now()->diffInDays($license->ends_at, false);

            $items->push([
                'type' => 'user',
                'license_id' => $license->id,
                'name' => $license->user->name ?? $license->user->email,
                'license_name' => $license->license->name,
                'tier' => $license->license->tier,
                'status' => $license->status,
                'ends_at' => $license->ends_at,
                'days_remaining' => $daysRemaining,
                'section' => 'expired_not_marked',
            ]);
        }

        // Expired but not marked (Org)
        $expiredOrgLicenses = OrganizationLicense::with(['license', 'organization'])
            ->whereIn('status', ['active', 'canceled'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->whereHas('license', fn ($q) => $q->where('active', true))
            ->get();

        foreach ($expiredOrgLicenses as $license) {
            // Days remaining = negative value for expired licenses (ends_at is in the past)
            $daysRemaining = (int) now()->diffInDays($license->ends_at, false);

            $items->push([
                'type' => 'organization',
                'license_id' => $license->id,
                'name' => $license->organization->name,
                'license_name' => $license->license->name,
                'tier' => $license->license->tier,
                'status' => $license->status,
                'ends_at' => $license->ends_at,
                'days_remaining' => $daysRemaining,
                'section' => 'expired_not_marked',
            ]);
        }

        // Expiring within 14 days (User)
        $expiringUserLicenses = UserLicense::with(['license', 'user'])
            ->whereIn('status', ['active', 'canceled'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '>=', now())
            ->where('ends_at', '<=', now()->addDays(14))
            ->whereHas('license', fn ($q) => $q->where('active', true))
            ->get();

        foreach ($expiringUserLicenses as $license) {
            // Days remaining = positive value for licenses expiring in the future
            $daysRemaining = (int) now()->diffInDays($license->ends_at, false);

            $items->push([
                'type' => 'user',
                'license_id' => $license->id,
                'name' => $license->user->name ?? $license->user->email,
                'license_name' => $license->license->name,
                'tier' => $license->license->tier,
                'status' => $license->status,
                'ends_at' => $license->ends_at,
                'days_remaining' => $daysRemaining,
                'section' => 'expiring_soon',
            ]);
        }

        // Expiring within 14 days (Org)
        $expiringOrgLicenses = OrganizationLicense::with(['license', 'organization'])
            ->whereIn('status', ['active', 'canceled'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '>=', now())
            ->where('ends_at', '<=', now()->addDays(14))
            ->whereHas('license', fn ($q) => $q->where('active', true))
            ->get();

        foreach ($expiringOrgLicenses as $license) {
            // Days remaining = positive value for licenses expiring in the future
            $daysRemaining = (int) now()->diffInDays($license->ends_at, false);

            $items->push([
                'type' => 'organization',
                'license_id' => $license->id,
                'name' => $license->organization->name,
                'license_name' => $license->license->name,
                'tier' => $license->license->tier,
                'status' => $license->status,
                'ends_at' => $license->ends_at,
                'days_remaining' => $daysRemaining,
                'section' => 'expiring_soon',
            ]);
        }

        return $items->sortBy('days_remaining')->values();
    }

    // ==================== UNPAID INVOICES ====================

    /**
     * Get organization licenses with overdue unpaid invoices.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getUnpaidInvoices(): Collection
    {
        return OrganizationLicense::with(['license', 'organization'])
            ->where('payment_status', 'unpaid')
            ->whereNotNull('invoice_due_date')
            ->where('invoice_due_date', '<', now())
            ->whereHas('license', fn ($q) => $q->where('active', true))
            ->get()
            ->map(fn ($license) => [
                'type' => 'organization',
                'license_id' => $license->id,
                'name' => $license->organization->name,
                'license_name' => $license->license->name,
                'invoice_number' => $license->invoice_number,
                'invoice_due_date' => $license->invoice_due_date,
                // Days overdue = positive value (invoice_due_date is in the past)
                'days_overdue' => (int) $license->invoice_due_date->diffInDays(now(), false),
            ]);
    }

    // ==================== HEALTHY COUNT ====================

    /**
     * Calculate the number of healthy (non-problematic) active licenses.
     *
     * Accepts pre-computed counts to avoid redundant expensive queries
     * when called from getStatusCards().
     */
    public function getHealthyCount(?int $overdueCount = null, ?int $expiringCount = null): int
    {
        $activeUserCount = UserLicense::where('status', 'active')
            ->whereHas('license', fn ($q) => $q->where('active', true))
            ->count();

        $activeOrgCount = OrganizationLicense::where('status', 'active')
            ->whereHas('license', fn ($q) => $q->where('active', true))
            ->count();

        $overdueCount ??= $this->getOverdueResets()->count();
        $expiringCount ??= $this->getExpiringSoon()->count();

        return max(0, ($activeUserCount + $activeOrgCount) - $overdueCount - $expiringCount);
    }

    // ==================== ALL ACTIVE LICENSES ====================

    /**
     * Get all active licenses with optional tier/type filtering.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getAllActiveLicenses(): Collection
    {
        $renewalService = app(LicenseRenewalService::class);
        $items = collect();

        // User licenses
        $userQuery = UserLicense::with(['license', 'user'])
            ->where('status', 'active')
            ->whereHas('license', fn ($q) => $q->where('active', true));

        if ($this->filterTier) {
            $userQuery->whereHas('license', fn ($q) => $q->where('tier', $this->filterTier));
        }

        if ($this->filterType === '' || $this->filterType === 'user') {
            foreach ($userQuery->get() as $license) {
                $resetInterval = $license->license->credit_reset_interval ?? 'monthly';
                $nextReset = null;

                if ($license->license->tier !== 'onetime') {
                    if ($license->license->tier === 'free') {
                        $lastReset = $license->last_credit_reset_at ?? $license->starts_at ?? $license->created_at;
                        $nextReset = $lastReset->copy()->addDays(30);
                    } else {
                        $nextReset = $renewalService->getNextRenewalDate($license->starts_at, $resetInterval);
                    }
                }

                $items->push([
                    'type' => 'user',
                    'license_id' => $license->id,
                    'name' => $license->user->name ?? $license->user->email,
                    'license_name' => $license->license->name,
                    'tier' => $license->license->tier,
                    'billing_cycle' => $license->license->billing_cycle,
                    'status' => $license->status,
                    'starts_at' => $license->starts_at,
                    'ends_at' => $license->ends_at,
                    'last_reset' => $license->last_credit_reset_at,
                    'next_reset' => $nextReset,
                    'balance' => $license->user->credits ?? 0,
                ]);
            }
        }

        // Organization licenses
        $orgQuery = OrganizationLicense::with(['license', 'organization.creditPool'])
            ->where('status', 'active')
            ->whereHas('license', fn ($q) => $q->where('active', true));

        if ($this->filterTier) {
            $orgQuery->whereHas('license', fn ($q) => $q->where('tier', $this->filterTier));
        }

        if ($this->filterType === '' || $this->filterType === 'organization') {
            foreach ($orgQuery->get() as $license) {
                $resetInterval = $license->license->credit_reset_interval ?? 'monthly';
                $nextReset = null;

                if ($license->license->tier !== 'onetime') {
                    $nextReset = $renewalService->getNextRenewalDate($license->starts_at, $resetInterval);
                }

                $items->push([
                    'type' => 'organization',
                    'license_id' => $license->id,
                    'name' => $license->organization->name,
                    'license_name' => $license->license->name,
                    'tier' => $license->license->tier,
                    'billing_cycle' => $license->license->billing_cycle,
                    'status' => $license->status,
                    'starts_at' => $license->starts_at,
                    'ends_at' => $license->ends_at,
                    'last_reset' => $license->last_credit_reset_at,
                    'next_reset' => $nextReset,
                    'balance' => $license->organization->creditPool->balance_credits ?? 0,
                ]);
            }
        }

        return $items->values();
    }

    // ==================== MANUAL RESET ====================

    /**
     * Manually trigger a credit reset for a specific license.
     *
     * @param  string  $type  Either 'user' or 'organization'
     * @param  int  $licenseId  The user_license or organization_license ID
     */
    public function manualReset(string $type, int $licenseId): void
    {
        if (! in_array($type, ['user', 'organization'], true)) {
            Notification::make()
                ->title('Ongeldig type')
                ->body('Type moet "user" of "organization" zijn.')
                ->danger()
                ->send();

            return;
        }

        $resetService = app(LicenseCreditResetService::class);

        try {
            if ($type === 'user') {
                $license = UserLicense::with(['license', 'user'])->findOrFail($licenseId);

                $result = match ($license->license->tier) {
                    'free' => $resetService->processFreeTierReset($license),
                    'premium' => $resetService->processPremiumReset($license),
                    default => false,
                };

                if ($result) {
                    $license->user->refresh();
                    Notification::make()
                        ->title('Credits gereset')
                        ->body("Credits voor {$license->user->name} zijn gereset naar {$license->user->credits}.")
                        ->success()
                        ->send();
                } else {
                    Notification::make()
                        ->title('Reset niet nodig')
                        ->body('De licentie heeft op dit moment geen reset nodig.')
                        ->warning()
                        ->send();
                }
            } else {
                $license = OrganizationLicense::with(['license', 'organization.creditPool'])->findOrFail($licenseId);

                $result = match ($license->license->tier) {
                    'premium' => $resetService->processOrganizationPremiumReset($license),
                    default => false,
                };

                if ($result) {
                    $license->organization->creditPool->refresh();
                    Notification::make()
                        ->title('Credits gereset')
                        ->body("Credits voor {$license->organization->name} zijn gereset naar {$license->organization->creditPool->balance_credits}.")
                        ->success()
                        ->send();
                } else {
                    Notification::make()
                        ->title('Reset niet nodig')
                        ->body('De licentie heeft op dit moment geen reset nodig.')
                        ->warning()
                        ->send();
                }
            }
        } catch (ModelNotFoundException $e) {
            Notification::make()
                ->title('Licentie niet gevonden')
                ->body('De opgegeven licentie kon niet worden gevonden.')
                ->danger()
                ->send();
        } catch (\RuntimeException $e) {
            Notification::make()
                ->title('Fout bij reset')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Get data for the reset confirmation dialog.
     *
     * @return array{current_balance: int, expected_balance: int, surplus: int, tier: string, name: string}
     */
    public function getResetConfirmationData(string $type, int $licenseId): array
    {
        if ($type === 'user') {
            $license = UserLicense::with(['license', 'user'])->findOrFail($licenseId);
            $currentBalance = $license->user->credits ?? 0;
            $licenseCredits = $license->license->credits;

            if ($license->license->tier === 'premium') {
                $surplus = max(0, $currentBalance - $licenseCredits);
                $expectedBalance = $licenseCredits + $surplus;

                return [
                    'current_balance' => $currentBalance,
                    'expected_balance' => $expectedBalance,
                    'surplus' => $surplus,
                    'tier' => 'premium',
                    'name' => $license->user->name ?? $license->user->email,
                ];
            }

            return [
                'current_balance' => $currentBalance,
                'expected_balance' => $licenseCredits,
                'surplus' => 0,
                'tier' => 'free',
                'name' => $license->user->name ?? $license->user->email,
            ];
        }

        $license = OrganizationLicense::with(['license', 'organization.creditPool'])->findOrFail($licenseId);
        $currentBalance = $license->organization->creditPool->balance_credits ?? 0;
        $licenseCredits = $license->license->credits;
        $surplus = max(0, $currentBalance - $licenseCredits);
        $expectedBalance = $licenseCredits + $surplus;

        return [
            'current_balance' => $currentBalance,
            'expected_balance' => $expectedBalance,
            'surplus' => $surplus,
            'tier' => 'premium',
            'name' => $license->organization->name,
        ];
    }

    // ==================== BULK CHECK ====================

    /**
     * Run a dry-run check across all licenses and report pending actions.
     */
    public function runBulkCheck(): void
    {
        $resetService = app(LicenseCreditResetService::class);
        $renewalService = app(LicenseRenewalService::class);

        $stats = [
            'free_resets' => 0,
            'premium_resets' => 0,
            'org_premium_resets' => 0,
            'expirations' => 0,
        ];

        // Check user free tier
        $freeUserLicenses = UserLicense::with(['license'])
            ->whereIn('status', ['active', 'trial'])
            ->whereHas('license', fn ($q) => $q->where('tier', 'free')->where('active', true))
            ->get();

        foreach ($freeUserLicenses as $license) {
            if ($resetService->shouldResetFreeCredits($license)) {
                $stats['free_resets']++;
            }
        }

        // Check user premium
        $premiumUserLicenses = UserLicense::with(['license'])
            ->where('status', 'active')
            ->whereHas('license', fn ($q) => $q->where('tier', 'premium')->where('active', true))
            ->get();

        foreach ($premiumUserLicenses as $license) {
            if ($resetService->shouldResetPremiumCredits($license)) {
                $stats['premium_resets']++;
            }
        }

        // Check org premium
        $premiumOrgLicenses = OrganizationLicense::with(['license'])
            ->where('status', 'active')
            ->whereHas('license', fn ($q) => $q->where('tier', 'premium')->where('active', true))
            ->get();

        foreach ($premiumOrgLicenses as $license) {
            $resetInterval = $license->license->credit_reset_interval ?? 'monthly';
            $lastReset = $license->last_credit_reset_at ?? $license->starts_at;
            $previousRenewal = $renewalService->getPreviousRenewalDate($license->starts_at, $resetInterval);

            if ($lastReset->lt($previousRenewal)) {
                $stats['org_premium_resets']++;
            }
        }

        // Check expirations (user + org)
        $stats['expirations'] += UserLicense::whereIn('status', ['active', 'canceled'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->count();

        $stats['expirations'] += OrganizationLicense::whereIn('status', ['active', 'canceled'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->count();

        Notification::make()
            ->title('Bulk check voltooid')
            ->body(
                "{$stats['free_resets']} free resets pending, " .
                "{$stats['premium_resets']} premium resets pending, " .
                "{$stats['org_premium_resets']} org premium resets pending, " .
                "{$stats['expirations']} expirations"
            )
            ->info()
            ->duration(10000)
            ->send();
    }

    // ==================== HELPERS ====================

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function updatedFilterTier(): void
    {
        // Livewire reactivity handles re-render
    }

    public function updatedFilterType(): void
    {
        // Livewire reactivity handles re-render
    }

    private static function getOverdueCount(): int
    {
        $resetService = app(LicenseCreditResetService::class);
        $renewalService = app(LicenseRenewalService::class);
        $count = 0;

        // Free tier user overdue
        $freeUserLicenses = UserLicense::with(['license'])
            ->whereIn('status', ['active', 'trial'])
            ->whereHas('license', fn ($q) => $q->where('tier', 'free')->where('active', true))
            ->get();

        foreach ($freeUserLicenses as $license) {
            if ($resetService->shouldResetFreeCredits($license)) {
                $count++;
            }
        }

        // Premium user overdue
        $premiumUserLicenses = UserLicense::with(['license'])
            ->where('status', 'active')
            ->whereHas('license', fn ($q) => $q->where('tier', 'premium')->where('active', true))
            ->get();

        foreach ($premiumUserLicenses as $license) {
            if ($resetService->shouldResetPremiumCredits($license)) {
                $count++;
            }
        }

        // Org premium overdue
        $premiumOrgLicenses = OrganizationLicense::with(['license'])
            ->where('status', 'active')
            ->whereHas('license', fn ($q) => $q->where('tier', 'premium')->where('active', true))
            ->get();

        foreach ($premiumOrgLicenses as $license) {
            $resetInterval = $license->license->credit_reset_interval ?? 'monthly';
            $lastReset = $license->last_credit_reset_at ?? $license->starts_at;
            $previousRenewal = $renewalService->getPreviousRenewalDate($license->starts_at, $resetInterval);

            if ($lastReset->lt($previousRenewal)) {
                $count++;
            }
        }

        return $count;
    }

    private static function getExpiredNotMarkedCount(): int
    {
        $userCount = UserLicense::whereIn('status', ['active', 'canceled'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->count();

        $orgCount = OrganizationLicense::whereIn('status', ['active', 'canceled'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->count();

        return $userCount + $orgCount;
    }
}
