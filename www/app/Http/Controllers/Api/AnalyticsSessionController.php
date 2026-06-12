<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SessionTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsSessionController extends Controller
{
    /**
     * Update analytics session with client-side tracking data
     *
     * POST /api/analytics/session
     */
    public function update(Request $request): JsonResponse
    {
        // Check kill-switch
        if (! config('analytics.client_tracking_enabled', true)) {
            return response()->json(['ok' => true, 'skipped' => true]);
        }

        $validated = $request->validate([
            'session_id' => 'required|uuid',
            'session_group_id' => 'nullable|uuid',
            'rage_clicks' => 'nullable|integer|min:0',
            'rapid_click_count' => 'nullable|integer|min:0',
            'form_abandonment' => 'nullable|boolean',
            'scroll_depth' => 'nullable|numeric|min:0|max:1',
            'actions' => 'nullable|array|max:50',
            'actions.*.type' => 'required|string|max:20',
            'actions.*.target' => 'nullable|string|max:100',
            'actions.*.t' => 'required|numeric',
            'exit_actions' => 'nullable|array|max:20',
        ]);

        SessionTrackingService::updateFromClient(
            $validated['session_id'],
            $validated
        );

        return response()->json(['ok' => true]);
    }
}
