<?php

namespace App\Console\Commands;

use App\Jobs\SendPostmarkTemplateEmail;
use App\Models\CreditLedger;
use App\Models\LicenseNotification;
use App\Models\OrganizationCreditLedger;
use App\Models\OrganizationLicense;
use App\Models\UserLicense;
use App\Services\LicenseRenewalService;
use App\Services\LocaleService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendLicenseNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'license:send-notifications {--dry-run : Run without sending emails}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send license expiry, renewal, and low credit notifications';

    private LicenseRenewalService $renewalService;

    public function __construct(LicenseRenewalService $renewalService)
    {
        parent::__construct();
        $this->renewalService = $renewalService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('Running in dry-run mode - no emails will be sent');
        }

        $this->info('Starting license notification processing...');

        $stats = [
            'expiry_7_days' => 0,
            'expiry_1_day' => 0,
            'renewal_7_days' => 0,
            'low_credits' => 0,
            'low_credits_org' => 0,
            'skipped_already_sent' => 0,
            'errors' => 0,
        ];

        // Process different notification types
        $this->processExpiryNotifications($stats, $dryRun);
        $this->processRenewalNotifications($stats, $dryRun);
        $this->processLowCreditNotifications($stats, $dryRun);
        $this->processOrganizationLowCreditNotifications($stats, $dryRun);

        // Output summary
        $this->newLine();
        $this->info('=== Notifications Complete ===');
        $this->table(
            ['Notification Type', 'Count'],
            [
                ['Expiry (7 days)', $stats['expiry_7_days']],
                ['Expiry (1 day)', $stats['expiry_1_day']],
                ['Renewal (7 days)', $stats['renewal_7_days']],
                ['Low credits (user)', $stats['low_credits']],
                ['Low credits (org)', $stats['low_credits_org']],
                ['Skipped (already sent)', $stats['skipped_already_sent']],
                ['Errors', $stats['errors']],
            ]
        );

        Log::info('License notification processing completed', $stats);

        return Command::SUCCESS;
    }

    /**
     * Process expiry notifications for onetime and canceled premium licenses
     */
    private function processExpiryNotifications(array &$stats, bool $dryRun): void
    {
        $this->info('Processing expiry notifications...');

        // Get licenses expiring in 7 days or 1 day
        // Only notify on is_current=true to avoid sending expiry mails for
        // superseded licenses when the user already bought a newer license.
        $licenses = UserLicense::with(['license', 'user'])
            ->where('is_current', true)
            ->where(function ($q) {
                // Onetime licenses
                $q->whereHas('license', fn ($lq) => $lq->where('tier', 'onetime'))
                    ->where('status', UserLicense::STATUS_ACTIVE);
            })
            ->orWhere(function ($q) {
                // Canceled premium licenses
                $q->whereHas('license', fn ($lq) => $lq->where('tier', 'premium'))
                    ->where('status', UserLicense::STATUS_CANCELED)
                    ->where('is_current', true);
            })
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now())
            ->get();

        foreach ($licenses as $license) {
            try {
                $daysUntilExpiry = now()->diffInDays($license->ends_at, false);

                // Check for 7-day notification
                if ($daysUntilExpiry <= 7 && $daysUntilExpiry > 1) {
                    $this->sendExpiryNotification($license, 7, $stats, $dryRun);
                }
                // Check for 1-day notification
                elseif ($daysUntilExpiry <= 1 && $daysUntilExpiry >= 0) {
                    $this->sendExpiryNotification($license, 1, $stats, $dryRun);
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('Error processing expiry notification', [
                    'license_id' => $license->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send expiry notification
     */
    private function sendExpiryNotification(UserLicense $license, int $days, array &$stats, bool $dryRun): void
    {
        $notificationType = $days === 7 ? LicenseNotification::TYPE_EXPIRY_7_DAYS : LicenseNotification::TYPE_EXPIRY_1_DAY;

        // Check if already sent
        if (LicenseNotification::wasRecentlySent($license->id, null, $notificationType, 7)) {
            $stats['skipped_already_sent']++;

            return;
        }

        $user = $license->user;
        $locale = $user->preferred_language ?? 'en';

        $templateModel = [
            'user_name' => $user->name,
            'license_name' => $license->license->name,
            'expiry_date' => app(LocaleService::class)->formatDate(Carbon::parse($license->ends_at), $user),
            'days_remaining' => $days,
            'credits_current' => $user->credits ?? 0,
            'is_subscription' => $license->license->tier === 'premium',
            'is_onetime' => $license->license->tier === 'onetime',
            'buy_credits_url' => route('pricing'),
        ];

        if (! $dryRun) {
            SendPostmarkTemplateEmail::dispatch(
                templateAlias: "license-expiry-warning__{$locale}",
                templateModel: $templateModel,
                to: $user->email,
                toName: $user->name,
                tag: 'license-expiry',
                messageStream: 'outbound'
            );

            LicenseNotification::recordSent($license->id, null, $notificationType);
        }

        $stats[$days === 7 ? 'expiry_7_days' : 'expiry_1_day']++;

        Log::info('Expiry notification sent', [
            'user_id' => $user->id,
            'license_id' => $license->id,
            'days' => $days,
            'dry_run' => $dryRun,
        ]);
    }

    /**
     * Process renewal notifications for active premium licenses
     */
    private function processRenewalNotifications(array &$stats, bool $dryRun): void
    {
        $this->info('Processing renewal notifications...');

        $licenses = UserLicense::with(['license', 'user'])
            ->where('is_current', true)
            ->whereHas('license', fn ($q) => $q->where('tier', 'premium'))
            ->where('status', UserLicense::STATUS_ACTIVE)
            ->get();

        foreach ($licenses as $license) {
            try {
                $resetInterval = $license->license->credit_reset_interval ?? 'monthly';
                $nextRenewal = $this->renewalService->getNextRenewalDate($license->starts_at, $resetInterval);

                if (! $nextRenewal) {
                    continue;
                }

                $daysUntilRenewal = now()->diffInDays($nextRenewal, false);

                // Check for 7-day notification only
                if ($daysUntilRenewal <= 7 && $daysUntilRenewal > 1) {
                    $this->sendRenewalNotification($license, $nextRenewal, $daysUntilRenewal, $stats, $dryRun);
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('Error processing renewal notification', [
                    'license_id' => $license->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send renewal notification
     */
    private function sendRenewalNotification(UserLicense $license, Carbon $renewalDate, int $days, array &$stats, bool $dryRun): void
    {
        $notificationType = LicenseNotification::TYPE_RENEWAL_7_DAYS;

        // Check if already sent
        if (LicenseNotification::wasRecentlySent($license->id, null, $notificationType, 7)) {
            $stats['skipped_already_sent']++;

            return;
        }

        $user = $license->user;
        $locale = $user->preferred_language ?? 'en';

        $templateModel = [
            'user_name' => $user->name,
            'license_name' => $license->license->name,
            'renewal_date' => app(LocaleService::class)->formatDate($renewalDate, $user),
            'days_remaining' => $days,
            'renewal_credits' => $license->license->credits,
            'credits_current' => $user->credits ?? 0,
            'has_current_credits' => ($user->credits ?? 0) > 0,
            'manage_url' => route('profile.plans'),
        ];

        if (! $dryRun) {
            SendPostmarkTemplateEmail::dispatch(
                templateAlias: "license-renewal-reminder__{$locale}",
                templateModel: $templateModel,
                to: $user->email,
                toName: $user->name,
                tag: 'license-renewal',
                messageStream: 'outbound'
            );

            LicenseNotification::recordSent($license->id, null, $notificationType);
        }

        $stats['renewal_7_days']++;

        Log::info('Renewal notification sent', [
            'user_id' => $user->id,
            'license_id' => $license->id,
            'days' => $days,
            'dry_run' => $dryRun,
        ]);
    }

    /**
     * Process low credit notifications for active premium licenses
     */
    private function processLowCreditNotifications(array &$stats, bool $dryRun): void
    {
        $this->info('Processing low credit notifications...');

        $licenses = UserLicense::with(['license', 'user'])
            ->where('is_current', true)
            ->whereHas('license', fn ($q) => $q->where('tier', 'premium'))
            ->where('status', UserLicense::STATUS_ACTIVE)
            ->get();

        foreach ($licenses as $license) {
            try {
                if ($this->shouldSendLowCreditNotification($license)) {
                    $this->sendLowCreditNotification($license, $stats, $dryRun);
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('Error processing low credit notification', [
                    'license_id' => $license->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Check if low credit notification should be sent
     */
    private function shouldSendLowCreditNotification(UserLicense $license): bool
    {
        $user = $license->user;

        // Check: credits < 10?
        if (($user->credits ?? 0) >= 10) {
            return false;
        }

        // Check: renewal > 1 day away?
        $resetInterval = $license->license->credit_reset_interval ?? 'monthly';
        $nextRenewal = $this->renewalService->getNextRenewalDate($license->starts_at, $resetInterval);

        $daysUntilRenewal = $nextRenewal ? now()->diffInDays($nextRenewal, false) : 0;
        if (! $nextRenewal || $daysUntilRenewal <= 1) {
            return false;
        }

        // Check if already sent recently (within 30 days)
        if (LicenseNotification::wasRecentlySent($license->id, null, LicenseNotification::TYPE_LOW_CREDITS, 30)) {
            return false;
        }

        // Check: did credits drop below 10 today?
        $droppedToday = CreditLedger::where('user_id', $user->id)
            ->where('delta', '<', 0)
            ->whereDate('created_at', today())
            ->where('balance_after', '<', 10)
            ->exists();

        return $droppedToday;
    }

    /**
     * Send low credit notification
     */
    private function sendLowCreditNotification(UserLicense $license, array &$stats, bool $dryRun): void
    {
        $user = $license->user;
        $locale = $user->preferred_language ?? 'en';

        $resetInterval = $license->license->credit_reset_interval ?? 'monthly';
        $nextRenewal = $this->renewalService->getNextRenewalDate($license->starts_at, $resetInterval);

        // Guard: if renewal date can't be calculated, skip this notification
        if (! $nextRenewal) {
            $stats['skipped_already_sent']++;

            return;
        }

        $templateModel = [
            'user_name' => $user->name,
            'credits_current' => $user->credits ?? 0,
            'renewal_date' => app(LocaleService::class)->formatDate($nextRenewal, $user),
            'days_until_renewal' => (int) $nextRenewal->diffInDays(now()),
            'buy_credits_url' => route('pricing'),
        ];

        if (! $dryRun) {
            SendPostmarkTemplateEmail::dispatch(
                templateAlias: "license-low-credits__{$locale}",
                templateModel: $templateModel,
                to: $user->email,
                toName: $user->name,
                tag: 'license-low-credits',
                messageStream: 'outbound'
            );

            LicenseNotification::recordSent($license->id, null, LicenseNotification::TYPE_LOW_CREDITS);
        }

        $stats['low_credits']++;

        Log::info('Low credit notification sent', [
            'user_id' => $user->id,
            'license_id' => $license->id,
            'credits' => $user->credits,
            'dry_run' => $dryRun,
        ]);
    }

    /**
     * Process low credit notifications for organization credit pools (premium licenses)
     */
    private function processOrganizationLowCreditNotifications(array &$stats, bool $dryRun): void
    {
        $this->info('Processing organization low credit notifications...');

        $licenses = OrganizationLicense::with(['license', 'organization.creditPool', 'organization.admins'])
            ->where('is_current', true)
            ->whereHas('license', fn ($q) => $q->where('tier', 'premium'))
            ->where('status', 'active')
            ->get();

        foreach ($licenses as $license) {
            try {
                if ($this->shouldSendOrgLowCreditNotification($license)) {
                    $this->sendOrgLowCreditNotification($license, $stats, $dryRun);
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('Error processing organization low credit notification', [
                    'license_id' => $license->id,
                    'organization_id' => $license->organization_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Check if organization low credit notification should be sent
     */
    private function shouldSendOrgLowCreditNotification(OrganizationLicense $license): bool
    {
        $organization = $license->organization;
        $creditPool = $organization->creditPool;

        // Check: credit pool exists and balance < 10?
        if (! $creditPool || $creditPool->balance_credits >= 10) {
            return false;
        }

        // Check: renewal > 1 day away?
        $resetInterval = $license->license->credit_reset_interval ?? 'monthly';
        $nextRenewal = $this->renewalService->getNextRenewalDate($license->starts_at, $resetInterval);

        $daysUntilRenewal = $nextRenewal ? now()->diffInDays($nextRenewal, false) : 0;
        if (! $nextRenewal || $daysUntilRenewal <= 1) {
            return false;
        }

        // Check if already sent recently (within 30 days)
        if (LicenseNotification::wasRecentlySent(null, $license->id, LicenseNotification::TYPE_LOW_CREDITS, 30)) {
            return false;
        }

        // Check: did credits drop below 10 today?
        $droppedToday = OrganizationCreditLedger::where('organization_id', $organization->id)
            ->where('delta', '<', 0)
            ->whereDate('created_at', today())
            ->where('balance_after', '<', 10)
            ->exists();

        return $droppedToday;
    }

    /**
     * Send organization low credit notification to all admins
     */
    private function sendOrgLowCreditNotification(OrganizationLicense $license, array &$stats, bool $dryRun): void
    {
        $organization = $license->organization;
        $admins = $organization->admins;

        if ($admins->isEmpty()) {
            Log::warning('No admins found for organization low credit notification', [
                'organization_id' => $organization->id,
            ]);

            return;
        }

        $resetInterval = $license->license->credit_reset_interval ?? 'monthly';
        $nextRenewal = $this->renewalService->getNextRenewalDate($license->starts_at, $resetInterval);

        // Guard: if renewal date can't be calculated, skip this notification
        if (! $nextRenewal) {
            return;
        }

        foreach ($admins as $admin) {
            $locale = $admin->preferred_language ?? 'en';

            $templateModel = [
                'user_name' => $admin->name,
                'organization_name' => $organization->name,
                'credits_current' => $organization->creditPool->balance_credits ?? 0,
                'renewal_date' => app(LocaleService::class)->formatDate($nextRenewal, $admin),
                'days_until_renewal' => (int) $nextRenewal->diffInDays(now()),
                'buy_credits_url' => route('pricing'),
            ];

            if (! $dryRun) {
                SendPostmarkTemplateEmail::dispatch(
                    templateAlias: "organization-low-credits__{$locale}",
                    templateModel: $templateModel,
                    to: $admin->email,
                    toName: $admin->name,
                    tag: 'organization-low-credits',
                    messageStream: 'outbound'
                );
            }

            Log::info('Organization low credit notification sent', [
                'organization_id' => $organization->id,
                'admin_user_id' => $admin->id,
                'credits' => $organization->creditPool->balance_credits ?? 0,
                'dry_run' => $dryRun,
            ]);
        }

        // Record notification once per organization license (not per admin)
        if (! $dryRun) {
            LicenseNotification::recordSent(null, $license->id, LicenseNotification::TYPE_LOW_CREDITS);
        }

        $stats['low_credits_org']++;
    }
}
