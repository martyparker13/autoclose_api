<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Dealer;
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

        $rows = Deal::forDealer($dealer->id)
            ->withoutTrashed()
            ->where('created_at', '>=', $start)
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
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
        return Deal::forDealer($dealer->id)
            ->withoutTrashed()
            ->where('status', 'delivered')
            ->join('vehicles', 'vehicles.id', '=', 'deals.vehicle_id')
            ->select(
                'deals.vehicle_id',
                'vehicles.year',
                'vehicles.make',
                'vehicles.model',
                DB::raw('COUNT(*) as deals'),
                DB::raw('SUM(deals.sale_price) as revenue_cents'),
            )
            ->groupBy('deals.vehicle_id', 'vehicles.year', 'vehicles.make', 'vehicles.model')
            ->orderByDesc('deals')
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
        return Deal::forDealer($dealer->id)
            ->withoutTrashed()
            ->where('status', 'delivered')
            ->whereNotNull('salesperson_id')
            ->join('users', 'users.id', '=', 'deals.salesperson_id')
            ->select(
                'deals.salesperson_id',
                'users.name',
                DB::raw('COUNT(*) as deals'),
                DB::raw('SUM(deals.sale_price) as revenue_cents'),
            )
            ->groupBy('deals.salesperson_id', 'users.name')
            ->orderByDesc('deals')
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
}
