<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Vehicle extends Model
{
    use HasFactory;
    use Searchable;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'dealer_id',
        'vin',
        'stock_number',
        'year',
        'make',
        'model',
        'trim',
        'body_style',
        'exterior_color',
        'interior_color',
        'mileage',
        'condition',
        'price',
        'msrp',
        'internet_price',
        'transmission',
        'engine',
        'drivetrain',
        'fuel_type',
        'doors',
        'cylinders',
        'status',
        'description',
        'carfax_url',
    ];

    /** @var list<string> */
    protected $hidden = ['cost'];

    /** @var array<string, string> */
    protected $casts = [
        'price'          => 'integer',
        'msrp'           => 'integer',
        'internet_price' => 'integer',
        'cost'           => 'integer',
        'mileage'        => 'integer',
        'year'           => 'integer',
        'doors'          => 'integer',
        'cylinders'      => 'integer',
    ];

    // ── Scopes ───────────────────────────────────────────────────────────

    /**
     * @param  Builder<Vehicle>  $query
     * @return Builder<Vehicle>
     */
    public function scopeForDealer(Builder $query, int $dealerId): Builder
    {
        return $query->where('dealer_id', $dealerId);
    }

    /**
     * @param  Builder<Vehicle>  $query
     * @return Builder<Vehicle>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', 'available');
    }

    // ── Relationships ────────────────────────────────────────────────────

    /** @return BelongsTo<Dealer, Vehicle> */
    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    /** @return HasMany<VehicleMedia> */
    public function media(): HasMany
    {
        return $this->hasMany(VehicleMedia::class)->orderBy('sort_order');
    }

    /** @return HasMany<VehicleFeature> */
    public function features(): HasMany
    {
        return $this->hasMany(VehicleFeature::class);
    }

    /** @return HasMany<Deal> */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    // ── Scout ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function toSearchableArray(): array
    {
        return [
            'id'             => $this->id,
            'dealer_id'      => $this->dealer_id,
            'year'           => $this->year,
            'make'           => $this->make,
            'model'          => $this->model,
            'trim'           => $this->trim,
            'vin'            => $this->vin,
            'stock_number'   => $this->stock_number,
            'condition'      => $this->condition,
            'status'         => $this->status,
            'price'          => $this->internet_price ?? $this->price,
            'mileage'        => $this->mileage,
            'exterior_color' => $this->exterior_color,
            'body_style'     => $this->body_style,
        ];
    }
}
