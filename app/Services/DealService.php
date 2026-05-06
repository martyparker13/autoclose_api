<?php

namespace App\Services;

use App\Events\DealStatusChanged;
use App\Models\ActivityLog;
use App\Models\Deal;
use App\Models\Dealer;
use App\Models\FiProduct;
use App\Models\User;
use App\Repositories\DealRepositoryInterface;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Validation\ValidationException;

class DealService
{
    public function __construct(
        private readonly DealRepositoryInterface $deals,
    ) {}

    // ── Read ─────────────────────────────────────────────────────────────

    /**
     * List deals for a dealer (dealer staff / admin view).
     *
     * @param  array<string, mixed>  $filters
     */
    public function listForDealer(Dealer $dealer, array $filters = []): CursorPaginator
    {
        return $this->deals->paginateForDealer($dealer->id, $filters);
    }

    /**
     * List deals for the authenticated buyer.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listForBuyer(User $buyer, array $filters = []): CursorPaginator
    {
        return $this->deals->paginateForBuyer($buyer->id, $filters);
    }

    /**
     * Fetch a deal for dealer staff/admin.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getForDealer(int $dealId, Dealer $dealer): Deal
    {
        return $this->deals->findForDealer($dealId, $dealer->id);
    }

    /**
     * Fetch a deal for the owning buyer.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getForBuyer(int $dealId, User $buyer): Deal
    {
        return $this->deals->findForBuyer($dealId, $buyer->id);
    }

    // ── Write ────────────────────────────────────────────────────────────

    /**
     * Open a new deal for a buyer on a specific vehicle.
     *
     * Enforces:
     *  - vehicle belongs to the dealer
     *  - vehicle is available
     *  - no existing active draft deal for the same buyer+vehicle
     *
     * @param  array<string, mixed>  $data  Validated input from StoreDealRequest
     * @throws ValidationException
     */
    public function open(Dealer $dealer, User $buyer, array $data): Deal
    {
        $vehicleId = $data['vehicle_id'];

        // Verify vehicle belongs to this dealer and is available
        $vehicle = $dealer->vehicles()->where('id', $vehicleId)->firstOrFail();

        if ($vehicle->status !== 'available') {
            throw ValidationException::withMessages([
                'vehicle_id' => ['This vehicle is not currently available.'],
            ]);
        }

        // Prevent duplicate active drafts
        $existing = Deal::where('buyer_id', $buyer->id)
            ->where('vehicle_id', $vehicleId)
            ->whereIn('status', ['draft', 'credit_submitted', 'credit_approved', 'docs_pending', 'docs_signed'])
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'vehicle_id' => ['You already have an active deal for this vehicle.'],
            ]);
        }

        $deal = $this->deals->create([
            'dealer_id'  => $dealer->id,
            'vehicle_id' => $vehicleId,
            'buyer_id'   => $buyer->id,
            'status'     => 'draft',
            'sale_price' => $data['sale_price'] ?? $vehicle->internet_price ?? $vehicle->price,
            'down_payment' => $data['down_payment'] ?? 0,
            'source'     => $data['source'] ?? 'web',
        ]);

        ActivityLog::record('deal.opened', $deal, [], ['status' => 'draft']);

        return $deal;
    }

    /**
     * Update deal financial terms (down payment, term, etc.).
     * Recalculates monthly payment if finance fields are present.
     *
     * @param  array<string, mixed>  $data  Validated input from UpdateDealRequest
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function updateTerms(Deal $deal, Dealer $dealer, array $data): Deal
    {
        $this->assertDealerOwns($deal, $dealer);
        $this->assertDealEditable($deal);

        $old = $deal->only(['sale_price', 'down_payment', 'term_months', 'apr', 'monthly_payment']);

        // Auto-calculate monthly payment when all finance fields are provided
        if (
            isset($data['sale_price'], $data['down_payment'], $data['term_months'], $data['apr'])
            || (isset($data['term_months']) || isset($data['apr']))
        ) {
            $salePrice   = $data['sale_price']   ?? $deal->sale_price;
            $downPayment = $data['down_payment']  ?? $deal->down_payment;
            $tradeValue  = $data['trade_in_value'] ?? $deal->trade_in_value;
            $termMonths  = $data['term_months']   ?? $deal->term_months;
            $apr         = $data['apr']           ?? $deal->apr;

            if ($termMonths && $apr !== null) {
                $data['finance_amount']  = max(0, $salePrice - $downPayment - $tradeValue);
                $data['monthly_payment'] = $this->calculateMonthlyPayment(
                    (int) $data['finance_amount'],
                    (float) $apr,
                    (int) $termMonths,
                );
            }
        }

        $updated = $this->deals->update($deal, $data);

        ActivityLog::record('deal.terms_updated', $updated, $old, $updated->only(array_keys($old)));

        return $updated;
    }

    /**
     * Advance a deal to the next status (dealer staff/admin only).
     *
     * @throws ValidationException
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function transition(Deal $deal, Dealer $dealer, string $newStatus): Deal
    {
        $this->assertDealerOwns($deal, $dealer);

        $allowed = $this->allowedTransitions($deal->status);

        if (! in_array($newStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition from '{$deal->status}' to '{$newStatus}'."],
            ]);
        }

        $old     = $deal->status;
        $updated = $this->deals->update($deal, ['status' => $newStatus]);

        ActivityLog::record('deal.status_changed', $updated, ['status' => $old], ['status' => $newStatus]);

        DealStatusChanged::dispatch($updated, $old, $newStatus);

        return $updated;
    }

    /**
     * Sync F&I products onto a deal (replaces existing selection).
     * Requires fi_products_enabled feature flag.
     *
     * @param  list<array{fi_product_id: int, price: int}>  $products
     * @throws ValidationException
     */
    public function syncFiProducts(Deal $deal, Dealer $dealer, array $products): Deal
    {
        $this->assertDealerOwns($deal, $dealer);
        $this->assertDealEditable($deal);
        $this->assertFeature($dealer, 'fi_products_enabled');

        // Validate all product IDs belong to this dealer
        $ids         = array_column($products, 'fi_product_id');
        $validIds    = FiProduct::where('dealer_id', $dealer->id)
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $invalidIds = array_diff($ids, $validIds);
        if ($invalidIds) {
            throw ValidationException::withMessages([
                'products' => ['One or more F&I products are invalid or inactive.'],
            ]);
        }

        // Replace pivot rows
        $deal->dealFiProducts()->delete();

        $totalFiIncome = 0;
        foreach ($products as $item) {
            $product = FiProduct::find($item['fi_product_id']);
            $price   = $item['price'] ?? $product->price;
            $deal->dealFiProducts()->create([
                'fi_product_id' => $item['fi_product_id'],
                'price'         => $price,
                'cost'          => $product->cost,
            ]);
            $totalFiIncome += $price - $product->cost;
        }

        $updated = $this->deals->update($deal, ['total_fi_income' => max(0, $totalFiIncome)]);

        ActivityLog::record('deal.fi_products_synced', $updated, [], ['product_ids' => $ids]);

        return $updated;
    }

    /**
     * Cancel (soft-delete) a deal.
     */
    public function cancel(Deal $deal, Dealer $dealer): void
    {
        $this->assertDealerOwns($deal, $dealer);

        ActivityLog::record('deal.cancelled', $deal, ['status' => $deal->status], []);

        $this->deals->update($deal, ['status' => 'cancelled']);
        $this->deals->delete($deal);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Calculate monthly loan payment (standard amortization formula).
     *
     * @param  int    $principalCents  Finance amount in cents
     * @param  float  $aprPercent      Annual percentage rate (e.g. 6.99)
     * @param  int    $termMonths
     * @return int  Monthly payment in cents
     */
    public function calculateMonthlyPayment(int $principalCents, float $aprPercent, int $termMonths): int
    {
        if ($principalCents <= 0 || $termMonths <= 0) {
            return 0;
        }

        $monthlyRate = ($aprPercent / 100) / 12;

        if ($monthlyRate === 0.0) {
            return (int) round($principalCents / $termMonths);
        }

        $factor  = pow(1 + $monthlyRate, $termMonths);
        $payment = $principalCents * ($monthlyRate * $factor) / ($factor - 1);

        return (int) round($payment);
    }

    /**
     * @return list<string>
     */
    private function allowedTransitions(string $current): array
    {
        return match ($current) {
            'draft'             => ['credit_submitted', 'cancelled'],
            'credit_submitted'  => ['credit_approved', 'credit_declined', 'cancelled'],
            'credit_approved'   => ['docs_pending', 'cancelled'],
            'credit_declined'   => ['draft', 'cancelled'],
            'docs_pending'      => ['docs_signed', 'cancelled'],
            'docs_signed'       => ['awaiting_delivery', 'cancelled'],
            'awaiting_delivery' => ['delivered', 'cancelled'],
            default             => [],
        };
    }

    private function assertDealerOwns(Deal $deal, Dealer $dealer): void
    {
        if ($deal->dealer_id !== $dealer->id) {
            abort(403, 'Deal does not belong to this dealer.');
        }
    }

    private function assertDealEditable(Deal $deal): void
    {
        if (! in_array($deal->status, ['draft', 'credit_approved'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Deal cannot be modified in its current status.'],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertFeature(Dealer $dealer, string $flag): void
    {
        if (! $dealer->hasFeature($flag)) {
            throw ValidationException::withMessages([
                $flag => ["This feature is not enabled for this dealer."],
            ]);
        }
    }
}
