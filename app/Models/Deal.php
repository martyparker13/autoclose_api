<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deal extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'dealer_id',
        'vehicle_id',
        'buyer_id',
        'status',
        'sale_price',
        'down_payment',
        'trade_in_value',
        'trade_in_vehicle',
        'finance_amount',
        'apr',
        'term_months',
        'monthly_payment',
        'lender',
        'fi_products',
        'total_fi_income',
        'salesperson_id',
        'source',
        'notes',
        'econtract_pushes',
        'deposit_paid_at',
        'deposit_payment_id',
        'deposit_amount',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'trade_in_vehicle' => 'array',
        'fi_products'      => 'array',
        'econtract_pushes' => 'array',
        'sale_price'       => 'integer',
        'down_payment'     => 'integer',
        'trade_in_value'   => 'integer',
        'finance_amount'   => 'integer',
        'monthly_payment'  => 'integer',
        'total_fi_income'  => 'integer',
        'apr'              => 'float',
    ];

    // ── Scopes ───────────────────────────────────────────────────────────

    /**
     * @param  Builder<Deal>  $query
     * @return Builder<Deal>
     */
    public function scopeForDealer(Builder $query, int $dealerId): Builder
    {
        return $query->where('dealer_id', $dealerId);
    }

    // ── Relationships ────────────────────────────────────────────────────

    /** @return BelongsTo<Dealer, Deal> */
    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    /** @return BelongsTo<Vehicle, Deal> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<User, Deal> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /** @return BelongsTo<User, Deal> */
    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    /** @return HasMany<DealDocument> */
    public function documents(): HasMany
    {
        return $this->hasMany(DealDocument::class);
    }

    /** @return HasOne<CreditApplication> */
    public function creditApplication(): HasOne
    {
        return $this->hasOne(CreditApplication::class);
    }

    /** @return HasOne<TradeInAppraisal> */
    public function tradeInAppraisal(): HasOne
    {
        return $this->hasOne(TradeInAppraisal::class);
    }

    /** @return HasMany<DealFiProduct> */
    public function dealFiProducts(): HasMany
    {
        return $this->hasMany(DealFiProduct::class);
    }

    /** @return HasOne<DeliveryAppointment> */
    public function deliveryAppointment(): HasOne
    {
        return $this->hasOne(DeliveryAppointment::class);
    }

    /** @return HasMany<DealScenario> */
    public function scenarios(): HasMany
    {
        return $this->hasMany(DealScenario::class)->orderBy('label');
    }
}
