<?php

namespace Tests\Feature;

use App\Enums\LoyaltyCardStatus;
use App\Models\LoyaltyCard;
use App\Services\Loyalty\LoyaltyRegistrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * loyalty:expire-cards (hourly). Runs on pos_live_raw, rolled back.
 */
class ExpireLoyaltyCardsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_only_open_cards_past_their_validity_become_expired(): void
    {
        $this->travelTo('2026-05-01 08:00:00');

        $overdue = $this->card('0800-0000-0901', LoyaltyCardStatus::Active, '2026-04-30 23:59:59');
        $overdueFull = $this->card('0800-0000-0902', LoyaltyCardStatus::Completed, '2026-04-30 23:59:59');
        $lastDay = $this->card('0800-0000-0903', LoyaltyCardStatus::Active, '2026-05-01 23:59:59');
        $noStampsYet = $this->card('0800-0000-0904', LoyaltyCardStatus::Active, null);
        $allRedeemed = $this->card('0800-0000-0905', LoyaltyCardStatus::Redeemed, '2026-04-30 23:59:59');

        $this->artisan('loyalty:expire-cards')->assertSuccessful();

        $this->assertSame(LoyaltyCardStatus::Expired, $overdue->refresh()->status);
        $this->assertSame(LoyaltyCardStatus::Expired, $overdueFull->refresh()->status);
        $this->assertSame(LoyaltyCardStatus::Active, $lastDay->refresh()->status);
        $this->assertSame(LoyaltyCardStatus::Active, $noStampsYet->refresh()->status);
        $this->assertSame(LoyaltyCardStatus::Redeemed, $allRedeemed->refresh()->status);
    }

    private function card(string $phone, LoyaltyCardStatus $status, ?string $expiresAt): LoyaltyCard
    {
        $card = app(LoyaltyRegistrationService::class)->registerOrRetrieve($phone, null)->card;
        $card->forceFill(['status' => $status, 'expires_at' => $expiresAt])->save();

        return $card;
    }
}
