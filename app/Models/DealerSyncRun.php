<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealerSyncRun extends Model
{
    protected $fillable = [
        'public_id',
        'dealer_id',
        'status',
        'archive_missing',
        'total_records',
        'chunk_size',
        'total_jobs',
        'processed_jobs',
        'created',
        'updated',
        'skipped',
        'archived',
        'error_count',
        'errors',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'archive_missing' => 'boolean',
        'errors' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }
}
