<?php

namespace App\Models;

use App\Enums\LoyaltyCardStatus;
use App\Support\MonthlySequence;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Hash;

#[Fillable(['customer_name', 'phone_number'])]
#[Hidden(['pin'])]
class LoyaltyCustomer extends Model
{
    protected static function booted(): void
    {
        // Customer codes read year, month and sequence like card numbers
        // (26110001): the month the customer registered and their place
        // among that month's new customers (MonthlySequence). Callers create
        // customers inside a transaction, which retries a clash.
        static::creating(function (LoyaltyCustomer $customer): void {
            $customer->customer_code ??= MonthlySequence::next(static::query(), 'customer_code');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pin' => 'hashed',
        ];
    }

    /**
     * Until the customer sets their own PIN (pin is NULL), the PIN is the
     * last 4 digits of their number: 6283123555526 -> 5526.
     */
    public function defaultPin(): string
    {
        return substr($this->phone_number, -4);
    }

    public function hasDefaultPin(): bool
    {
        return $this->pin === null;
    }

    public function checkPin(string $pin): bool
    {
        return $this->hasDefaultPin()
            ? hash_equals($this->defaultPin(), $pin)
            : Hash::check($pin, $this->pin);
    }

    /**
     * @param  string  $pin  4 digits, in plain text (stored hashed).
     */
    public function changePin(string $pin): void
    {
        $this->forceFill(['pin' => $pin])->save();
    }

    /**
     * @return HasMany<LoyaltyCard, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(LoyaltyCard::class);
    }

    /**
     * @return HasOne<LoyaltyCard, $this>
     */
    public function activeCard(): HasOne
    {
        return $this->hasOne(LoyaltyCard::class)->where('status', LoyaltyCardStatus::Active);
    }

    /**
     * @return HasMany<LoyaltyRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(LoyaltyRedemption::class);
    }

    /**
     * Every stamp the customer has earned, on all their cards.
     *
     * @return HasManyThrough<LoyaltyStamp, LoyaltyCard, $this>
     */
    public function stamps(): HasManyThrough
    {
        return $this->hasManyThrough(LoyaltyStamp::class, LoyaltyCard::class);
    }
}
