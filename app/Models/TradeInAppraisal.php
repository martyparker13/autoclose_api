<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeInAppraisal extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'deal_id',
        'dealer_id',
        'year',
        'make',
        'model',
        'trim',
        'mileage',
        'vin',
        'condition',
        'kbb_value',
        'black_book_value',
        'dealer_offer',
        'accepted',
        'responded_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'accepted'         => 'boolean',
        'responded_at'     => 'datetime',
        'kbb_value'        => 'integer',
        'black_book_value' => 'integer',
        'dealer_offer'     => 'integer',
        'mileage'          => 'integer',
        'year'             => 'integer',
    ];

    /** @return BelongsTo<Deal, TradeInAppraisal> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<Dealer, TradeInAppraisal> */
    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }
}
