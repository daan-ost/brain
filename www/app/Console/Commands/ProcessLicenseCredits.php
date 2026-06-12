<?php

namespace App\Console\Commands;

use App\Models\OrganizationLicense;
use App\Models\UserLicense;
use App\Services\LicenseCreditResetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessLicenseCredits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'license:process-credits {--dry-run : Run without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process license credit resets and expirations';

    private LicenseCreditResetService $resetService;

    public function __construct(LicenseCreditResetService $resetService)
    {
        parent::__construct();
        $this->resetService = $resetService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('Running in dry-run mode - no changes will be made');
        }

        $this->info('Starting license credit processing...');

        $stats = [
            'free_resets' => 0,
            'onetime_expired' => 0,
            'premium_resets' => 0,
            'premium_canceled_expired' => 0,
            'org_onetime_expired' => 0,
            'org_premium_resets' => 0,
            'org_premium_canceled_expired' => 0,
            'errors' => 0,
        ];

        // Process User Licenses
        $this->processUserLicenses($stats, $dryRun);

        // Process Organization Licenses
        $this->processOrganizationLicenses($stats, $dryRun);

        // Output summary
        $this->newLine();
        $this->info('=== Processing Complete ===');
        $this->table(
            ['Action', 'Count'],
            [
                ['Free tier resets', $stats['free_resets']],
                ['Onetime expired', $stats['onetime_expired']],
                ['Premium resets', $stats['premium_resets']],
                ['Premium canceled expired', $stats['premium_canceled_expired']],
                ['Org onetime expired', $stats['org_onetime_expired']],
                ['Org premium resets', $stats['org_premium_resets']],
                ['Org premium canceled expired', $stats['org_premium_canceled_expired']],
                ['Errors', $stats['errors']],
            ]
        );

        Log::info('License credit processing completed', $stats);

        return Command::SUCCESS;
    }

    private function processUserLicenses(array &$stats, bool $dryRun): void
    {
        $this->info('Processing user licenses...');

        // Get all active/canceled user licenses with their license details
        $licenses = UserLicense::with(['license', 'user'])
            ->whereIn('status', [UserLicense::STATUS_ACTIVE, UserLicense::STATUS_CANCELED])
            ->whereHas('license', fn ($q) => $q->where('active', true))
            ->get();

        $this->output->progressStart($licenses->count());

        foreach ($licenses as $license) {
            try {
                $processed = $this->processUserLicense($license, $dryRun, $stats);
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('Error processing user license', [
                    'license_id' => $license->id,
                    'user_id' => $license->user_id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Error processing license {$license->id}: {$e->getMessage()}");
            }

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();
    }

    private function processUserLicense(UserLicense $license, bool $dryRun, array &$stats): bool
    {
        $tier = $license->license->tier;

        switch ($tier) {
            case 'free':
                if ($this->resetService->shouldResetFreeCredits($license)) {
                    if (! $dryRun) {
                        $this->resetService->processFreeTierReset($license);
                    }
                    $stats['free_resets']++;

                    return true;
                }
                break;

            case 'onetime':
                if ($license->ends_at && $license->ends_at->lte(now()) && $license->status !== UserLicense::STATUS_EXPIRED) {
                    if (! $dryRun) {
                        $this->resetService->processOnetimeExpiry($license);
                    }
                    $stats['onetime_expired']++;

                    return true;
                }
                break;

            case 'premium':
                if ($license->status === UserLicense::STATUS_ACTIVE) {
                    if ($this->resetService->shouldResetPremiumCredits($license)) {
                        if (! $dryRun) {
                            $this->resetService->processPremiumReset($license);
                        }
                        $stats['premium_resets']++;

                        return true;
                    }
                } elseif ($license->status === UserLicense::STATUS_CANCELED) {
                    if ($license->ends_at && $license->ends_at->lte(now())) {
                        if (! $dryRun) {
                            $this->resetService->processPremiumCanceledExpiry($license);
                        }
                        $stats['premium_canceled_expired']++;

                        return true;
                    }
                }
                break;
        }

        return false;
    }

    private function processOrganizationLicenses(array &$stats, bool $dryRun): void
    {
        $this->info('Processing organization licenses...');

        $licenses = OrganizationLicense::with(['license', 'organization.creditPool'])
            ->whereIn('status', ['active', 'canceled'])
            ->whereHas('license', fn ($q) => $q->where('active', true))
            ->get();

        $this->output->progressStart($licenses->count());

        foreach ($licenses as $license) {
            try {
                $this->processOrganizationLicense($license, $dryRun, $stats);
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('Error processing organization license', [
                    'license_id' => $license->id,
                    'organization_id' => $license->organization_id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Error processing org license {$license->id}: {$e->getMessage()}");
            }

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();
    }

    private function processOrganizationLicense(OrganizationLicense $license, bool $dryRun, array &$stats): bool
    {
        $tier = $license->license->tier;

        switch ($tier) {
            case 'onetime':
                if ($license->ends_at && $license->ends_at->lte(now()) && $license->status !== 'expired') {
                    if (! $dryRun) {
                        $this->resetService->processOrganizationOnetimeExpiry($license);
                    }
                    $stats['org_onetime_expired']++;

                    return true;
                }
                break;

            case 'premium':
                if ($license->status === 'active') {
                    // Check if reset is needed (similar logic to user)
                    $resetInterval = $license->license->credit_reset_interval ?? 'monthly';
                    $lastReset = $license->last_credit_reset_at ?? $license->starts_at;
                    $renewalService = app(\App\Services\LicenseRenewalService::class);
                    $previousRenewal = $renewalService->getPreviousRenewalDate($license->starts_at, $resetInterval);

                    if ($lastReset->lt($previousRenewal)) {
                        if (! $dryRun) {
                            $this->resetService->processOrganizationPremiumReset($license);
                        }
                        $stats['org_premium_resets']++;

                        return true;
                    }
                } elseif ($license->status === 'canceled') {
                    // Check if canceled license has expired
                    if ($license->ends_at && $license->ends_at->lte(now())) {
                        if (! $dryRun) {
                            $this->resetService->processOrganizationPremiumCanceledExpiry($license);
                        }
                        $stats['org_premium_canceled_expired']++;

                        return true;
                    }
                }
                break;
        }

        return false;
    }
}
