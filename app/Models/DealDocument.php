<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealDocument extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'deal_id',
        'type',
        'docusign_envelope_id',
        'docusign_status',
        's3_path',
        'uploaded_by',
        'signed_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'signed_at' => 'datetime',
    ];

    /** @return BelongsTo<Deal, DealDocument> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<User, DealDocument> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
