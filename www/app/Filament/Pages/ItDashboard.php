<?php

namespace App\Filament\Pages;

use App\Models\CreditLedger;
use App\Models\OrganizationCreditLedger;
use App\Services\DeepLService;
use App\Services\IPRegistryService;
use App\Services\PostmarkTemplateService;
use App\Services\ServerMonitoringService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ItDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static string $view = 'filament.pages.it-dashboard';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 100;

    protected static ?string $title = 'IT Dashboard';

    public array $data = [];

    public function mount(): void
    {
        $this->loadDashboardData();
    }

    protected function loadDashboardData(): void
    {
        $this->data = Cache::remember('it-dashboard-data', 300, function () {
            $serverService = app(ServerMonitoringService::class);

            return [
                'server' => $this->getServerData($serverService),
                'queue' => $this->getQueueData(),
                'deepl' => $this->getDeepLData(),
                'postmark' => $this->getPostmarkData(),
                'ipregistry' => $this->getIPRegistryData(),
                'credits' => $this->getCreditsData(),
            ];
        });
    }

    protected function getServerData(ServerMonitoringService $service): array
    {
        return [
            'cpu_usage' => $service->getCpuUsage() ?? 0,
            'load_average' => $service->getLoadAverage(),
            'memory' => $service->getMemoryUsage(),
            'disk' => $service->getDiskUsage(),
            'uptime' => $service->getServerUptime(),
        ];
    }

    protected function getQueueData(): array
    {
        return [
            'pending' => DB::table('jobs')->count(),
            'failed_24h' => DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->count(),
        ];
    }

    protected function getDeepLData(): array
    {
        try {
            $service = app(DeepLService::class);
            $usage = $service->getUsage();

            return [
                'character_count' => $usage['character_count'] ?? 0,
                'character_limit' => $usage['character_limit'] ?? 500000,
                'percent_used' => $usage['character_limit'] > 0
                    ? round(($usage['character_count'] / $usage['character_limit']) * 100, 1)
                    : 0,
            ];
        } catch (\Exception $e) {
            return [
                'character_count' => 0,
                'character_limit' => 0,
                'percent_used' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function getPostmarkData(): array
    {
        // Postmark stats not available via public API - return placeholder data
        // TODO: Add public method to PostmarkTemplateService if stats are needed
        return [
            'emails_today' => 0,
            'emails_yesterday' => 0,
            'emails_last_7_days' => 0,
            'emails_this_month' => 0,
            'bounce_rate_today' => 0,
            'bounce_rate_month' => 0,
            'not_configured' => true,
        ];
    }

    protected function getIPRegistryData(): array
    {
        // IPRegistry stats methods not available - return placeholder data
        return [
            'detections_today' => 0,
            'detections_this_month' => 0,
            'cache_hit_rate_percent' => 0,
            'estimated_api_calls_month' => 0,
            'top_countries' => [],
            'not_configured' => true,
        ];
    }

    protected function getCreditsData(): array
    {
        $today = now()->startOfDay();

        // User credits consumed today
        $userCreditsConsumed = CreditLedger::where('created_at', '>=', $today)
            ->where('delta', '<', 0)
            ->sum('delta');

        // Organization credits consumed today
        $orgCreditsConsumed = OrganizationCreditLedger::where('created_at', '>=', $today)
            ->where('delta', '<', 0)
            ->sum('delta');

        // Credits issued today (purchases, bonuses)
        $creditsIssued = CreditLedger::where('created_at', '>=', $today)
            ->where('delta', '>', 0)
            ->sum('delta');

        return [
            'consumed_today' => abs($userCreditsConsumed) + abs($orgCreditsConsumed),
            'user_credits_today' => abs($userCreditsConsumed),
            'org_credits_today' => abs($orgCreditsConsumed),
            'issued_today' => $creditsIssued,
        ];
    }

    public function refresh(): void
    {
        Cache::forget('it-dashboard-data');
        $this->loadDashboardData();
    }
}
