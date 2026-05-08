<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditApplication extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'deal_id',
        'buyer_id',
        'dob',
        'annual_income',
        'employment_status',
        'employer_name',
        'employer_phone',
        'monthly_housing',
        'housing_status',
        'years_at_employer',
        'credit_score_range',
        'bureau_pull_type',
        'decision',
        'approved_amount',
        'approved_apr',
        'approved_term',
        'submitted_at',
        'decided_at',
        'integration_pushes',
        'pre_qual_result',
    ];

    /** @var list<string> */
    protected $hidden = [
        'ssn_encrypted',
        'bureau_response',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'ssn_encrypted'   => 'encrypted',
        'bureau_response' => 'encrypted:array',
        'dob'             => 'datetime',
        'annual_income'   => 'integer',
        'monthly_housing' => 'integer',
        'approved_amount' => 'integer',
        'approved_apr'    => 'float',
        'submitted_at'        => 'datetime',
        'decided_at'          => 'datetime',
        'integration_pushes'  => 'array',
        'pre_qual_result'     => 'array',
    ];

    /** @return BelongsTo<Deal, CreditApplication> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<User, CreditApplication> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }
}
