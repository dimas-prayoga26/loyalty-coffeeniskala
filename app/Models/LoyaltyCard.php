<?php

namespace App\Models;

use App\Enums\LoyaltyCardStatus;
use App\Support\MonthlySequence;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * active_flag is a generated column (database-maintained); never set it.
 *
 * expires_at is set when the card's first stamp is issued
 * (expiryAfterFirstStamp()); NULL while the card has no stamps. Past it,
 * the card becomes Expired (expireOverdue()): its stamps start again from 0
 * on a new card with the next stamp, which gets a validity of its own, and
 * rewards still on it can no longer be handed over.
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
     * Card numbers read month-year-sequence (11-2026-001): the month the card
     * was issued and its place among that month's cards (MonthlySequence).
     * Called inside the registration transaction, which retries a clash.
     */
    public static function nextCardNumber(?CarbonInterface $issuedAt = null): string
    {
        return MonthlySequence::next(static::query(), 'card_number', $issuedAt);
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
     * Closes the Active and Completed cards whose validity has passed: they
     * become Expired. Called wherever cards are read or changed (stamps,
     * redemptions, the card page, vouchers) and every hour
     * (loyalty:expire-cards), so a card is closed from the first moment
     * after its expires_at whoever looks at it first.
     *
     * @param  int|null  $customerId  Only this customer's cards; null for all.
     * @return int The number of cards that expired now.
     */
    public static function expireOverdue(?int $customerId = null): int
    {
        return static::query()
            ->whereIn('status', [LoyaltyCardStatus::Active->value, LoyaltyCardStatus::Completed->value])
            ->where('expires_at', '<', now())
            ->when($customerId !== null, fn (Builder $query) => $query->where('loyalty_customer_id', $customerId))
            ->update(['status' => LoyaltyCardStatus::Expired->value]);
    }

    /**
     * Past its validity, whether or not its status says so yet.
     */
    public function isOverdue(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
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
