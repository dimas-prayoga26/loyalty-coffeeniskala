<?php

namespace App\Services\Loyalty;

use App\Models\LoyaltyCard;
use App\Models\LoyaltyCustomer;

final readonly class LoyaltyRegistrationResult
{
    public function __construct(
        public LoyaltyCustomer $customer,
        public LoyaltyCard $card,
        public bool $customerCreated,
        public bool $cardCreated,
    ) {}
}
