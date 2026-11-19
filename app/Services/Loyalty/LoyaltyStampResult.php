<?php

namespace App\Services\Loyalty;

use App\Models\LoyaltyStamp;

final readonly class LoyaltyStampResult
{
    /**
     * @param  bool  $created  false: the order already had this stamp
     * @param  bool  $customerCreated  the order's phone was new, registered now
     */
    public function __construct(
        public LoyaltyStamp $stamp,
        public bool $created,
        public bool $customerCreated = false,
    ) {}
}
