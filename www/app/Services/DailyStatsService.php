<?php

namespace App\Services;

use App\Models\DailyStat;
use App\Models\License;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DailyStatsService
{
    /**
     * Compute all stats for a single calendar date and return as array.
     */
    public function computeForDate(Carbon $date): array
    {
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        // --- Revenue (paid orders) ---
        $orders = Order::where('status', 'paid')
            ->whereBetween('paid_at', [$start, $end])
            ->with('license:id,slug,name,tier')
            ->get(['id', 'license_id', 'gross_amount', 'currency', 'paid_at']);

        $revenue = (float) $orders->sum('gross_amount');
        $ordersCount = $orders->count();
        $avgOrderValue = $ordersCount > 0 ? round($revenue / $ordersCount, 2) : 0.0;

        // EUR / USD split
        $eurOrders = $orders->where('currency', 'eur');
        $usdOrders = $orders->where('currency', 'usd');
        $revenueEur = round((float) $eurOrders->sum('gross_amount'), 2);
        $revenueUsd = round((float) $usdOrders->sum('gross_amount'), 2);

        $revenueByLicense = $orders
            ->groupBy(fn ($o) => $o->license?->slug ?? 'unknown')
            ->map(fn ($group) => round($group->sum('gross_amount'), 2))
            ->toArray();

        $ordersByLicense = $orders
            ->groupBy(fn ($o) => $o->license?->slug ?? 'unknown')
            ->map->count()
            ->toArray();

        // Tier breakdown: onetime / premium / free
        $ordersByTier = [];
        foreach ($orders as $order) {
            $tier = $order->license?->tier ?? 'unknown';
            if (! isset($ordersByTier[$tier])) {
                $ordersByTier[$tier] = ['count' => 0, 'revenue' => 0.0];
            }
            $ordersByTier[$tier]['count']++;
            $ordersByTier[$tier]['revenue'] = round($ordersByTier[$tier]['revenue'] + (float) $order->gross_amount, 2);
        }

        // --- Invoice-requested orders ---
        $invoiceRequestedCount = Order::where('status', 'invoice_requested')
            ->whereBetween('created_at', [$start, $end])
            ->count();

        // --- New license activations (user + org, status=active, created today) ---
        $newLicenses = DB::table('user_licenses')
            ->where('status', 'active')
            ->whereBetween('created_at', [$start, $end])
            ->count()
            + DB::table('organization_licenses')
                ->where('status', 'active')
                ->whereBetween('created_at', [$start, $end])
                ->count();

        // --- Expired licenses (ends_at fell within this day, was active) ---
        $expiredLicenses = DB::table('user_licenses')
            ->whereBetween('ends_at', [$start, $end])
            ->whereIn('status', ['expired', 'inactive'])
            ->count()
            + DB::table('organization_licenses')
                ->whereBetween('ends_at', [$start, $end])
                ->whereIn('status', ['expired', 'inactive'])
                ->count();

        // --- Users ---
        $newUsers = User::whereBetween('created_at', [$start, $end])->count();

        // --- Analytics events (single efficient query) ---
        $events = DB::table('analytics_events')
            ->selectRaw("
                SUM(event = 'user_email_confirmed') AS email_confirmed,
                SUM(event = 'user_logged_in') AS logins,
                SUM(event = 'plans_view') AS plans_views,
                SUM(event = 'checkout_started') AS checkout_started,
                SUM(event = 'checkout_payment_initiated') AS checkout_payment_initiated,
                SUM(event = 'credits_purchased') AS credits_purchased_events,
                SUM(event = 'transaction_delete_upgrade_modal_shown') AS upgrade_modal_shown,
                SUM(event = 'landing_page_view') AS pageviews
            ")
            ->whereBetween('created_at', [$start, $end])
            ->first();

        // Active users (distinct user_id with event in period)
        $activeUsers = DB::table('analytics_events')
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->count('user_id');

        // Traffic sources (landing_page_view referrer breakdown)
        $trafficRows = DB::table('analytics_events')
            ->selectRaw("
                SUM(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.referrer')) LIKE '%google%') AS google,
                SUM(
                    JSON_EXTRACT(meta, '$.referrer') IS NULL
                    OR JSON_UNQUOTE(JSON_EXTRACT(meta, '$.referrer')) = 'null'
                    OR JSON_UNQUOTE(JSON_EXTRACT(meta, '$.referrer')) = ''
                ) AS direct
            ")
            ->where('event', 'landing_page_view')
            ->whereBetween('created_at', [$start, $end])
            ->first();

        // --- Credits (user + organisation ledgers combined) ---
        $creditsReceived = (int) DB::table('credit_ledger')
            ->whereBetween('created_at', [$start, $end])
            ->where('delta', '>', 0)
            ->sum('delta')
            + (int) DB::table('organization_credit_ledger')
                ->whereBetween('created_at', [$start, $end])
                ->where('delta', '>', 0)
                ->sum('delta');

        $creditsSpent = abs(
            (int) DB::table('credit_ledger')
                ->whereBetween('created_at', [$start, $end])
                ->where('delta', '<', 0)
                ->sum('delta')
            + (int) DB::table('organization_credit_ledger')
                ->whereBetween('created_at', [$start, $end])
                ->where('delta', '<', 0)
                ->sum('delta')
        );

        return [
            'date' => $date->format('Y-m-d'),
            'revenue' => $revenue,
            'orders_count' => $ordersCount,
            'avg_order_value' => $avgOrderValue,
            'revenue_eur' => $revenueEur,
            'revenue_usd' => $revenueUsd,
            'orders_eur' => $eurOrders->count(),
            'orders_usd' => $usdOrders->count(),
            'revenue_by_license' => $revenueByLicense ?: null,
            'orders_by_license' => $ordersByLicense ?: null,
            'orders_by_tier' => $ordersByTier ?: null,
            'new_licenses' => $newLicenses,
            'expired_licenses' => $expiredLicenses,
            'invoice_requested_count' => $invoiceRequestedCount,
            'new_users' => $newUsers,
            'email_confirmed' => (int) ($events->email_confirmed ?? 0),
            'logins' => (int) ($events->logins ?? 0),
            'active_users' => $activeUsers,
            'plans_views' => (int) ($events->plans_views ?? 0),
            'checkout_started' => (int) ($events->checkout_started ?? 0),
            'checkout_payment_initiated' => (int) ($events->checkout_payment_initiated ?? 0),
            'credits_purchased_events' => (int) ($events->credits_purchased_events ?? 0),
            'upgrade_modal_shown' => (int) ($events->upgrade_modal_shown ?? 0),
            'pageviews' => (int) ($events->pageviews ?? 0),
            'pageviews_google' => (int) ($trafficRows->google ?? 0),
            'pageviews_direct' => (int) ($trafficRows->direct ?? 0),
            'credits_received' => $creditsReceived,
            'credits_spent' => $creditsSpent,
            'generated_at' => now(),
        ];
    }

    /**
     * Generate (or refresh) daily_stats rows for a date range.
     * Skips dates already present unless $force = true.
     * Today is always recomputed (data is still accumulating).
     *
     * @return int Number of dates processed
     */
    public function generateForDateRange(Carbon $from, Carbon $to, bool $force = false): int
    {
        $count = 0;
        $current = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($current->lte($end)) {
            $dateStr = $current->format('Y-m-d');
            $isToday = $current->isToday();

            if (! $force && ! $isToday) {
                if (DailyStat::where('date', $dateStr)->exists()) {
                    $current->addDay();
                    continue;
                }
            }

            $data = $this->computeForDate($current->copy());
            DailyStat::updateOrCreate(['date' => $dateStr], $data);
            $count++;
            $current->addDay();
        }

        return $count;
    }

    /**
     * Aggregate daily_stats rows for a period into a single summary array.
     * For active_users the true unique count is computed live (union of all distinct users).
     */
    public function aggregate(Carbon $from, Carbon $to): array
    {
        $rows = DailyStat::inRange($from, $to)->get();

        $ordersCount = $rows->sum('orders_count');
        $revenue = $rows->sum('revenue');

        return [
            'revenue'                    => round((float) $revenue, 2),
            'revenue_eur'                => round((float) $rows->sum('revenue_eur'), 2),
            'revenue_usd'                => round((float) $rows->sum('revenue_usd'), 2),
            'orders_count'               => $ordersCount,
            'orders_eur'                 => (int) $rows->sum('orders_eur'),
            'orders_usd'                 => (int) $rows->sum('orders_usd'),
            'avg_order_value'            => $ordersCount > 0 ? round((float) $revenue / $ordersCount, 2) : 0.0,
            'new_users'                  => $rows->sum('new_users'),
            'email_confirmed'            => $rows->sum('email_confirmed'),
            'logins'                     => $rows->sum('logins'),
            'active_users'               => $rows->sum('active_users'),
            'plans_views'                => $rows->sum('plans_views'),
            'checkout_started'           => $rows->sum('checkout_started'),
            'checkout_payment_initiated' => $rows->sum('checkout_payment_initiated'),
            'credits_purchased_events'   => $rows->sum('credits_purchased_events'),
            'upgrade_modal_shown'        => $rows->sum('upgrade_modal_shown'),
            'pageviews'                  => $rows->sum('pageviews'),
            'pageviews_google'           => $rows->sum('pageviews_google'),
            'pageviews_direct'           => $rows->sum('pageviews_direct'),
            'credits_received'           => $rows->sum('credits_received'),
            'credits_spent'              => $rows->sum('credits_spent'),
            'new_licenses'               => (int) $rows->sum('new_licenses'),
            'expired_licenses'           => (int) $rows->sum('expired_licenses'),
            'invoice_requested_count'    => (int) $rows->sum('invoice_requested_count'),
            'days'                       => $rows->count(),
            // Derived
            'checkout_conversion'        => $rows->sum('checkout_started') > 0
                ? round(($rows->sum('credits_purchased_events') / $rows->sum('checkout_started')) * 100, 1)
                : 0.0,
        ];
    }

    /**
     * Revenue breakdown per license across a date range.
     * Enriched with license name, tier, and currency from the licenses table.
     *
     * @return array<string, array{name: string, tier: string, revenue: float, orders: int}>
     */
    public function revenueByLicense(Carbon $from, Carbon $to): array
    {
        $rows = DailyStat::inRange($from, $to)
            ->whereNotNull('revenue_by_license')
            ->get(['revenue_by_license', 'orders_by_license']);

        $revenue = [];
        $orders = [];

        foreach ($rows as $row) {
            foreach ($row->revenue_by_license ?? [] as $slug => $amount) {
                $revenue[$slug] = ($revenue[$slug] ?? 0) + (float) $amount;
            }
            foreach ($row->orders_by_license ?? [] as $slug => $count) {
                $orders[$slug] = ($orders[$slug] ?? 0) + (int) $count;
            }
        }

        // Enrich with license metadata (name, tier, currency) keyed by slug
        $slugs = array_keys($revenue);
        $licenseMap = License::whereIn('slug', $slugs)
            ->get(['slug', 'name', 'tier', 'currency', 'credits'])
            ->keyBy('slug');

        arsort($revenue);

        $result = [];
        foreach ($revenue as $slug => $amount) {
            $license = $licenseMap->get($slug);
            $result[$slug] = [
                'name'     => $license?->name ?? $slug,
                'tier'     => $license?->tier ?? 'unknown',
                'currency' => $license?->currency ?? '?',
                'credits'  => $license?->credits ?? 0,
                'revenue'  => round($amount, 2),
                'orders'   => $orders[$slug] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Tier breakdown aggregated across a date range.
     *
     * @return array<string, array{count: int, revenue: float}>
     */
    public function ordersByTier(Carbon $from, Carbon $to): array
    {
        $rows = DailyStat::inRange($from, $to)
            ->whereNotNull('orders_by_tier')
            ->get(['orders_by_tier']);

        $result = [];
        foreach ($rows as $row) {
            foreach ($row->orders_by_tier ?? [] as $tier => $data) {
                if (! isset($result[$tier])) {
                    $result[$tier] = ['count' => 0, 'revenue' => 0.0];
                }
                $result[$tier]['count'] += (int) ($data['count'] ?? 0);
                $result[$tier]['revenue'] = round($result[$tier]['revenue'] + (float) ($data['revenue'] ?? 0), 2);
            }
        }

        arsort($result);

        return $result;
    }

    /**
     * Resolve the date range for a period preset.
     *
     * @return array{from: Carbon, to: Carbon}
     */
    public static function resolvePeriod(string $preset, ?string $customFrom = null, ?string $customTo = null): array
    {
        $today = Carbon::today();

        return match ($preset) {
            '7d' => ['from' => $today->copy()->subDays(6), 'to' => $today],
            '30d' => ['from' => $today->copy()->subDays(29), 'to' => $today],
            '90d' => ['from' => $today->copy()->subDays(89), 'to' => $today],
            'mtd' => ['from' => $today->copy()->startOfMonth(), 'to' => $today],
            'prev_month' => [
                'from' => $today->copy()->subMonth()->startOfMonth(),
                'to' => $today->copy()->subMonth()->endOfMonth(),
            ],
            'ytd' => ['from' => $today->copy()->startOfYear(), 'to' => $today],
            '12m' => ['from' => $today->copy()->subMonths(11)->startOfMonth(), 'to' => $today],
            'custom' => [
                'from' => Carbon::parse($customFrom ?? $today->copy()->subDays(29)->format('Y-m-d')),
                'to' => Carbon::parse($customTo ?? $today->format('Y-m-d')),
            ],
            default => ['from' => $today->copy()->subDays(29), 'to' => $today],
        };
    }

    /**
     * Resolve comparison period dates given a primary period.
     *
     * @return array{from: Carbon, to: Carbon}|null
     */
    public static function resolveComparePeriod(string $compareMode, Carbon $from, Carbon $to): ?array
    {
        if ($compareMode === 'none') {
            return null;
        }

        $days = (int) $from->diffInDays($to);

        if ($compareMode === 'previous') {
            return [
                'from' => $from->copy()->subDays($days + 1),
                'to' => $from->copy()->subDay(),
            ];
        }

        if ($compareMode === 'year') {
            return [
                'from' => $from->copy()->subYear(),
                'to' => $to->copy()->subYear(),
            ];
        }

        return null;
    }
}
