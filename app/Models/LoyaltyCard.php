<?php

namespace App\Models;

use App\Enums\LoyaltyCardStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * active_flag is a generated column (database-maintained); never set it.
 *
 * expires_at is set when the card's first stamp is issued
 * (expiryAfterFirstStamp()); NULL while the card has no stamps.
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
            'expires_at' => 'datetime',
        ];
    }

    /**
     * A card is valid for loyalty.card_valid_months months from the moment
     * its first stamp was issued (the stamp's created_at, not the order date
     * it carries), through the end of that day. A month without that date
     * ends on its last day: 30 November + 3 months = 28 February.
     */
    public static function expiryAfterFirstStamp(CarbonInterface $firstStampIssuedAt): Carbon
    {
        return Carbon::instance($firstStampIssuedAt)
            ->addMonthsNoOverflow((int) config('loyalty.card_valid_months'))
            ->endOfDay();
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
