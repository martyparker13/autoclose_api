<?php

namespace App\Services;

use App\Models\CreditApplication;
use App\Models\Deal;
use App\Models\Dealer;
use App\Models\FiProduct;
use App\Models\ActivityLog;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportingService
{
    /**
     * High-level KPI summary for the dealer dashboard.
     *
     * Returns period-over-period deltas so the front-end can show "up 12% vs last month".
     *
     * @return array<string, mixed>
     */
    public function summary(Dealer $dealer, string $period = '30d'): array
    {
        [$start, $prevStart, $prevEnd] = $this->periodDates($period);

        $base = Deal::forDealer($dealer->id)->withoutTrashed();

        // Current period
        $current = (clone $base)->where('created_at', '>=', $start);
        $prev    = (clone $base)->whereBetween('created_at', [$prevStart, $prevEnd]);

        $totalDeals        = (clone $current)->count();
        $prevTotalDeals    = (clone $prev)->count();

        $closedDeals       = (clone $current)->where('status', 'delivered')->count();
        $prevClosedDeals   = (clone $prev)->where('status', 'delivered')->count();

        $revenue           = (clone $current)->where('status', 'delivered')->sum('sale_price');
        $prevRevenue       = (clone $prev)->where('status', 'delivered')->sum('sale_price');

        $fiIncome          = (clone $current)->where('status', 'delivered')->sum('total_fi_income');
        $prevFiIncome      = (clone $prev)->where('status', 'delivered')->sum('total_fi_income');

        $pendingCredit     = (clone $base)->where('status', 'credit_submitted')->count();

        $avgSalePrice      = (clone $current)->where('status', 'delivered')->avg('sale_price') ?? 0;

        return [
            'period'          => $period,
            'period_start'    => $start->toDateString(),
            'total_deals'     => $totalDeals,
            'total_deals_delta' => $this->delta($totalDeals, $prevTotalDeals),
            'closed_deals'    => $closedDeals,
            'closed_deals_delta' => $this->delta($closedDeals, $prevClosedDeals),
            'revenue_cents'   => (int) $revenue,
            'revenue_delta'   => $this->delta((int) $revenue, (int) $prevRevenue),
            'fi_income_cents' => (int) $fiIncome,
            'fi_income_delta' => $this->delta((int) $fiIncome, (int) $prevFiIncome),
            'pending_credit'  => $pendingCredit,
            'avg_sale_price_cents' => (int) $avgSalePrice,
        ];
    }

    /**
     * Deal counts grouped by status — the pipeline funnel.
     *
     * @return array<string, int>
     */
    public function dealFunnel(Dealer $dealer): array
    {
        $rows = Deal::forDealer($dealer->id)
            ->withoutTrashed()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $statuses = [
            'draft', 'credit_submitted', 'credit_approved', 'credit_declined',
            'docs_pending', 'docs_signed', 'awaiting_delivery', 'delivered', 'cancelled',
        ];

        $funnel = [];
        foreach ($statuses as $s) {
            $funnel[$s] = $rows[$s] ?? 0;
        }

        return $funnel;
    }

    /**
     * Monthly deal volume and revenue for the trailing 12 months.
     *
     * @return list<array{month: string, deals: int, revenue_cents: int, fi_income_cents: int}>
     */
    public function monthlyTrend(Dealer $dealer): array
    {
        $start = now()->subMonths(11)->startOfMonth();
        $monthExpr = $this->monthBucketExpression();

        $rows = Deal::forDealer($dealer->id)
            ->withoutTrashed()
            ->where('created_at', '>=', $start)
            ->select(
                DB::raw("{$monthExpr} as month"),
                DB::raw('COUNT(*) as deals'),
                DB::raw("SUM(CASE WHEN status = 'delivered' THEN sale_price ELSE 0 END) as revenue_cents"),
                DB::raw("SUM(CASE WHEN status = 'delivered' THEN total_fi_income ELSE 0 END) as fi_income_cents"),
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $key    = now()->subMonths($i)->format('Y-m');
            $row    = $rows->get($key);
            $months[] = [
                'month'           => $key,
                'deals'           => $row ? (int) $row->deals : 0,
                'revenue_cents'   => $row ? (int) $row->revenue_cents : 0,
                'fi_income_cents' => $row ? (int) $row->fi_income_cents : 0,
            ];
        }

        return $months;
    }

    /**
     * Top 10 vehicles by number of closed deals.
     *
     * @return list<array{vehicle_id: int, year: int, make: string, model: string, deals: int, revenue_cents: int}>
     */
    public function topVehicles(Dealer $dealer, int $limit = 10): array
    {
        $aggregated = Deal::forDealer($dealer->id)
            ->withoutTrashed()
            ->where('status', 'delivered')
            ->select(
                'deals.vehicle_id',
                DB::raw('COUNT(*) as deals'),
                DB::raw('SUM(deals.sale_price) as revenue_cents'),
            )
            ->groupBy('deals.vehicle_id');

        return DB::query()
            ->fromSub($aggregated, 'agg')
            ->join('vehicles', 'vehicles.id', '=', 'agg.vehicle_id')
            ->select(
                'agg.vehicle_id',
                'vehicles.year',
                'vehicles.make',
                'vehicles.model',
                'agg.deals',
                'agg.revenue_cents',
            )
            ->orderByDesc('agg.deals')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'vehicle_id'    => $r->vehicle_id,
                'year'          => $r->year,
                'make'          => $r->make,
                'model'         => $r->model,
                'deals'         => (int) $r->deals,
                'revenue_cents' => (int) $r->revenue_cents,
            ])
            ->all();
    }

    /**
     * Top 5 staff by number of closed deals.
     *
     * @return list<array{salesperson_id: int, name: string, deals: int, revenue_cents: int}>
     */
    public function topStaff(Dealer $dealer, int $limit = 5): array
    {
        $aggregated = Deal::forDealer($dealer->id)
            ->withoutTrashed()
            ->where('status', 'delivered')
            ->whereNotNull('salesperson_id')
            ->select(
                'deals.salesperson_id',
                DB::raw('COUNT(*) as deals'),
                DB::raw('SUM(deals.sale_price) as revenue_cents'),
            )
            ->groupBy('deals.salesperson_id');

        return DB::query()
            ->fromSub($aggregated, 'agg')
            ->join('users', 'users.id', '=', 'agg.salesperson_id')
            ->select(
                'agg.salesperson_id',
                'users.name',
                'agg.deals',
                'agg.revenue_cents',
            )
            ->orderByDesc('agg.deals')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'salesperson_id' => $r->salesperson_id,
                'name'           => $r->name,
                'deals'          => (int) $r->deals,
                'revenue_cents'  => (int) $r->revenue_cents,
            ])
            ->all();
    }

    /**
     * Inventory snapshot — available / pending / sold counts.
     *
     * @return array<string, int>
     */
    public function inventorySnapshot(Dealer $dealer): array
    {
        $rows = Vehicle::where('dealer_id', $dealer->id)
            ->whereNull('deleted_at')
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        return [
            'available' => $rows['available'] ?? 0,
            'pending'   => $rows['pending']   ?? 0,
            'sold'      => $rows['sold']      ?? 0,
            'total'     => array_sum($rows),
        ];
    }

    /**
     * Average days from deal created to delivered, for the given period.
     *
     * @return array{avg_days: float|null, sample_size: int, period: string}
     */
    public function timeToClose(Dealer $dealer, string $period = '30d'): array
    {
        [$start] = $this->periodDates($period);

        $row = Deal::forDealer($dealer->id)
            ->withoutTrashed()
            ->where('status', 'delivered')
            ->where('created_at', '>=', $start)
            ->select(
                DB::raw($this->avgDaysToCloseExpression() . ' as avg_days'),
                DB::raw('COUNT(*) as sample_size'),
            )
            ->first();

        return [
            'avg_days'    => $row && $row->avg_days !== null ? round((float) $row->avg_days, 1) : null,
            'sample_size' => $row ? (int) $row->sample_size : 0,
            'period'      => $period,
        ];
    }

    /**
     * F&I product attach rate — how often each active product appears on a delivered deal.
     *
     * @return list<array{product_id: int, name: string, type: string, attach_count: int, attach_rate: float}>
     */
    public function fiAttachRate(Dealer $dealer, string $period = '30d'): array
    {
        [$start] = $this->periodDates($period);

        $deliveredDeals = Deal::forDealer($dealer->id)
            ->withoutTrashed()
            ->where('status', 'delivered')
            ->where('created_at', '>=', $start)
            ->count();

        if ($deliveredDeals === 0) {
            return [];
        }

        $rows = FiProduct::forDealer($dealer->id)
            ->select(
                'fi_products.id',
                'fi_products.name',
                'fi_products.type',
                DB::raw('COUNT(deal_fi_products.id) as attach_count'),
            )
            ->leftJoin('deal_fi_products', function ($join) use ($dealer, $start) {
                $join->on('deal_fi_products.fi_product_id', '=', 'fi_products.id')
                     ->whereExists(function ($q) use ($dealer, $start) {
                         $q->from('deals')
                           ->whereColumn('deals.id', 'deal_fi_products.deal_id')
                           ->where('deals.dealer_id', $dealer->id)
                           ->where('deals.status', 'delivered')
                           ->where('deals.created_at', '>=', $start)
                           ->whereNull('deals.deleted_at');
                     });
            })
            ->groupBy('fi_products.id', 'fi_products.name', 'fi_products.type')
            ->orderByDesc('attach_count')
            ->get();

        return $rows->map(fn ($r) => [
            'product_id'  => $r->id,
            'name'        => $r->name,
            'type'        => $r->type,
            'attach_count' => (int) $r->attach_count,
            'attach_rate' => $deliveredDeals > 0
                ? round(((int) $r->attach_count / $deliveredDeals) * 100, 1)
                : 0.0,
        ])->all();
    }

    /**
     * Credit approval rate overall and broken down by lender (deal->lender field).
     *
     * @return array{
     *   period: string,
     *   total_apps: int,
     *   approved: int,
     *   declined: int,
     *   approval_rate: float,
     *   by_lender: list<array{lender: string, approved: int, declined: int, rate: float}>
     * }
     */
    public function creditApprovalRate(Dealer $dealer, string $period = '30d'): array
    {
        [$start] = $this->periodDates($period);

        $apps = CreditApplication::whereHas('deal', fn ($q) =>
                $q->where('dealer_id', $dealer->id)
                  ->where('created_at', '>=', $start)
                  ->whereNull('deleted_at')
            )
            ->whereNotNull('decision')
            ->select('decision', DB::raw('COUNT(*) as total'))
            ->groupBy('decision')
            ->pluck('total', 'decision')
            ->toArray();

        $approved = (int) ($apps['approved'] ?? 0);
        $declined = (int) ($apps['declined'] ?? 0);
        $total    = $approved + $declined;

        // By lender — uses deals.lender field which is set when credit is approved
        $byLender = Deal::forDealer($dealer->id)
            ->withoutTrashed()
            ->where('created_at', '>=', $start)
            ->whereNotNull('lender')
            ->select(
                'lender',
                DB::raw("SUM(CASE WHEN status NOT IN ('credit_declined','cancelled') THEN 1 ELSE 0 END) as approved"),
                DB::raw("SUM(CASE WHEN status = 'credit_declined' THEN 1 ELSE 0 END) as declined"),
            )
            ->groupBy('lender')
            ->orderByDesc('approved')
            ->get()
            ->map(fn ($r) => [
                'lender'   => $r->lender,
                'approved' => (int) $r->approved,
                'declined' => (int) $r->declined,
                'rate'     => ($r->approved + $r->declined) > 0
                    ? round(((int) $r->approved / ($r->approved + $r->declined)) * 100, 1)
                    : 0.0,
            ])
            ->all();

        return [
            'period'        => $period,
            'total_apps'    => $total,
            'approved'      => $approved,
            'declined'      => $declined,
            'approval_rate' => $total > 0 ? round(($approved / $total) * 100, 1) : 0.0,
            'by_lender'     => $byLender,
        ];
    }

    /**
     * Platform-wide KPI summary for the super admin dashboard.
     *
     * @return array<string, mixed>
     */
    public function summaryGlobal(string $period = '30d'): array
    {
        [$start, $prevStart, $prevEnd] = $this->periodDates($period);

        $base = Deal::query()->withoutTrashed();

        $current = (clone $base)->where('created_at', '>=', $start);
        $prev    = (clone $base)->whereBetween('created_at', [$prevStart, $prevEnd]);

        $totalDeals      = (clone $current)->count();
        $prevTotalDeals  = (clone $prev)->count();

        $closedDeals     = (clone $current)->where('status', 'delivered')->count();
        $prevClosedDeals = (clone $prev)->where('status', 'delivered')->count();

        $revenue         = (clone $current)->where('status', 'delivered')->sum('sale_price');
        $prevRevenue     = (clone $prev)->where('status', 'delivered')->sum('sale_price');

        $fiIncome        = (clone $current)->where('status', 'delivered')->sum('total_fi_income');
        $prevFiIncome    = (clone $prev)->where('status', 'delivered')->sum('total_fi_income');

        $pendingCredit   = (clone $base)->where('status', 'credit_submitted')->count();
        $avgSalePrice    = (clone $current)->where('status', 'delivered')->avg('sale_price') ?? 0;

        $activeDealers = Dealer::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->count();

        $auditEvents24h = ActivityLog::query()
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return [
            'period'                => $period,
            'period_start'          => $start->toDateString(),
            'total_deals'           => $totalDeals,
            'total_deals_delta'     => $this->delta($totalDeals, $prevTotalDeals),
            'closed_deals'          => $closedDeals,
            'closed_deals_delta'    => $this->delta($closedDeals, $prevClosedDeals),
            'revenue_cents'         => (int) $revenue,
            'revenue_delta'         => $this->delta((int) $revenue, (int) $prevRevenue),
            'fi_income_cents'       => (int) $fiIncome,
            'fi_income_delta'       => $this->delta((int) $fiIncome, (int) $prevFiIncome),
            'pending_credit'        => $pendingCredit,
            'avg_sale_price_cents'  => (int) $avgSalePrice,
            'active_dealers'        => $activeDealers,
            'audit_events_24h'      => $auditEvents24h,
        ];
    }

    /**
     * Platform-wide monthly trend for trailing 12 months.
     *
     * @return list<array{month: string, deals: int, revenue_cents: int, fi_income_cents: int}>
     */
    public function monthlyTrendGlobal(): array
    {
        $start = now()->subMonths(11)->startOfMonth();
        $monthExpr = $this->monthBucketExpression();

        $rows = Deal::query()
            ->withoutTrashed()
            ->where('created_at', '>=', $start)
            ->select(
                DB::raw("{$monthExpr} as month"),
                DB::raw('COUNT(*) as deals'),
                DB::raw("SUM(CASE WHEN status = 'delivered' THEN sale_price ELSE 0 END) as revenue_cents"),
                DB::raw("SUM(CASE WHEN status = 'delivered' THEN total_fi_income ELSE 0 END) as fi_income_cents"),
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $key = now()->subMonths($i)->format('Y-m');
            $row = $rows->get($key);
            $months[] = [
                'month'           => $key,
                'deals'           => $row ? (int) $row->deals : 0,
                'revenue_cents'   => $row ? (int) $row->revenue_cents : 0,
                'fi_income_cents' => $row ? (int) $row->fi_income_cents : 0,
            ];
        }

        return $months;
    }

    /**
     * Top dealers by closed deals and revenue.
     *
     * @return list<array{dealer_id: int, dealer_name: string, deals: int, revenue_cents: int, close_rate: float}>
     */
    public function topDealers(int $limit = 10): array
    {
        return Deal::query()
            ->withoutTrashed()
            ->join('dealers', 'dealers.id', '=', 'deals.dealer_id')
            ->select(
                'deals.dealer_id',
                'dealers.name as dealer_name',
                DB::raw("SUM(CASE WHEN deals.status = 'delivered' THEN 1 ELSE 0 END) as deals"),
                DB::raw("SUM(CASE WHEN deals.status = 'delivered' THEN deals.sale_price ELSE 0 END) as revenue_cents"),
                DB::raw('COUNT(*) as total_deals')
            )
            ->groupBy('deals.dealer_id', 'dealers.name')
            ->orderByDesc('deals')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'dealer_id'     => (int) $r->dealer_id,
                'dealer_name'   => $r->dealer_name,
                'deals'         => (int) $r->deals,
                'revenue_cents' => (int) $r->revenue_cents,
                'close_rate'    => (int) $r->total_deals > 0
                    ? round(((int) $r->deals / (int) $r->total_deals) * 100, 1)
                    : 0.0,
            ])
            ->all();
    }

    /**
     * Daily audit activity counts for the trailing N days.
     *
     * @return list<array{date: string, total: int, sensitive: int}>
     */
    public function auditActivity(int $days = 14): array
    {
        $start = now()->subDays($days - 1)->startOfDay();
        $dateExpr = $this->dateBucketExpression();
        $sensitiveEvents = [
            'deal.status_changed',
            'document.uploaded',
            'document.downloaded',
            'user.login',
            'user.logout',
            'staff.invited',
            'deal.cancelled',
        ];

        $rows = ActivityLog::query()
            ->where('created_at', '>=', $start)
            ->select(
                DB::raw("{$dateExpr} as date"),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN event IN ('" . implode("','", $sensitiveEvents) . "') THEN 1 ELSE 0 END) as sensitive"),
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $points = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $row = $rows->get($date);
            $points[] = [
                'date'      => $date,
                'total'     => $row ? (int) $row->total : 0,
                'sensitive' => $row ? (int) $row->sensitive : 0,
            ];
        }

        return $points;
    }

    /**
     * Workflow automation activity overview for a single dealer.
     *
     * @return array{
     *   period_days: int,
     *   total_events: int,
     *   reminders: int,
     *   escalations: int,
     *   next_steps: int,
     *   unique_deals_touched: int,
     *   top_events: list<array{event: string, total: int}>,
     *   daily: list<array{date: string, total: int}>
     * }
     */
    public function workflowAutomationOverviewForDealer(Dealer $dealer, int $days = 14): array
    {
        $start = now()->subDays($days - 1)->startOfDay();
        $dateExpr = $this->dateBucketExpression();

        $base = ActivityLog::query()
            ->where('dealer_id', $dealer->id)
            ->where('created_at', '>=', $start)
            ->where('event', 'like', 'workflow.%');

        $totalEvents = (clone $base)->count();
        $reminders = (clone $base)->where('event', 'like', 'workflow.reminder.%')->count();
        $escalations = (clone $base)->where('event', 'like', 'workflow.escalation.%')->count();
        $nextSteps = (clone $base)->where('event', 'like', 'workflow.next_step.%')->count();

        $uniqueDealsTouched = (clone $base)
            ->where('model_type', Deal::class)
            ->whereNotNull('model_id')
            ->distinct('model_id')
            ->count('model_id');

        $topEvents = (clone $base)
            ->select('event', DB::raw('COUNT(*) as total'))
            ->groupBy('event')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'event' => $row->event,
                'total' => (int) $row->total,
            ])
            ->all();

        $dailyRows = (clone $base)
            ->select(
                DB::raw("{$dateExpr} as date"),
                DB::raw('COUNT(*) as total'),
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $daily = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $row = $dailyRows->get($date);
            $daily[] = [
                'date' => $date,
                'total' => $row ? (int) $row->total : 0,
            ];
        }

        return [
            'period_days' => $days,
            'total_events' => $totalEvents,
            'reminders' => $reminders,
            'escalations' => $escalations,
            'next_steps' => $nextSteps,
            'unique_deals_touched' => $uniqueDealsTouched,
            'top_events' => $topEvents,
            'daily' => $daily,
        ];
    }

    /**
     * Workflow automation activity overview for super admins.
     *
     * @return array{
     *   period_days: int,
     *   total_events: int,
     *   reminders: int,
     *   escalations: int,
     *   next_steps: int,
     *   unique_deals_touched: int,
     *   top_events: list<array{event: string, total: int}>,
     *   daily: list<array{date: string, total: int}>
     * }
     */
    public function workflowAutomationOverview(int $days = 14): array
    {
        $start = now()->subDays($days - 1)->startOfDay();
        $dateExpr = $this->dateBucketExpression();

        $base = ActivityLog::query()
            ->where('created_at', '>=', $start)
            ->where('event', 'like', 'workflow.%');

        $totalEvents = (clone $base)->count();
        $reminders = (clone $base)->where('event', 'like', 'workflow.reminder.%')->count();
        $escalations = (clone $base)->where('event', 'like', 'workflow.escalation.%')->count();
        $nextSteps = (clone $base)->where('event', 'like', 'workflow.next_step.%')->count();

        $uniqueDealsTouched = (clone $base)
            ->where('model_type', Deal::class)
            ->whereNotNull('model_id')
            ->distinct('model_id')
            ->count('model_id');

        $topEvents = (clone $base)
            ->select('event', DB::raw('COUNT(*) as total'))
            ->groupBy('event')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'event' => $row->event,
                'total' => (int) $row->total,
            ])
            ->all();

        $dailyRows = (clone $base)
            ->select(
                DB::raw("{$dateExpr} as date"),
                DB::raw('COUNT(*) as total'),
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $daily = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $row = $dailyRows->get($date);
            $daily[] = [
                'date' => $date,
                'total' => $row ? (int) $row->total : 0,
            ];
        }

        return [
            'period_days' => $days,
            'total_events' => $totalEvents,
            'reminders' => $reminders,
            'escalations' => $escalations,
            'next_steps' => $nextSteps,
            'unique_deals_touched' => $uniqueDealsTouched,
            'top_events' => $topEvents,
            'daily' => $daily,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * @return array{Carbon, Carbon, Carbon}
     */
    private function periodDates(string $period): array
    {
        $start = match ($period) {
            '7d'  => now()->subDays(7)->startOfDay(),
            '90d' => now()->subDays(90)->startOfDay(),
            '1y'  => now()->subYear()->startOfDay(),
            default => now()->subDays(30)->startOfDay(), // 30d
        };

        $length    = now()->diffInSeconds($start);
        $prevEnd   = (clone $start)->subSecond();
        $prevStart = (clone $prevEnd)->subSeconds($length);

        return [$start, $prevStart, $prevEnd];
    }

    /**
     * Calculate percentage delta, capped at ±999 to avoid inf.
     */
    private function delta(int|float $current, int|float $previous): float|null
    {
        if ($previous == 0) {
            return $current > 0 ? null : 0.0; // null means "new" (∞)
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Returns SQL expression for grouping timestamps by year-month per DB driver.
     */
    private function monthBucketExpression(): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => "strftime('%Y-%m', created_at)",
            'pgsql' => "to_char(created_at, 'YYYY-MM')",
            'sqlsrv' => "FORMAT(created_at, 'yyyy-MM')",
            default => "DATE_FORMAT(created_at, '%Y-%m')",
        };
    }

    /**
     * Returns SQL expression for grouping timestamps by date.
     */
    private function dateBucketExpression(): string
    {
        return match (DB::getDriverName()) {
            'sqlsrv' => 'CAST(created_at AS date)',
            default => 'DATE(created_at)',
        };
    }

    /**
     * Returns SQL expression for average days from created_at to updated_at.
     */
    private function avgDaysToCloseExpression(): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => "AVG((julianday(updated_at) - julianday(created_at)))",
            'pgsql' => 'AVG(EXTRACT(EPOCH FROM (updated_at - created_at)) / 86400)',
            'sqlsrv' => 'AVG(CAST(DATEDIFF(HOUR, created_at, updated_at) AS FLOAT) / 24.0)',
            default => 'AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) / 24',
        };
    }
}
