<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryAppointment extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'deal_id',
        'dealer_id',
        'type',
        'scheduled_at',
        'address',
        'driver_id',
        'status',
        'notes',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'scheduled_at' => 'datetime',
        'address'      => 'array',
    ];

    /** @return BelongsTo<Deal, DeliveryAppointment> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<Dealer, DeliveryAppointment> */
    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    /** @return BelongsTo<User, DeliveryAppointment> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
