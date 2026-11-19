<?php

namespace App\Models;

use App\Enums\LoyaltyCardStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['customer_name', 'phone_number', 'email'])]
class LoyaltyCustomer extends Model
{
    protected static function booted(): void
    {
        // The code is derived from the auto-increment id, which only exists
        // after the insert. Callers create customers inside a transaction,
        // so no one ever sees a customer without a code.
        static::created(function (LoyaltyCustomer $customer): void {
            $customer->customer_code = sprintf(
                '%s-%06d',
                config('loyalty.customer_code_prefix'),
                $customer->getKey(),
            );
            $customer->saveQuietly();
        });
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
}
