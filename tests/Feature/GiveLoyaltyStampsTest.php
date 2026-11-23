<?php

namespace Tests\Feature;

use App\Enums\LoyaltyCardStatus;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyCustomer;
use App\Support\WhatsAppNumber;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * loyalty:give-stamps (demo/tes). Runs on pos_live_raw; DatabaseTransactions
 * rolls every write back. 0800 numbers are never a customer's.
 */
class GiveLoyaltyStampsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config(['loyalty.stamps_per_card' => 10]);
        // Whatever LOYALTY_TEST_NUMBERS the local .env holds.
        WhatsAppNumber::allowTestNumbers([]);
    }

    protected function tearDown(): void
    {
        WhatsAppNumber::allowTestNumbers([]);

        parent::tearDown();
    }

    private function cardOf(string $phone): LoyaltyCard
    {
        return LoyaltyCustomer::where('phone_number', $phone)->sole()->cards()->sole();
    }

    public function test_a_new_number_gets_a_card_with_the_stamps_dated_before_today(): void
    {
        $this->artisan('loyalty:give-stamps', ['phone' => '0800-0000-0701', 'stamps' => 5, '--name' => 'Demo Minuman'])
            ->assertSuccessful();

        $this->assertSame('Demo Minuman', LoyaltyCustomer::where('phone_number', '6280000000701')->sole()->customer_name);
        $card = $this->cardOf('6280000000701');
        $this->assertSame(5, $card->total_stamps);
        $this->assertSame(LoyaltyCardStatus::Active, $card->status);

        $stamps = $card->stamps()->orderBy('stamp_number')->get();
        $this->assertSame([1, 2, 3, 4, 5], $stamps->pluck('stamp_number')->all());
        $this->assertTrue($stamps->last()->stamped_at->isYesterday());
        $this->assertSame(5, $stamps->map(fn ($stamp) => $stamp->stamped_at->toDateString())->unique()->count());
    }

    public function test_ten_stamps_fill_the_card(): void
    {
        $this->artisan('loyalty:give-stamps', ['phone' => '0800-0000-0702', 'stamps' => 10])->assertSuccessful();

        $card = $this->cardOf('6280000000702');
        $this->assertSame(10, $card->total_stamps);
        $this->assertSame(LoyaltyCardStatus::Completed, $card->status);
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->artisan('loyalty:give-stamps', ['phone' => '0800-0000-0703', 'stamps' => 10])->assertSuccessful();
        $this->artisan('loyalty:give-stamps', ['phone' => '0800-0000-0703', 'stamps' => 10])->assertSuccessful();
        $this->artisan('loyalty:give-stamps', ['phone' => '0800-0000-0703', 'stamps' => 5])->assertSuccessful();

        $card = $this->cardOf('6280000000703');
        $this->assertSame(10, $card->total_stamps);
        $this->assertSame(10, $card->stamps()->count());
    }

    public function test_an_active_card_is_topped_up(): void
    {
        $this->artisan('loyalty:give-stamps', ['phone' => '0800-0000-0704', 'stamps' => 2])->assertSuccessful();
        $this->artisan('loyalty:give-stamps', ['phone' => '0800-0000-0704', 'stamps' => 5])->assertSuccessful();

        $card = $this->cardOf('6280000000704');
        $this->assertSame(5, $card->total_stamps);
        $this->assertSame([1, 2, 3, 4, 5], $card->stamps()->orderBy('stamp_number')->pluck('stamp_number')->all());
    }

    public function test_a_test_number_is_accepted_only_when_configured(): void
    {
        $this->artisan('loyalty:give-stamps', ['phone' => '0987654321', 'stamps' => 10])->assertFailed();
        $this->assertFalse(LoyaltyCustomer::where('phone_number', '62987654321')->exists());

        WhatsAppNumber::allowTestNumbers(['0987654321']);
        $this->artisan('loyalty:give-stamps', ['phone' => '0987654321', 'stamps' => 10])->assertSuccessful();

        $this->assertSame(10, $this->cardOf('62987654321')->total_stamps);
    }

    public function test_the_stamp_count_must_fit_the_card(): void
    {
        $this->artisan('loyalty:give-stamps', ['phone' => '0800-0000-0705', 'stamps' => 0])->assertFailed();
        $this->artisan('loyalty:give-stamps', ['phone' => '0800-0000-0705', 'stamps' => 11])->assertFailed();
        $this->artisan('loyalty:give-stamps', ['phone' => '0800-0000-0705', 'stamps' => 'lima'])->assertFailed();

        $this->assertFalse(LoyaltyCustomer::where('phone_number', '6280000000705')->exists());
    }
}
