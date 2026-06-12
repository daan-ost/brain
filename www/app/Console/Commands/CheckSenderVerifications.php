<?php

namespace App\Console\Commands;

use App\Enums\SenderConfigStatus;
use App\Enums\SenderLevel;
use App\Models\OrganizationSenderConfig;
use App\Services\SenderConfigService;
use Illuminate\Console\Command;

class CheckSenderVerifications extends Command
{
    protected $signature = 'sender:check-verifications';

    protected $description = 'Check verification status of pending sender signatures and domain authentications';

    public function handle(SenderConfigService $service): int
    {
        $pending = OrganizationSenderConfig::where('status', SenderConfigStatus::PendingVerification)
            ->whereIn('sender_level', [SenderLevel::SenderSignature, SenderLevel::DomainAuth])
            ->with('organization')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No pending verifications to check.');

            return self::SUCCESS;
        }

        $this->info("Checking {$pending->count()} pending verifications...");

        $verified = 0;
        $failed = 0;

        foreach ($pending as $config) {
            try {
                if ($config->sender_level === SenderLevel::SenderSignature) {
                    $updated = $service->checkSignatureStatus($config);
                } else {
                    $updated = $service->verifyDomainDns($config);
                }

                if ($updated->status === SenderConfigStatus::Verified) {
                    $verified++;
                    $this->line("  ✓ {$config->organization->name}: verified");
                } elseif ($updated->status === SenderConfigStatus::Failed) {
                    $failed++;
                    $this->error("  ✗ {$config->organization->name}: {$updated->failure_reason}");
                } else {
                    $this->line("  ○ {$config->organization->name}: still pending");
                }
            } catch (\Exception $e) {
                $failed++;
                $this->error("  ✗ {$config->organization->name}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Done. Verified: {$verified}, Failed: {$failed}");

        return self::SUCCESS;
    }
}
