<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealScenario extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'deal_id',
        'label',
        'term_months',
        'down_payment',
        'sale_price',
        'fi_product_ids',
        'apr',
        'monthly_payment',
        'total_cost',
        'is_selected',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'fi_product_ids'  => 'array',
        'term_months'     => 'integer',
        'down_payment'    => 'integer',
        'sale_price'      => 'integer',
        'monthly_payment' => 'integer',
        'total_cost'      => 'integer',
        'apr'             => 'float',
        'is_selected'     => 'boolean',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    /** @return BelongsTo<Deal, DealScenario> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }
}
