<?php

namespace App\Console\Commands;

use App\Models\LoyaltyCard;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Closes every card past its validity (LoyaltyCard::expireOverdue()).
 * Scheduled hourly, so the database shows them Expired even before the
 * customer comes back; stamping, redeeming and the card page close them
 * on the spot anyway.
 */
#[Signature('loyalty:expire-cards')]
#[Description('Tandai kartu stamp yang sudah lewat masa berlakunya sebagai kedaluwarsa')]
class ExpireLoyaltyCards extends Command
{
    public function handle(): int
    {
        $expired = LoyaltyCard::expireOverdue();

        $this->info("{$expired} kartu stamp kedaluwarsa.");

        return self::SUCCESS;
    }
}
