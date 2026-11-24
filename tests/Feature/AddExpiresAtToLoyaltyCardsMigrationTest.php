<?php

namespace Tests\Feature;

use App\Models\LoyaltyCard;
use App\Services\Loyalty\LoyaltyRegistrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 2026_11_24_094448_add_expires_at_to_loyalty_cards_table fills expires_at
 * for cards stamped before the column existed. Runs on pos_live_raw,
 * rolled back.
 */
class AddExpiresAtToLoyaltyCardsMigrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cards_with_stamps_get_three_months_from_their_first_stamps_issue(): void
    {
        $stamped = $this->card('0800-0000-0801', ['2026-11-30 08:00:00', '2026-12-02 09:30:00']);
        $unstamped = $this->card('0800-0000-0802', []);
        $alreadySet = $this->card('0800-0000-0803', ['2026-01-10 10:00:00']);
        $alreadySet->forceFill(['expires_at' => '2026-12-31 23:59:59'])->save();

        $this->migration()->backfill();

        // 30 November + 3 months: February has no 30th, so its last day.
        $this->assertSame('2027-02-28 23:59:59', $stamped->refresh()->expires_at->format('Y-m-d H:i:s'));
        $this->assertNull($unstamped->refresh()->expires_at);
        $this->assertSame('2026-12-31 23:59:59', $alreadySet->refresh()->expires_at->format('Y-m-d H:i:s'));
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_24_094448_add_expires_at_to_loyalty_cards_table.php');
    }

    /**
     * A customer's card with stamps issued at the given times, and no expiry
     * yet. Each stamp carries a date a month before its issue, which must not
     * count.
     *
     * @param  list<string>  $issuedAt
     */
    private function card(string $phone, array $issuedAt): LoyaltyCard
    {
        $card = app(LoyaltyRegistrationService::class)->registerOrRetrieve($phone, null)->card;

        foreach ($issuedAt as $i => $at) {
            $this->travelTo($at);
            $card->stamps()->create(['stamp_number' => $i + 1, 'stamped_at' => now()->subMonth()]);
        }
        $this->travelBack();
        $card->update(['total_stamps' => count($issuedAt)]);

        return $card;
    }
}
