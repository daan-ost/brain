<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\User;
use App\Models\WorkflowExecution;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TransactionQueryService
{
    private const VALID_WEB_SORT_COLUMNS = ['created_at', 'status', 'expires_at'];

    private const VALID_API_SORT_COLUMNS = ['created_at', 'status'];

    private const COMPLETED_STATUSES = ['done', 'error'];

    private const DEFAULT_PER_PAGE = 20;

    /**
     * Get web transactions (batches) for a user
     *
     * @return array{transactions: LengthAwarePaginator, total: int, credits: int}
     */
    public function getWebTransactions(
        User $user,
        string $dateFrom,
        string $dateTo,
        string $sortBy = 'created_at',
        string $sortDirection = 'desc'
    ): array {
        $query = $user->batches()
            ->with(['workflowExecution.workflow'])
            ->whereIn('status', self::COMPLETED_STATUSES)
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo);

        if (in_array($sortBy, self::VALID_WEB_SORT_COLUMNS)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $transactions = $query->paginate(self::DEFAULT_PER_PAGE);

        // Calculate totals
        $totalQuery = $user->batches()
            ->whereIn('status', self::COMPLETED_STATUSES)
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo);

        return [
            'transactions' => $transactions,
            'total' => $totalQuery->count(),
            'credits' => $totalQuery->sum('credits_spent'),
        ];
    }

    /**
     * Get API transactions (workflow executions) for a user
     *
     * @return array{transactions: LengthAwarePaginator, total: int, credits: int}
     */
    public function getApiTransactions(
        User $user,
        string $dateFrom,
        string $dateTo,
        string $sortBy = 'created_at',
        string $sortDirection = 'desc'
    ): array {
        $apiSources = [WorkflowExecution::SOURCE_API_V1, WorkflowExecution::SOURCE_API_V2];

        $query = WorkflowExecution::query()
            ->with(['workflow'])
            ->where('user_id', $user->id)
            ->whereIn('source', $apiSources)
            ->whereIn('status', self::COMPLETED_STATUSES)
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo);

        if (in_array($sortBy, self::VALID_API_SORT_COLUMNS)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $transactions = $query->paginate(self::DEFAULT_PER_PAGE);

        // Calculate totals
        $totalQuery = WorkflowExecution::query()
            ->where('user_id', $user->id)
            ->whereIn('source', $apiSources)
            ->whereIn('status', self::COMPLETED_STATUSES)
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo);

        return [
            'transactions' => $transactions,
            'total' => $totalQuery->count(),
            'credits' => 0, // API credits tracked via ledger
        ];
    }

    /**
     * Normalize and validate filter parameters
     *
     * @return array{tab: string, dateFrom: string, dateTo: string, sortBy: string, sortDirection: string}
     */
    public function normalizeFilters(
        ?string $tab,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $sortBy,
        ?string $sortDirection
    ): array {
        return [
            'tab' => in_array($tab, ['web', 'api']) ? $tab : 'web',
            'dateFrom' => $dateFrom ?: now()->subMonth()->format('Y-m-d'),
            'dateTo' => $dateTo ?: now()->format('Y-m-d'),
            'sortBy' => $sortBy ?: 'created_at',
            'sortDirection' => in_array($sortDirection, ['asc', 'desc']) ? $sortDirection : 'desc',
        ];
    }

    /**
     * Validate batch deletion authorization
     *
     * @return array{allowed: bool, error: string|null}
     */
    public function validateDeletion(Batch $batch, User $user): array
    {
        // Check ownership
        if ($batch->user_id !== $user->id) {
            return [
                'allowed' => false,
                'error' => __('profile.unauthorized_delete'),
                'reason' => 'unauthorized_access',
            ];
        }

        // Check license tier
        $currentLicense = $user->getCurrentLicense();
        $isFreeUser = ! $currentLicense || $currentLicense->tier === 'free';

        if ($isFreeUser) {
            return [
                'allowed' => false,
                'error' => __('profile.free_tier_delete_blocked'),
                'reason' => 'free_tier_restriction',
            ];
        }

        return [
            'allowed' => true,
            'error' => null,
            'reason' => null,
        ];
    }

    /**
     * Build analytics data for batch deletion
     */
    public function buildDeletionAnalytics(Batch $batch, User $user): array
    {
        $currentLicense = $user->getCurrentLicense();

        $data = [
            'batch_id' => $batch->id,
            'user_license_tier' => $currentLicense?->tier ?? 'none',
            'file_size' => $batch->result_size,
            'batch_status' => $batch->status,
            'credits_spent' => $batch->credits_spent,
        ];

        // Add workflow context if available
        if ($batch->workflow_execution_id) {
            $execution = $batch->workflowExecution;
            if ($execution) {
                $data['workflow_id'] = $execution->workflow_id;
                $data['workflow_name'] = $execution->workflow?->name;
                $data['page_slug'] = $this->extractPageSlug($batch, $execution);
            }
        }

        return $data;
    }

    /**
     * Extract page_slug from batch or execution (priority order)
     */
    private function extractPageSlug(Batch $batch, WorkflowExecution $execution): ?string
    {
        // Priority 1: Batch inputs_json
        if (isset($batch->inputs_json['page_slug'])) {
            return $batch->inputs_json['page_slug'];
        }

        // Priority 2: Execution snapshot (landing_page)
        if (isset($execution->execution_snapshot['landing_page'])) {
            return $execution->execution_snapshot['landing_page'];
        }

        // Priority 3: Alternative snapshot key (legacy)
        if (isset($execution->execution_snapshot['page_slug'])) {
            return $execution->execution_snapshot['page_slug'];
        }

        return null;
    }
}
