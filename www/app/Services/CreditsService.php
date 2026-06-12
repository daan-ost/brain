<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\CreditLedger;
use App\Models\Organization;
use App\Models\OrganizationCreditLedger;
use App\Models\User;
use App\Models\UserLicense;
use App\Models\Workflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreditsService
{
    /**
     * Calculate credits needed for a workflow execution
     */
    public function calculateCreditsNeeded(Workflow $workflow, int $documentsCount): int
    {
        $steps = $workflow->steps_json ?? [];

        // Check if workflow contains only non-charging actions
        if ($this->isWorkflowNonCharging($workflow)) {
            Log::info('CreditsService: Workflow contains only non-charging actions', [
                'workflow_id' => $workflow->id,
                'workflow_name' => $workflow->name,
                'steps' => $steps,
            ]);

            return 0;
        }

        // Detect if a folding rule applies
        $foldingRule = $this->detectFoldingRule($steps);

        if ($foldingRule) {
            // Use folding rule's credit configuration
            $creditsNeeded = $this->calculateFoldedCredits($foldingRule, $documentsCount);

            Log::info('CreditsService: Calculated credits using folding rule', [
                'workflow_id' => $workflow->id,
                'workflow_name' => $workflow->name,
                'folding_rule' => $foldingRule['pattern'],
                'charging_model' => $foldingRule['charging_model'] ?? 'unknown',
                'documents_count' => $documentsCount,
                'credits_needed' => $creditsNeeded,
            ]);

            return $creditsNeeded;
        }

        // No folding rule found: calculate per-step credits
        $creditsNeeded = $this->calculateUnfoldedCredits($steps, $documentsCount);

        Log::info('CreditsService: Calculated credits per-step (no folding)', [
            'workflow_id' => $workflow->id,
            'workflow_name' => $workflow->name,
            'steps_count' => count($steps),
            'documents_count' => $documentsCount,
            'credits_needed' => $creditsNeeded,
        ]);

        return $creditsNeeded;
    }

    /**
     * Check if workflow contains only non-charging actions
     */
    public function isWorkflowNonCharging(Workflow $workflow): bool
    {
        return $this->areStepsNonCharging($workflow->steps_json ?? []);
    }

    /**
     * Generic method to check if workflow steps contain only non-charging actions
     * Can be used by any part of the application for consistent credit logic
     */
    public function areStepsNonCharging(array $steps): bool
    {
        $nonChargingActions = config('credits.non_charging_actions', []);

        // If no steps, don't charge
        if (empty($steps)) {
            return true;
        }

        // Check if all steps are in non-charging list
        foreach ($steps as $step) {
            $stepType = $step['type'] ?? null;
            if (! in_array($stepType, $nonChargingActions)) {
                return false; // Found a charging action
            }
        }

        return true; // All steps are non-charging
    }

    /**
     * Generic method to count charging steps in a workflow
     * Used for credit calculation across the application
     */
    public function countChargingSteps(array $steps): int
    {
        $nonChargingActions = config('credits.non_charging_actions', []);
        $chargingStepsCount = 0;

        foreach ($steps as $step) {
            $stepType = $step['type'] ?? '';
            if (! in_array($stepType, $nonChargingActions)) {
                $chargingStepsCount++;
            }
        }

        return $chargingStepsCount;
    }

    /**
     * Check if workflow contains merge operations (single API call regardless of input count)
     */
    public function workflowContainsMergeOperations(Workflow $workflow): bool
    {
        return $this->stepsContainMergeOperations($workflow->steps_json ?? []);
    }

    /**
     * Generic method to check if steps contain merge operations
     */
    public function stepsContainMergeOperations(array $steps): bool
    {
        $mergeOperations = config('credits.merge_operations', [
            'merge_pdfs',
            'images_to_pdf',
        ]);

        foreach ($steps as $step) {
            $stepType = $step['type'] ?? '';
            if (in_array($stepType, $mergeOperations)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get payment source (organization or user) for a user
     */
    public function getPaymentSource(User $user): array
    {
        // Get organizations with positive balance, sorted by rules
        $organizationsWithBalance = $this->getUserOrganizationsWithPositiveBalance($user);

        if ($organizationsWithBalance->isNotEmpty()) {
            $selectedOrg = $organizationsWithBalance->first();

            Log::info('CreditsService: Selected organization as payment source', [
                'user_id' => $user->id,
                'organization_id' => $selectedOrg->id,
                'organization_name' => $selectedOrg->name,
                'balance' => $selectedOrg->creditPool->balance_credits ?? 0,
            ]);

            return [
                'type' => 'organization',
                'id' => $selectedOrg->id,
                'name' => $selectedOrg->name,
                'balance' => $selectedOrg->creditPool->balance_credits ?? 0,
                'organization' => $selectedOrg,
            ];
        }

        // Fall back to user credits
        // If user has 0 credits and an active one-time license, try to activate free tier
        if (($user->credits ?? 0) <= 0) {
            $this->activateFreeTierIfEligible($user);
            $user->refresh();
        }

        Log::info('CreditsService: Selected user as payment source', [
            'user_id' => $user->id,
            'user_balance' => $user->credits ?? 0,
        ]);

        return [
            'type' => 'user',
            'id' => $user->id,
            'name' => __('ui.credits_source_personal'),
            'balance' => $user->credits ?? 0,
            'user' => $user,
        ];
    }

    /**
     * Check if payment source has sufficient credits
     */
    public function hasSufficientCredits(array $paymentSource, int $creditsNeeded): bool
    {
        return $paymentSource['balance'] >= $creditsNeeded;
    }

    /**
     * Charge credits and create ledger entries
     */
    public function chargeCredits(Batch $batch, Workflow $workflow, array $paymentSource, int $creditsNeeded, int $documentsCount): void
    {
        if ($creditsNeeded <= 0) {
            Log::info('CreditsService: No credits to charge', [
                'batch_id' => $batch->id,
                'credits_needed' => $creditsNeeded,
            ]);

            return;
        }

        DB::transaction(function () use ($batch, $workflow, $paymentSource, $creditsNeeded, $documentsCount) {
            $now = now();

            if ($paymentSource['type'] === 'organization') {
                $this->chargeOrganization($batch, $workflow, $paymentSource, $creditsNeeded, $now);
            } else {
                $this->chargeUser($batch, $workflow, $paymentSource, $creditsNeeded, $now);
            }

            // Update batch record
            $batch->update([
                'documents_count' => $documentsCount,
                'credits_spent' => $creditsNeeded,
                'charged_at' => $now,
                'organization_id' => $paymentSource['type'] === 'organization' ? $paymentSource['id'] : null,
            ]);

            Log::info('CreditsService: Successfully charged credits', [
                'batch_id' => $batch->id,
                'workflow_id' => $workflow->id,
                'payment_source_type' => $paymentSource['type'],
                'payment_source_id' => $paymentSource['id'],
                'credits_charged' => $creditsNeeded,
                'documents_count' => $documentsCount,
            ]);
        });
    }

    /**
     * Validate credits before workflow execution
     */
    public function validateAndReserveCredits(User $user, Workflow $workflow, int $documentsCount): array
    {
        $creditsNeeded = $this->calculateCreditsNeeded($workflow, $documentsCount);

        // If no credits needed, validation passes
        if ($creditsNeeded <= 0) {
            return [
                'valid' => true,
                'credits_needed' => 0,
                'payment_source' => null,
                'message' => __('ui.credits_conversion_free'),
            ];
        }

        $paymentSource = $this->getPaymentSource($user);

        // Skip credit validation error for unverified users - they'll be blocked by email verification check
        // This prevents showing "not enough credits" message when they haven't received their signup bonus yet
        if (! $this->hasSufficientCredits($paymentSource, $creditsNeeded)) {
            // If user email is not verified, allow them to proceed to step 3 where email verification will be checked
            if (! $user->hasVerifiedEmail()) {
                $translationKey = ($creditsNeeded === 1 && $documentsCount === 1)
                    ? 'ui.credits_conversion_standard_singular'
                    : 'ui.credits_conversion_standard';

                return [
                    'valid' => true,
                    'credits_needed' => $creditsNeeded,
                    'payment_source' => $paymentSource,
                    'message' => __($translationKey, [
                        'credits' => $creditsNeeded,
                        'count' => $documentsCount,
                    ]),
                ];
            }

            // Check if user is in the 24-hour waiting period for free tier
            $waitingPeriod = $this->getFreeTierWaitingPeriod($user);

            if ($waitingPeriod) {
                // User is in waiting period - show special message with time remaining
                $hoursRemaining = ceil($waitingPeriod['hours_remaining']);
                $availableAt = $waitingPeriod['available_at'];

                // Format time based on locale
                $locale = app()->getLocale();
                $timeFormat = $locale === 'nl' ? 'H:i' : 'g:i A';
                $dateFormat = $locale === 'nl' ? 'j F' : 'F j';
                $formattedTime = $availableAt->format($timeFormat);
                $formattedDate = $availableAt->translatedFormat($dateFormat);

                $message = __('ui.credits_exhausted_waiting_period', [
                    'hours' => $hoursRemaining,
                    'time' => $formattedTime,
                    'date' => $formattedDate,
                ]);

                return [
                    'valid' => false,
                    'credits_needed' => $creditsNeeded,
                    'payment_source' => $paymentSource,
                    'message' => $message,
                    'waiting_period' => $waitingPeriod,
                ];
            }

            $message = $paymentSource['type'] === 'organization'
                ? __('ui.credits_insufficient_organization', ['name' => $paymentSource['name']])
                : __('ui.credits_insufficient_personal');

            return [
                'valid' => false,
                'credits_needed' => $creditsNeeded,
                'payment_source' => $paymentSource,
                'message' => $message,
            ];
        }

        // Generate simplified message based on operation type (2025-10-31)
        $steps = $workflow->steps_json ?? [];
        $chargingStepsCount = $this->countChargingSteps($steps);
        $foldingRule = $this->detectFoldingRule($steps);

        // Scenario 1: Only merge operation (merge-only workflow)
        if ($foldingRule && ($foldingRule['charging_model'] ?? 'per_file') === 'fixed' && $this->workflowContainsMergeOperations($workflow)) {
            $message = __('ui.credits_conversion_merge_only', [
                'credits' => $creditsNeeded,
                'count' => $documentsCount,
            ]);
        }
        // Scenario 2: Conversion + Merge (complex workflow with both convert and merge)
        elseif ($this->workflowContainsMergeOperations($workflow) && $chargingStepsCount > 1) {
            // Count how many files go through conversion (before merge)
            $fileCount = $documentsCount;

            $message = __('ui.credits_conversion_with_merge', [
                'credits' => $creditsNeeded,
                'file_count' => $fileCount,
            ]);
        }
        // Scenario 3: Multi-step workflow (multiple charging steps but no merge)
        elseif ($chargingStepsCount > 1) {
            $translationKey = ($creditsNeeded === 1 && $documentsCount === 1)
                ? 'ui.credits_workflow_multi_step_singular'
                : 'ui.credits_workflow_multi_step';
            $message = __($translationKey, [
                'credits' => $creditsNeeded,
                'count' => $documentsCount,
                'steps' => $chargingStepsCount,
            ]);
        }
        // Scenario 4: Standard conversion (single-step or folded per-file)
        else {
            $translationKey = ($creditsNeeded === 1 && $documentsCount === 1)
                ? 'ui.credits_conversion_standard_singular'
                : 'ui.credits_conversion_standard';
            $message = __($translationKey, [
                'credits' => $creditsNeeded,
                'count' => $documentsCount,
            ]);
        }

        return [
            'valid' => true,
            'credits_needed' => $creditsNeeded,
            'payment_source' => $paymentSource,
            'message' => $message,
        ];
    }

    /**
     * Get user organizations with positive balance
     */
    private function getUserOrganizationsWithPositiveBalance(User $user)
    {
        $sortCriteria = config('credits.organization_selection.sort_criteria', [
            'joined_at' => 'asc',
            'id' => 'asc',
        ]);

        $minimumBalance = config('credits.organization_selection.minimum_balance', 1);

        $query = $user->organizations()
            ->join('organization_credit_pool', 'organizations.id', '=', 'organization_credit_pool.organization_id')
            ->where('organization_credit_pool.balance_credits', '>=', $minimumBalance)
            ->with('creditPool');

        // Apply sorting
        foreach ($sortCriteria as $field => $direction) {
            if ($field === 'joined_at') {
                $query->orderBy('organization_user.joined_at', $direction);
            } else {
                $query->orderBy("organizations.{$field}", $direction);
            }
        }

        return $query->get();
    }

    /**
     * Charge organization credits
     */
    private function chargeOrganization(Batch $batch, Workflow $workflow, array $paymentSource, int $creditsNeeded, $timestamp): void
    {
        $organization = $paymentSource['organization'];
        $currentBalance = $paymentSource['balance'];
        $newBalance = $currentBalance - $creditsNeeded;

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
            'batch_id' => $batch->id, // Fixed: batch_id now char(36) to match batches.id
            'workflow_id' => $workflow->id,
            'delta' => -$creditsNeeded,
            'reason' => 'spend',
            'balance_after' => $newBalance,
            'meta' => [
                'documents_count' => $batch->documents_count ?? 0,
                'workflow_name' => $workflow->name,
            ],
            'created_at' => $timestamp,
        ]);
    }

    /**
     * Charge user credits
     */
    private function chargeUser(Batch $batch, Workflow $workflow, array $paymentSource, int $creditsNeeded, $timestamp): void
    {
        $user = $paymentSource['user'];
        $currentBalance = $paymentSource['balance'];
        $newBalance = $currentBalance - $creditsNeeded;

        // Update user credits
        $updateData = [
            'credits' => $newBalance,
            'credits_updated_at' => $timestamp,
        ];

        // Track when credits are exhausted (for 24-hour free tier delay)
        // Only set if going from positive to zero AND user has a one-time license
        if ($newBalance <= 0 && $currentBalance > 0) {
            $hasActiveOnetime = $user->userLicenses()
                ->whereHas('license', fn ($q) => $q->where('tier', 'onetime'))
                ->where('status', UserLicense::STATUS_ACTIVE)
                ->where(function ($q) {
                    $q->whereNull('ends_at')
                        ->orWhere('ends_at', '>', now());
                })
                ->exists();

            if ($hasActiveOnetime) {
                $updateData['credits_exhausted_at'] = $timestamp;

                Log::info('CreditsService: User credits exhausted, starting 24-hour delay for free tier', [
                    'user_id' => $user->id,
                    'credits_exhausted_at' => $timestamp,
                ]);
            }
        }

        $user->update($updateData);

        // Create ledger entry
        CreditLedger::create([
            'user_id' => $user->id,
            'batch_id' => $batch->id, // Fixed: batch_id now char(36) to match batches.id
            'workflow_id' => $workflow->id,
            'delta' => -$creditsNeeded,
            'reason' => 'spend',
            'balance_after' => $newBalance,
            'meta' => [
                'documents_count' => $batch->documents_count ?? 0,
                'workflow_name' => $workflow->name,
            ],
            'created_at' => $timestamp,
        ]);
    }

    /**
     * Get credit spending data for a license (user or organization)
     *
     * @param  string  $licenseType  'user' or 'organization'
     * @param  int  $licenseId  User ID or Organization ID
     * @param  array  $options  Filter options: ['days' => int, 'from' => date, 'to' => date]
     * @return array Credit spending statistics
     */
    public function getLicenseSpendingData(string $licenseType, int $licenseId, array $options = []): array
    {
        $defaultOptions = [
            'days' => null,
            'from' => null,
            'to' => null,
        ];
        $options = array_merge($defaultOptions, $options);

        if ($licenseType === 'organization') {
            return $this->getOrganizationSpendingData($licenseId, $options);
        } else {
            return $this->getUserSpendingData($licenseId, $options);
        }
    }

    /**
     * Get credit spending data for a user license
     */
    private function getUserSpendingData(int $userId, array $options): array
    {
        $query = CreditLedger::where('user_id', $userId)
            ->whereIn('reason', ['spend', 'spend_multi_source']);

        $this->applyDateFilters($query, $options);

        $transactions = $query->orderBy('created_at', 'desc')->get();

        return $this->calculateSpendingMetrics($transactions, 'user');
    }

    /**
     * Get credit spending data for an organization license
     */
    private function getOrganizationSpendingData(int $organizationId, array $options): array
    {
        $query = OrganizationCreditLedger::where('organization_id', $organizationId)
            ->whereIn('reason', ['spend', 'spend_multi_source']);

        $this->applyDateFilters($query, $options);

        $transactions = $query->orderBy('created_at', 'desc')->get();

        return $this->calculateSpendingMetrics($transactions, 'organization');
    }

    /**
     * Apply date filters to spending query
     */
    private function applyDateFilters($query, array $options): void
    {
        if ($options['days']) {
            $query->where('created_at', '>=', now()->subDays($options['days']));
        } elseif ($options['from'] || $options['to']) {
            if ($options['from']) {
                $query->whereDate('created_at', '>=', $options['from']);
            }
            if ($options['to']) {
                $query->whereDate('created_at', '<=', $options['to']);
            }
        }
    }

    /**
     * Calculate spending metrics from transaction data
     */
    private function calculateSpendingMetrics($transactions, string $licenseType): array
    {
        $totalCreditsSpent = $transactions->sum(function ($transaction) {
            return abs($transaction->delta);
        });

        $totalDocuments = $transactions->sum(function ($transaction) {
            return $transaction->meta['documents_count'] ?? 0;
        });

        $workflowUsage = $transactions->groupBy(function ($transaction) {
            return $transaction->meta['workflow_name'] ?? 'Unknown';
        })->map(function ($group) {
            return [
                'workflow_name' => $group->first()->meta['workflow_name'] ?? 'Unknown',
                'credits_spent' => $group->sum(function ($t) {
                    return abs($t->delta);
                }),
                'documents_processed' => $group->sum(function ($t) {
                    return $t->meta['documents_count'] ?? 0;
                }),
                'runs_count' => $group->count(),
            ];
        })->sortByDesc('credits_spent')->values();

        // Recent activity (last 7 days for trend)
        $recentTransactions = $transactions->filter(function ($transaction) {
            return $transaction->created_at >= now()->subDays(7);
        });

        $recentCreditsSpent = $recentTransactions->sum(function ($transaction) {
            return abs($transaction->delta);
        });

        return [
            'license_type' => $licenseType,
            'total_credits_spent' => $totalCreditsSpent,
            'total_documents_processed' => $totalDocuments,
            'total_runs' => $transactions->count(),
            'average_credits_per_document' => $totalDocuments > 0 ? round($totalCreditsSpent / $totalDocuments, 2) : 0,
            'average_credits_per_run' => $transactions->count() > 0 ? round($totalCreditsSpent / $transactions->count(), 2) : 0,
            'workflow_usage' => $workflowUsage->take(5), // Top 5 workflows
            'recent_activity' => [
                'credits_spent_last_7_days' => $recentCreditsSpent,
                'runs_last_7_days' => $recentTransactions->count(),
            ],
            'first_transaction_date' => $transactions->min('created_at'),
            'last_transaction_date' => $transactions->max('created_at'),
            'has_activity' => $transactions->count() > 0,
        ];
    }

    /**
     * Detect which folding rule applies to a workflow's steps
     *
     * @param  array  $steps  Workflow steps
     * @return array|null Folding rule configuration or null if no match
     */
    private function detectFoldingRule(array $steps): ?array
    {
        if (empty($steps)) {
            return null;
        }

        // Get all folding rules from config
        $foldingRules = config('workflow_steps.folding_rules', []);

        // Extract step types from workflow
        $stepTypes = array_map(fn ($step) => $step['type'] ?? '', $steps);

        // Try to match folding rules (longest patterns first)
        // Rules are already ordered longest-first in config
        foreach ($foldingRules as $ruleKey => $rule) {
            $pattern = $rule['pattern'] ?? [];

            if (empty($pattern)) {
                continue;
            }

            // Check if workflow steps match this folding rule pattern exactly
            if ($this->stepsMatchPattern($stepTypes, $pattern)) {
                Log::info('CreditsService: Folding rule detected', [
                    'rule_key' => $ruleKey,
                    'pattern' => $pattern,
                    'workflow_steps' => $stepTypes,
                ]);

                return $rule;
            }
        }

        Log::info('CreditsService: No folding rule matched', [
            'workflow_steps' => $stepTypes,
        ]);

        return null;
    }

    /**
     * Check if workflow steps match a folding rule pattern
     *
     * @param  array  $stepTypes  Step types from workflow
     * @param  array  $pattern  Pattern from folding rule
     * @return bool True if steps match pattern
     */
    private function stepsMatchPattern(array $stepTypes, array $pattern): bool
    {
        // Must have same length
        if (count($stepTypes) !== count($pattern)) {
            return false;
        }

        // Must match in order
        for ($i = 0; $i < count($stepTypes); $i++) {
            if ($stepTypes[$i] !== $pattern[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calculate credits for a workflow using folding rule configuration
     *
     * @param  array  $foldingRule  Folding rule configuration
     * @param  int  $documentsCount  Number of documents to process
     * @return int Credits needed
     */
    private function calculateFoldedCredits(array $foldingRule, int $documentsCount): int
    {
        $chargingModel = $foldingRule['charging_model'] ?? 'per_file';

        if ($chargingModel === 'fixed') {
            // Fixed cost: use total_credits_fixed (doesn't scale with document count)
            $credits = $foldingRule['total_credits_fixed'] ?? 1;

            Log::info('CreditsService: Folded credits calculated (fixed)', [
                'charging_model' => 'fixed',
                'credits_total' => $credits,
                'documents_count' => $documentsCount,
            ]);

            return $credits;
        } else {
            // Per-file cost: multiply by document count
            $creditsPerFile = $foldingRule['total_credits_per_file'] ?? 1;
            $credits = $creditsPerFile * $documentsCount;

            Log::info('CreditsService: Folded credits calculated (per_file)', [
                'charging_model' => 'per_file',
                'credits_per_file' => $creditsPerFile,
                'documents_count' => $documentsCount,
                'credits_total' => $credits,
            ]);

            return $credits;
        }
    }

    /**
     * Calculate credits for a workflow without folding (sum individual steps)
     *
     * @param  array  $steps  Workflow steps
     * @param  int  $documentsCount  Number of documents to process
     * @return int Credits needed
     */
    private function calculateUnfoldedCredits(array $steps, int $documentsCount): int
    {
        $totalCredits = 0;
        $stepDetails = [];
        $currentFileCount = $documentsCount; // Track how many files are being processed at each step

        foreach ($steps as $step) {
            $stepType = $step['type'] ?? '';
            $stepConfig = config("workflow_steps.steps.{$stepType}");

            if (! $stepConfig) {
                Log::warning('CreditsService: Step config not found', [
                    'step_type' => $stepType,
                ]);

                continue;
            }

            $creditsPerDocument = $stepConfig['credits_per_document'] ?? 1;
            $chargingModel = $stepConfig['charging_model'] ?? 'per_file';
            $actionType = $stepConfig['action_type'] ?? '';

            if ($chargingModel === 'fixed') {
                // Fixed cost step (e.g., merge operations, system steps)
                $stepCredits = $creditsPerDocument;

                // If this is a merge operation, it outputs 1 file regardless of input
                if ($actionType === 'merge') {
                    $currentFileCount = 1; // Merge always outputs 1 file
                }
            } else {
                // Per-file cost step (e.g., convert operations)
                // Use the current file count (which may have been reduced by a previous merge)
                $stepCredits = $creditsPerDocument * $currentFileCount;
            }

            $totalCredits += $stepCredits;

            $stepDetails[] = [
                'step_type' => $stepType,
                'charging_model' => $chargingModel,
                'credits_per_document' => $creditsPerDocument,
                'step_credits' => $stepCredits,
                'files_processed' => $currentFileCount,
            ];
        }

        Log::info('CreditsService: Unfold credits calculated', [
            'steps_count' => count($steps),
            'documents_count' => $documentsCount,
            'step_details' => $stepDetails,
            'total_credits' => $totalCredits,
        ]);

        return $totalCredits;
    }

    /**
     * Charge credits for API V1 (without Batch requirement)
     *
     * @param  User  $user  User executing the workflow
     * @param  array  $paymentSource  Payment source from getPaymentSource()
     * @param  int  $creditsNeeded  Credits to charge
     * @param  Workflow  $workflow  Workflow being executed
     * @param  int  $workflowExecutionId  WorkflowExecution ID
     * @param  int  $documentsCount  Number of documents processed
     * @return bool Success status
     */
    public function chargeCreditsForV1Api(User $user, array $paymentSource, int $creditsNeeded, Workflow $workflow, int $workflowExecutionId, int $documentsCount): bool
    {
        if ($creditsNeeded <= 0) {
            Log::info('CreditsService: No credits to charge for V1 API', [
                'workflow_execution_id' => $workflowExecutionId,
                'credits_needed' => $creditsNeeded,
            ]);

            return true;
        }

        try {
            DB::transaction(function () use ($user, $paymentSource, $creditsNeeded, $workflow, $workflowExecutionId, $documentsCount) {
                $timestamp = now();
                $currentBalance = $paymentSource['balance'];
                $newBalance = $currentBalance - $creditsNeeded;

                if ($paymentSource['type'] === 'organization') {
                    $organization = $paymentSource['organization'];

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
                        'user_id' => $user->id,
                        'batch_id' => null, // V1 API doesn't use batches
                        'workflow_id' => $workflow->id,
                        'delta' => -$creditsNeeded,
                        'reason' => 'api_v1_conversion',
                        'balance_after' => $newBalance,
                        'meta' => [
                            'documents_count' => $documentsCount,
                            'workflow_name' => $workflow->name,
                            'workflow_execution_id' => $workflowExecutionId,
                            'api_version' => 'v1',
                        ],
                        'created_at' => $timestamp,
                    ]);

                    Log::info('CreditsService: Charged organization credits for V1 API', [
                        'organization_id' => $organization->id,
                        'workflow_execution_id' => $workflowExecutionId,
                        'credits_charged' => $creditsNeeded,
                        'new_balance' => $newBalance,
                    ]);
                } else {
                    // Update user credits
                    $user->update([
                        'credits' => $newBalance,
                        'credits_updated_at' => $timestamp,
                    ]);

                    // Create ledger entry
                    CreditLedger::create([
                        'user_id' => $user->id,
                        'batch_id' => null, // V1 API doesn't use batches
                        'workflow_id' => $workflow->id,
                        'delta' => -$creditsNeeded,
                        'reason' => 'api_v1_conversion',
                        'balance_after' => $newBalance,
                        'meta' => [
                            'documents_count' => $documentsCount,
                            'workflow_name' => $workflow->name,
                            'workflow_execution_id' => $workflowExecutionId,
                            'api_version' => 'v1',
                        ],
                        'created_at' => $timestamp,
                    ]);

                    Log::info('CreditsService: Charged user credits for V1 API', [
                        'user_id' => $user->id,
                        'workflow_execution_id' => $workflowExecutionId,
                        'credits_charged' => $creditsNeeded,
                        'new_balance' => $newBalance,
                    ]);
                }
            });

            return true;
        } catch (\Exception $e) {
            Log::error('CreditsService: Failed to charge credits for V1 API', [
                'workflow_execution_id' => $workflowExecutionId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Activate free tier credits if user has an active one-time license with 0 credits.
     *
     * This handles the fallback scenario where:
     * - User has an active (not expired) one-time license
     * - User has 0 credits
     * - User also has a free tier license
     *
     * In this case, we grant them their free tier credits so they can continue converting.
     *
     * Note: This does NOT apply to recurring/premium licenses - those should wait for reset.
     */
    private function activateFreeTierIfEligible(User $user): void
    {
        // Check if user has an active one-time license that is not expired
        $hasActiveOnetime = $user->userLicenses()
            ->whereHas('license', fn ($q) => $q->where('tier', 'onetime'))
            ->where('status', UserLicense::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->exists();

        if (! $hasActiveOnetime) {
            // No active one-time license, no fallback needed
            return;
        }

        // Check 24-hour delay: if credits were exhausted less than 24 hours ago, don't activate free tier yet
        // This encourages users to purchase more credits instead of immediately falling back to free tier
        if ($user->credits_exhausted_at) {
            $hoursSinceExhausted = $user->credits_exhausted_at->diffInHours(now());

            if ($hoursSinceExhausted < 24) {
                Log::info('CreditsService: Free tier activation delayed (24-hour waiting period)', [
                    'user_id' => $user->id,
                    'credits_exhausted_at' => $user->credits_exhausted_at,
                    'hours_since_exhausted' => $hoursSinceExhausted,
                    'hours_remaining' => 24 - $hoursSinceExhausted,
                ]);

                return; // Don't activate yet, user must wait
            }
        }

        // Check if user has a free tier license
        $freeLicense = $user->userLicenses()
            ->whereHas('license', fn ($q) => $q->where('tier', 'free'))
            ->where('status', UserLicense::STATUS_ACTIVE)
            ->with('license')
            ->first();

        if (! $freeLicense) {
            // No free tier license available
            return;
        }

        // Grant free tier credits
        $freeCredits = $freeLicense->license->credits ?? 15;
        $previousBalance = $user->credits ?? 0;

        $user->update([
            'credits' => $freeCredits,
            'credits_updated_at' => now(),
            'credits_exhausted_at' => null, // Clear the exhausted timestamp
        ]);

        // Create ledger entry for audit trail
        CreditLedger::create([
            'user_id' => $user->id,
            'delta' => $freeCredits - $previousBalance,
            'reason' => 'free_tier_fallback',
            'balance_after' => $freeCredits,
            'meta' => [
                'trigger' => 'onetime_exhausted',
                'free_license_id' => $freeLicense->id,
                'previous_balance' => $previousBalance,
                'waited_24_hours' => true,
            ],
            'created_at' => now(),
        ]);

        Log::info('CreditsService: Activated free tier credits as fallback (after 24-hour delay)', [
            'user_id' => $user->id,
            'previous_balance' => $previousBalance,
            'new_balance' => $freeCredits,
            'free_license_id' => $freeLicense->id,
        ]);
    }

    /**
     * Check if user is in the 24-hour waiting period after exhausting one-time credits.
     *
     * @return array|null Returns waiting period info or null if not in waiting period
     */
    public function getFreeTierWaitingPeriod(User $user): ?array
    {
        if (! $user->credits_exhausted_at) {
            return null;
        }

        // Check if user has an active one-time license
        $hasActiveOnetime = $user->userLicenses()
            ->whereHas('license', fn ($q) => $q->where('tier', 'onetime'))
            ->where('status', UserLicense::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->exists();

        if (! $hasActiveOnetime) {
            return null;
        }

        $hoursSinceExhausted = $user->credits_exhausted_at->diffInHours(now());

        if ($hoursSinceExhausted >= 24) {
            return null; // Waiting period is over
        }

        $hoursRemaining = 24 - $hoursSinceExhausted;
        $availableAt = $user->credits_exhausted_at->copy()->addHours(24);

        return [
            'in_waiting_period' => true,
            'hours_remaining' => $hoursRemaining,
            'available_at' => $availableAt,
            'credits_exhausted_at' => $user->credits_exhausted_at,
        ];
    }
}
