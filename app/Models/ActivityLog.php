<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use HasFactory;

    /** @var bool */
    public $timestamps = true;

    /** Audit logs are immutable — disable updated_at. */
    const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'dealer_id',
        'user_id',
        'event',
        'model_type',
        'model_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    /** @return BelongsTo<Dealer, ActivityLog> */
    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    /** @return BelongsTo<User, ActivityLog> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Static helper ────────────────────────────────────────────────────

    /**
     * Write an activity log entry.
     *
     * @param  string  $event          e.g. 'vehicle.created', 'deal.status_changed'
     * @param  Model|null  $subject    The Eloquent model being acted upon
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public static function record(
        string $event,
        ?Model $subject = null,
        array $oldValues = [],
        array $newValues = [],
    ): self {
        /** @var \Illuminate\Http\Request $request */
        $request = app('request');
        $user    = $request->user();
        $dealer  = null;

        try {
            $dealer = app('current_dealer');
        } catch (\Throwable) {
            // Not in a tenant context — e.g. super-admin actions
        }

        return static::create([
            'dealer_id'  => $dealer?->id ?? $user?->dealer_id,
            'user_id'    => $user?->id,
            'event'      => $event,
            'model_type' => $subject ? get_class($subject) : null,
            'model_id'   => $subject?->getKey(),
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}

