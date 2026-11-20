<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reward of the stamp card: unlocked at stamps_required, managed from the
 * POS (Pengaturan → Program Promo). short_name: one word for its card slot;
 * promo_menu_item_id: the POS Promo loyalty menu Tukar gives for it.
 */
#[Fillable(['stamps_required', 'reward_name', 'short_name', 'promo_menu_item_id', 'description', 'is_active'])]
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
