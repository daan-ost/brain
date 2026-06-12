<?php

use App\Http\Controllers\Api\AI\AiAnalyticsController;
use App\Http\Controllers\Api\AnalyticsSessionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['auth:sanctum', 'track-api-token']);

// Analytics Session API (rate limited: 20 req/min per session_id)
Route::middleware(['web', 'throttle:analytics'])
    ->post('/analytics/session', [AnalyticsSessionController::class, 'update']);

// AI Analytics API (internal only - requires dual authentication)
Route::middleware('internal-only')
    ->prefix('ai/analytics')
    ->group(function () {
        Route::get('/summary', [AiAnalyticsController::class, 'summary']);
        Route::get('/user-diagnostics', [AiAnalyticsController::class, 'userDiagnostics']);
    });
