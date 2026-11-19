<?php

namespace App\Models;

use App\Enums\LoyaltyCardStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * active_flag is a generated column (database-maintained); never set it.
 */
#[Fillable(['loyalty_customer_id', 'card_number', 'total_stamps', 'status'])]
#[Hidden(['active_flag'])]
class LoyaltyCard extends Model
{
    protected $attributes = [
        'total_stamps' => 0,
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'total_stamps' => 'integer',
            'status' => LoyaltyCardStatus::class,
        ];
    }

    /**
     * @return BelongsTo<LoyaltyCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCustomer::class, 'loyalty_customer_id');
    }

    /**
     * @return HasMany<LoyaltyStamp, $this>
     */
    public function stamps(): HasMany
    {
        return $this->hasMany(LoyaltyStamp::class);
    }

    /**
     * @return HasMany<LoyaltyRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(LoyaltyRedemption::class);
    }
}
