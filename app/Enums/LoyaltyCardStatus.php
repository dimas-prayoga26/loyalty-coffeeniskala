<?php

namespace App\Enums;

/**
 * Stored in loyalty_cards.status. Only Active is used so far (registration
 * issues active cards); the transitions to the other statuses belong to
 * the stamp/redemption rules, which are not specified yet.
 *
 * The value 'active' is also hard-coded in the loyalty_cards.active_flag
 * generated column that enforces one active card per customer.
 */
enum LoyaltyCardStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Redeemed = 'redeemed';
    case Expired = 'expired';
}
