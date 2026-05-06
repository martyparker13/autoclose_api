<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealFiProduct extends Model
{
    use HasFactory;

    protected $table = 'deal_fi_products';

    /** @var list<string> */
    protected $fillable = [
        'deal_id',
        'fi_product_id',
        'price',
        'cost',
    ];

    /** @var list<string> */
    protected $hidden = ['cost'];

    /** @var array<string, string> */
    protected $casts = [
        'price' => 'integer',
        'cost'  => 'integer',
    ];

    /** @return BelongsTo<Deal, DealFiProduct> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<FiProduct, DealFiProduct> */
    public function fiProduct(): BelongsTo
    {
        return $this->belongsTo(FiProduct::class);
    }
}
