<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dealer extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'subdomain',
        'custom_domain',
        'logo_url',
        'primary_color',
        'phone',
        'email',
        'address',
        'city',
        'state',
        'zip',
        'license_number',
        'dms_provider',
        'subscription_plan',
        'subscription_status',
        'stripe_customer_id',
        'stripe_subscription_id',
        'is_active',
        'feature_flags',
        'google_review_url',
        'dealertrack_credentials',
        'routeone_credentials',
    ];

    /** @var list<string> */
    protected $hidden = [
        'dms_credentials',
        'dealertrack_credentials',
        'routeone_credentials',
        'stripe_customer_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'dms_credentials'          => 'encrypted:array',
        'dealertrack_credentials'  => 'encrypted:array',
        'routeone_credentials'     => 'encrypted:array',
        'feature_flags'            => 'array',
        'is_active'                => 'boolean',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    /** @return HasMany<User> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Vehicle> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /** @return HasMany<Deal> */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /** @return HasMany<FiProduct> */
    public function fiProducts(): HasMany
    {
        return $this->hasMany(FiProduct::class);
    }

    /** @return HasMany<DealerSyncRun> */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(DealerSyncRun::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Check whether a feature flag is enabled for this dealer.
     *
     * @param  string  $flag  e.g. 'financing_enabled'
     */
    public function hasFeature(string $flag): bool
    {
        $flags = $this->feature_flags ?? [];

        return (bool) ($flags[$flag] ?? false);
    }
}
