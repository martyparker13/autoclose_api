<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleFeature extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'vehicle_id',
        'category',
        'feature_name',
    ];

    /** @return BelongsTo<Vehicle, VehicleFeature> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
