<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationWebhookEvent extends Model
{
    protected $fillable = [
        'provider',
        'event_key',
        'payload_hash',
        'status',
        'delivery_count',
        'payload',
        'error',
        'first_seen_at',
        'last_seen_at',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
