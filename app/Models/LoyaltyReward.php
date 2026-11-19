<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * stamps_required links a row to its entry in config('loyalty.rewards').
 */
#[Fillable(['stamps_required', 'reward_name', 'description', 'is_active'])]
class LoyaltyReward extends Model
{
    protected function casts(): array
    {
        return [
            'stamps_required' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<LoyaltyRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(LoyaltyRedemption::class, 'reward_id');
    }
}
