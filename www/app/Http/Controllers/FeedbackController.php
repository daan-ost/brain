<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsService;
use App\Services\ThreadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FeedbackController extends Controller
{
    public function __construct(
        private ThreadService $threadService
    ) {}

    /**
     * Store conversion feedback
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'thumb' => 'required|in:up,down',
            'content' => 'nullable|string|max:500',
            'converter_type' => 'nullable|string|max:100',
            'page_url' => 'nullable|string|max:500',
        ]);

        try {
            $user = $request->user();

            // Build context from request
            $context = [
                'page_url' => $validated['page_url'] ?? $request->headers->get('referer'),
                'converter_type' => $validated['converter_type'],
                'browser' => $this->getBrowserInfo($request),
                'thumb' => $validated['thumb'],
            ];

            // Create the feedback thread
            $thread = $this->threadService->createConversionFeedback(
                user: $user,
                thumb: $validated['thumb'],
                content: $validated['content'] ?? null,
                context: $context
            );

            Log::info('FeedbackController: Feedback submitted', [
                'thread_id' => $thread->id,
                'user_id' => $user->id,
                'thumb' => $validated['thumb'],
                'has_content' => ! empty($validated['content']),
            ]);

            AnalyticsService::log('feedback_submitted', [
                'thumb' => $validated['thumb'],
                'has_content' => ! empty($validated['content']),
                'converter_type' => $validated['converter_type'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'message' => __('feedback.thank_you'),
                'thread_id' => $thread->id,
            ]);
        } catch (\Exception $e) {
            Log::error('FeedbackController: Failed to store feedback', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => __('feedback.error'),
            ], 500);
        }
    }

    /**
     * Get browser info from request
     */
    private function getBrowserInfo(Request $request): string
    {
        $userAgent = $request->userAgent() ?? 'Unknown';

        // Simple browser detection
        if (str_contains($userAgent, 'Chrome')) {
            return 'Chrome';
        }
        if (str_contains($userAgent, 'Firefox')) {
            return 'Firefox';
        }
        if (str_contains($userAgent, 'Safari')) {
            return 'Safari';
        }
        if (str_contains($userAgent, 'Edge')) {
            return 'Edge';
        }

        return 'Other';
    }
}
