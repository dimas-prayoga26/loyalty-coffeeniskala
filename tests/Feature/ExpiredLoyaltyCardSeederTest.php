<?php

namespace Tests\Feature;

use App\Enums\LoyaltyCardStatus;
use App\Models\LoyaltyCustomer;
use Database\Seeders\ExpiredLoyaltyCardSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The local demo of an expired card. Runs on pos_live_raw, rolled back.
 */
class ExpiredLoyaltyCardSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_seeds_one_expired_card_with_nine_stamps_however_often_it_runs(): void
    {
        $this->seed(ExpiredLoyaltyCardSeeder::class);
        $this->seed(ExpiredLoyaltyCardSeeder::class);

        $customer = LoyaltyCustomer::query()->where('phone_number', '6280012345678')->sole();
        $card = $customer->cards()->sole();

        $this->assertSame('Demo Kedaluwarsa', $customer->customer_name);
        $this->assertTrue($customer->checkPin(ExpiredLoyaltyCardSeeder::PIN));
        $this->assertSame(LoyaltyCardStatus::Expired, $card->status);
        $this->assertSame(9, $card->total_stamps);
        $this->assertSame(9, $card->stamps()->count());
        $this->assertTrue($card->expires_at->isPast());
        $this->assertSame(0, $card->redemptions()->count());
    }
}
