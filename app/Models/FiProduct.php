<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiProduct extends Model
{
    use HasFactory;

    protected $table = 'fi_products';

    /** @var list<string> */
    protected $fillable = [
        'dealer_id',
        'name',
        'type',
        'provider',
        'description',
        'cost',
        'price',
        'term_months',
        'is_active',
    ];

    /** @var list<string> */
    protected $hidden = ['cost'];

    /** @var array<string, string> */
    protected $casts = [
        'cost'       => 'integer',
        'price'      => 'integer',
        'is_active'  => 'boolean',
    ];

    /**
     * @param  Builder<FiProduct>  $query
     * @return Builder<FiProduct>
     */
    public function scopeForDealer(Builder $query, int $dealerId): Builder
    {
        return $query->where('dealer_id', $dealerId);
    }

    /**
     * @param  Builder<FiProduct>  $query
     * @return Builder<FiProduct>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return BelongsTo<Dealer, FiProduct> */
    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }
}
