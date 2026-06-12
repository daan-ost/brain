<?php

namespace App\Http\Controllers\Api\AI;

use App\Http\Controllers\Controller;
use App\Services\AI\AnalyticsAggregatorService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiAnalyticsController extends Controller
{
    public function __construct(
        private AnalyticsAggregatorService $aggregatorService
    ) {}

    /**
     * GET /api/ai/analytics/summary
     *
     * Returns aggregated analytics for a period (max 30 days).
     */
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->endOfDay();

        // Validate max 30 days
        if ($from->diffInDays($to) > 30) {
            return response()->json([
                'error' => 'Date range cannot exceed 30 days',
            ], 422);
        }

        try {
            $summary = $this->aggregatorService->getSummary($from, $to);

            return response()->json($summary);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/ai/analytics/user-diagnostics
     *
     * Returns debug summary for a specific user.
     */
    public function userDiagnostics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->endOfDay();

        // Validate max 30 days
        if ($from->diffInDays($to) > 30) {
            return response()->json([
                'error' => 'Date range cannot exceed 30 days',
            ], 422);
        }

        try {
            $diagnostics = $this->aggregatorService->getUserDiagnostics(
                (int) $validated['user_id'],
                $from,
                $to
            );

            return response()->json($diagnostics);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
