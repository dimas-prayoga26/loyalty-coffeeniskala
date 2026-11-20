<?php

namespace App\Models;

use App\Models\Pos\PosOrder;
use App\Models\Pos\PosUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * loyalty_customer_id duplicates the card's customer; whatever writes a
 * redemption must keep the two consistent.
 */
#[Fillable([
    'loyalty_customer_id', 'loyalty_card_id', 'reward_id',
    'order_id', 'menu_item_id', 'menu_name',
    'redeemed_by', 'redeemed_at',
])]
class LoyaltyRedemption extends Model
{
    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
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
     * @return BelongsTo<LoyaltyCard, $this>
     */
    public function card(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCard::class, 'loyalty_card_id');
    }

    /**
     * @return BelongsTo<LoyaltyReward, $this>
     */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(LoyaltyReward::class, 'reward_id');
    }

    /**
     * The POS order the reward went out with (null: redeemed outside an order).
     *
     * @return BelongsTo<PosOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'order_id');
    }

    /**
     * @return BelongsTo<PosUser, $this>
     */
    public function redeemedBy(): BelongsTo
    {
        return $this->belongsTo(PosUser::class, 'redeemed_by');
    }
}
