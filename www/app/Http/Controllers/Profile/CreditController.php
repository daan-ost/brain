<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CreditController extends Controller
{
    /**
     * Display the user's credits information.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        // Load user with organizations and their current licenses
        $user->load(['organizations.currentLicenses.license']);

        // Get all active licenses in priority order
        $activeLicenses = $user->getAllActiveLicenses();

        // Get credit summary
        $creditSummary = $user->getCreditSummary();

        // Track page view
        AnalyticsService::log('credits_view', [
            'current_balance' => $user->credits ?? 0,
            'has_organization' => $user->organizations()->exists(),
            'active_licenses_count' => $activeLicenses->count(),
        ]);

        return view('profile.credits', [
            'user' => $user,
            'activeLicenses' => $activeLicenses,
            'creditSummary' => $creditSummary,
        ]);
    }
}
