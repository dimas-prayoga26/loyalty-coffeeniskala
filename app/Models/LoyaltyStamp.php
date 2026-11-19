<?php

namespace App\Models;

use App\Models\Pos\PosOrder;
use App\Models\Pos\PosUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * order_id is unique (one stamp per POS order) and becomes NULL if the POS
 * deletes the order; order_code keeps the trace.
 */
#[Fillable(['loyalty_card_id', 'order_id', 'order_code', 'stamp_number', 'created_by', 'stamped_at'])]
class LoyaltyStamp extends Model
{
    protected function casts(): array
    {
        return [
            'stamp_number' => 'integer',
            'stamped_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LoyaltyCard, $this>
     */
    public function card(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCard::class, 'loyalty_card_id');
    }

    /**
     * @return BelongsTo<PosOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'order_id');
    }

    /**
     * @return BelongsTo<PosUser, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(PosUser::class, 'created_by');
    }
}
