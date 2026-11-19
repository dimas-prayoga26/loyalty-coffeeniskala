<?php

namespace App\Models\Pos;

use App\Models\Concerns\ReadOnlyModel;
use App\Models\LoyaltyStamp;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A POS transaction (orders table, owned by the POS). Read-only.
 *
 * order_status is "In Progress" or "Completed" in the POS code. Which
 * statuses make an order eligible for a stamp is a business rule that is
 * not decided yet.
 */
#[Table('orders')]
class PosOrder extends Model
{
    use ReadOnlyModel;

    protected function casts(): array
    {
        return [
            'order_date' => 'datetime',
            'total' => 'decimal:2',
            'online_order_charge' => 'decimal:2',
            'tax' => 'decimal:2',
            'total_with_tax' => 'decimal:2',
        ];
    }

    /**
     * @return HasOne<LoyaltyStamp, $this>
     */
    public function loyaltyStamp(): HasOne
    {
        return $this->hasOne(LoyaltyStamp::class, 'order_id');
    }
}
