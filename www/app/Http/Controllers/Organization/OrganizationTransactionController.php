<?php

namespace App\Http\Controllers\Organization;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Models\WorkflowExecution;
use App\Services\AnalyticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrganizationTransactionController extends Controller
{
    /**
     * Display organization transaction history.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        // Get user's organizations
        $organizations = $user->organizations()->get();

        if ($organizations->isEmpty()) {
            return view('profile.organization-transactions', [
                'user' => $user,
                'transactions' => collect(),
                'organizations' => $organizations,
                'activeTab' => 'web',
            ]);
        }

        // Check if user is admin in any organization
        $isAdmin = $organizations->contains(function ($org) {
            return $org->pivot->role === OrganizationRole::Owner;
        });

        if (! $isAdmin) {
            return redirect()->route('profile.organization')->with('error', 'You do not have permission to view organization transactions.');
        }

        // Determine which tab is active (web or api)
        $activeTab = $request->get('tab', 'web');
        if (! in_array($activeTab, ['web', 'api'])) {
            $activeTab = 'web';
        }

        // Set default dates if not provided
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');

        // If date_from is empty, default to 1 month ago
        if (empty($dateFrom)) {
            $dateFrom = now()->subMonth()->format('Y-m-d');
        }

        // If date_to is empty, default to current date
        if (empty($dateTo)) {
            $dateTo = now()->format('Y-m-d');
        }

        // Apply sorting
        $sortBy = $request->get('sort', 'created_at');
        $sortDirection = $request->get('direction', 'desc');

        $organizationIds = $organizations->pluck('id');

        if ($activeTab === 'web') {
            // Web conversions: not available in basewebsite (pdfen-specific)
            $transactions = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20);
            $totalTransactions = 0;
            $totalCredits = 0;
        } else {
            // API conversions: Get workflow executions with API source
            $query = WorkflowExecution::query()
                ->with(['workflow', 'user'])
                ->whereIn('organization_id', $organizationIds)
                ->whereIn('source', [WorkflowExecution::SOURCE_API_V1, WorkflowExecution::SOURCE_API_V2])
                ->whereIn('status', ['done', 'error'])
                ->orderBy('created_at', 'desc');

            if (in_array($sortBy, ['created_at', 'status'])) {
                $query->orderBy($sortBy, $sortDirection);
            }

            $query->whereDate('created_at', '>=', $dateFrom);
            $query->whereDate('created_at', '<=', $dateTo);

            $transactions = $query->paginate(20);

            // Calculate totals for API conversions
            $totalQuery = WorkflowExecution::query()
                ->whereIn('organization_id', $organizationIds)
                ->whereIn('source', [WorkflowExecution::SOURCE_API_V1, WorkflowExecution::SOURCE_API_V2])
                ->whereIn('status', ['done', 'error'])
                ->whereDate('created_at', '>=', $dateFrom)
                ->whereDate('created_at', '<=', $dateTo);

            $totalTransactions = $totalQuery->count();
            $totalCredits = 0; // API credits tracked separately
        }

        // Track page view
        AnalyticsService::log('organization_transactions_view', [
            'organization_id' => $organizations->first()->id,
            'tab' => $activeTab,
            'transaction_count' => $totalTransactions,
            'has_filters' => $request->filled('date_from') || $request->filled('date_to'),
            'date_range_days' => now()->parse($dateFrom)->diffInDays(now()->parse($dateTo)),
        ]);

        return view('profile.organization-transactions', [
            'user' => $user,
            'transactions' => $transactions,
            'organizations' => $organizations,
            'activeTab' => $activeTab,
            'sortBy' => $sortBy,
            'sortDirection' => $sortDirection,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'totalTransactions' => $totalTransactions,
            'totalCredits' => $totalCredits,
        ]);
    }
}
