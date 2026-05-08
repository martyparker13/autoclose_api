<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealerApiKey extends Model
{
    protected $fillable = [
        'dealer_id',
        'label',
        'key_hash',
        'key_prefix',
        'last_used_at',
        'expires_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
    ];

    /** @return BelongsTo<Dealer, DealerApiKey> */
    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Generate a new raw API key and its stored components.
     *
     * Returns ['raw' => 'ac_...', 'hash' => '...', 'prefix' => 'ac_XXXX']
     *
     * @return array{raw: string, hash: string, prefix: string}
     */
    public static function generate(): array
    {
        $raw    = 'ac_' . bin2hex(random_bytes(24)); // 51 chars total
        $hash   = hash('sha256', $raw);
        $prefix = substr($raw, 0, 8);

        return compact('raw', 'hash', 'prefix');
    }
}
