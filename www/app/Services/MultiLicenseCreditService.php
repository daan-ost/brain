<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\CreditLedger;
use App\Models\OrganizationCreditLedger;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MultiLicenseCreditService
{
    private LicensePriorityService $licensePriorityService;

    private CreditsService $creditsService;

    public function __construct(
        LicensePriorityService $licensePriorityService,
        CreditsService $creditsService
    ) {
        $this->licensePriorityService = $licensePriorityService;
        $this->creditsService = $creditsService;
    }

    /**
     * Get all available credit sources for a user in priority order
     */
    public function getAvailableCreditSources(User $user): array
    {
        $creditSources = [];
        $processedOrganizations = [];
        $activeLicenses = $this->licensePriorityService->getAllActiveLicenses($user);
        $userCreditsAdded = false;

        foreach ($activeLicenses as $license) {
            if ($license->license_type === 'organizational') {
                $organization = $license->source_organization;

                // Only add each organization once (avoid duplicating credit pools)
                if (! in_array($organization->id, $processedOrganizations)) {
                    $creditPool = $organization->creditPool;
                    $balance = $creditPool ? $creditPool->balance_credits : 0;

                    $creditSources[] = [
                        'type' => 'organization',
                        'id' => $organization->id,
                        'name' => $organization->name,
                        'balance' => $balance,
                        'license' => $license, // Use the highest priority license from this org
                        'organization' => $organization,
                        'priority_score' => $license->priority_score,
                    ];

                    $processedOrganizations[] = $organization->id;
                }
            } else {
                // Only add user credits once
                if (! $userCreditsAdded) {
                    $balance = $user->credits ?? 0;

                    $creditSources[] = [
                        'type' => 'user',
                        'id' => $user->id,
                        'name' => 'Personal',
                        'balance' => $balance,
                        'license' => $license,
                        'user' => $user,
                        'priority_score' => $license->priority_score,
                    ];

                    $userCreditsAdded = true;
                }
            }
        }

        // Filter out sources with zero balance
        return array_filter($creditSources, fn ($source) => $source['balance'] > 0);
    }

    /**
     * Calculate total available credits across all sources
     */
    public function getTotalAvailableCredits(User $user): int
    {
        $creditSources = $this->getAvailableCreditSources($user);

        return array_sum(array_column($creditSources, 'balance'));
    }

    /**
     * Check if user has sufficient credits across all sources
     */
    public function hasSufficientCreditsMultiSource(User $user, int $creditsNeeded): bool
    {
        return $this->getTotalAvailableCredits($user) >= $creditsNeeded;
    }

    /**
     * Plan credit consumption across multiple sources
     */
    public function planCreditConsumption(User $user, int $creditsNeeded): array
    {
        $creditSources = $this->getAvailableCreditSources($user);
        $consumptionPlan = [];
        $remainingCreditsNeeded = $creditsNeeded;

        foreach ($creditSources as $source) {
            if ($remainingCreditsNeeded <= 0) {
                break;
            }

            $creditsToConsume = min($source['balance'], $remainingCreditsNeeded);

            if ($creditsToConsume > 0) {
                $consumptionPlan[] = [
                    'source' => $source,
                    'credits_to_consume' => $creditsToConsume,
                    'remaining_after' => $source['balance'] - $creditsToConsume,
                ];

                $remainingCreditsNeeded -= $creditsToConsume;
            }
        }

        return [
            'plan' => $consumptionPlan,
            'total_credits_needed' => $creditsNeeded,
            'total_credits_available' => $this->getTotalAvailableCredits($user),
            'can_fulfill' => $remainingCreditsNeeded <= 0,
            'shortfall' => max(0, $remainingCreditsNeeded),
        ];
    }

    /**
     * Enhanced validation that considers multiple license sources
     */
    public function validateAndReserveCreditsMultiSource(User $user, Workflow $workflow, int $documentsCount): array
    {
        $creditsNeeded = $this->creditsService->calculateCreditsNeeded($workflow, $documentsCount);

        // If no credits needed, validation passes
        if ($creditsNeeded <= 0) {
            return [
                'valid' => true,
                'credits_needed' => 0,
                'consumption_plan' => [],
                'message' => 'This workflow is free to run.',
            ];
        }

        $consumptionPlan = $this->planCreditConsumption($user, $creditsNeeded);

        if (! $consumptionPlan['can_fulfill']) {
            return [
                'valid' => false,
                'credits_needed' => $creditsNeeded,
                'consumption_plan' => $consumptionPlan,
                'message' => "Not enough credits available. Need {$creditsNeeded}, have {$consumptionPlan['total_credits_available']}, missing {$consumptionPlan['shortfall']}.",
            ];
        }

        // Generate consumption message
        $sourceMessages = [];
        foreach ($consumptionPlan['plan'] as $step) {
            $sourceMessages[] = "{$step['credits_to_consume']} from {$step['source']['name']}";
        }
        $sourceMessage = implode(', ', $sourceMessages);

        $operationType = $this->creditsService->workflowContainsMergeOperations($workflow)
            ? "merge operation: fixed cost for {$documentsCount} files"
            : "{$documentsCount} × {$workflow->credits_per_document}";

        $message = "This run will consume {$creditsNeeded} credits ({$operationType}). Sources: {$sourceMessage}.";

        return [
            'valid' => true,
            'credits_needed' => $creditsNeeded,
            'consumption_plan' => $consumptionPlan,
            'message' => $message,
        ];
    }

    /**
     * Charge credits across multiple sources according to plan
     */
    public function chargeCreditsMultiSource(
        Batch $batch,
        Workflow $workflow,
        array $consumptionPlan,
        int $creditsNeeded,
        int $documentsCount
    ): void {
        if ($creditsNeeded <= 0) {
            Log::info('MultiLicenseCreditService: No credits to charge', [
                'batch_id' => $batch->id,
                'credits_needed' => $creditsNeeded,
            ]);

            return;
        }

        DB::transaction(function () use ($batch, $workflow, $consumptionPlan, $documentsCount) {
            $now = now();
            $totalCharged = 0;
            $primarySource = null; // For batch record

            foreach ($consumptionPlan['plan'] as $step) {
                $source = $step['source'];
                $creditsToCharge = $step['credits_to_consume'];

                if ($creditsToCharge <= 0) {
                    continue;
                }

                // Track primary source (first/highest priority)
                if ($primarySource === null) {
                    $primarySource = $source;
                }

                if ($source['type'] === 'organization') {
                    $this->chargeOrganizationCredits($batch, $workflow, $source, $creditsToCharge, $now);
                } else {
                    $this->chargeUserCredits($batch, $workflow, $source, $creditsToCharge, $now);
                }

                $totalCharged += $creditsToCharge;

                Log::info('MultiLicenseCreditService: Charged credits from source', [
                    'batch_id' => $batch->id,
                    'source_type' => $source['type'],
                    'source_name' => $source['name'],
                    'credits_charged' => $creditsToCharge,
                    'total_charged_so_far' => $totalCharged,
                ]);
            }

            // Update batch record with primary source info
            $batch->update([
                'documents_count' => $documentsCount,
                'credits_spent' => $totalCharged,
                'charged_at' => $now,
                'organization_id' => $primarySource && $primarySource['type'] === 'organization'
                    ? $primarySource['id']
                    : null,
            ]);

            Log::info('MultiLicenseCreditService: Successfully charged credits across multiple sources', [
                'batch_id' => $batch->id,
                'workflow_id' => $workflow->id,
                'total_credits_charged' => $totalCharged,
                'sources_used' => count($consumptionPlan['plan']),
                'documents_count' => $documentsCount,
            ]);
        });
    }

    /**
     * Get credit summary for display in UI
     */
    public function getCreditSummaryForUser(User $user): array
    {
        $activeLicenses = $this->licensePriorityService->getAllActiveLicenses($user);
        $creditSources = $this->getAvailableCreditSources($user);

        $summary = [
            'total_credits' => $this->getTotalAvailableCredits($user),
            'active_licenses_count' => $activeLicenses->count(),
            'credit_sources' => [],
            'primary_source' => null,
        ];

        foreach ($creditSources as $index => $source) {
            $sourceInfo = [
                'type' => $source['type'],
                'name' => $source['name'],
                'balance' => $source['balance'],
                'is_primary' => $index === 0,
                'priority_score' => $source['priority_score'],
            ];

            if ($source['type'] === 'organizational') {
                $sourceInfo['organization_id'] = $source['organization']->id;
            }

            $summary['credit_sources'][] = $sourceInfo;

            if ($index === 0) {
                $summary['primary_source'] = $sourceInfo;
            }
        }

        return $summary;
    }

    /**
     * Charge organization credits (part of multi-source charging)
     */
    private function chargeOrganizationCredits(Batch $batch, Workflow $workflow, array $source, int $creditsToCharge, $timestamp): void
    {
        $organization = $source['organization'];
        $currentBalance = $source['balance'];
        $newBalance = $currentBalance - $creditsToCharge;

        // Update organization credit pool
        $organization->creditPool()->updateOrCreate(
            ['organization_id' => $organization->id],
            [
                'balance_credits' => $newBalance,
                'updated_at' => $timestamp,
            ]
        );

        // Create ledger entry
        OrganizationCreditLedger::create([
            'organization_id' => $organization->id,
            'user_id' => $batch->user_id,
            'batch_id' => $batch->id,
            'workflow_id' => $workflow->id,
            'delta' => -$creditsToCharge,
            'reason' => 'spend_multi_source',
            'balance_after' => $newBalance,
            'meta' => [
                'documents_count' => $batch->documents_count ?? 0,
                'workflow_name' => $workflow->name,
                'multi_source_charge' => true,
                'total_credits_needed' => $batch->credits_spent ?? 0,
            ],
            'created_at' => $timestamp,
        ]);
    }

    /**
     * Charge user credits (part of multi-source charging)
     */
    private function chargeUserCredits(Batch $batch, Workflow $workflow, array $source, int $creditsToCharge, $timestamp): void
    {
        $user = $source['user'];
        $currentBalance = $source['balance'];
        $newBalance = $currentBalance - $creditsToCharge;

        // Update user credits
        $user->update([
            'credits' => $newBalance,
            'credits_updated_at' => $timestamp,
        ]);

        // Create ledger entry
        CreditLedger::create([
            'user_id' => $user->id,
            'batch_id' => $batch->id,
            'workflow_id' => $workflow->id,
            'delta' => -$creditsToCharge,
            'reason' => 'spend_multi_source',
            'balance_after' => $newBalance,
            'meta' => [
                'documents_count' => $batch->documents_count ?? 0,
                'workflow_name' => $workflow->name,
                'multi_source_charge' => true,
                'total_credits_needed' => $batch->credits_spent ?? 0,
            ],
            'created_at' => $timestamp,
        ]);
    }
}
