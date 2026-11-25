<?php

namespace Tests\Feature;

use App\Enums\LoyaltyCardStatus;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyStamp;
use App\Models\Pos\PosUser;
use App\Services\Loyalty\RewardVoucher;
use App\Services\Loyalty\StampCardProgram;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * /api/pos/*, as pos-backend calls it. Runs on pos_live_raw: the POS
 * orders these tests need are inserted inside the test transaction and
 * rolled back with everything else. 0800 numbers are never a customer's.
 */
class PosLoyaltyApiTest extends TestCase
{
    use DatabaseTransactions;

    private const TOKEN = 'test-pos-token';

    private int $orderSequence = 0;

    private int $orderDay = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'loyalty.pos_api_token' => self::TOKEN,
            'loyalty.stamps_per_card' => 10,
            'loyalty.stamp_min_order_total' => 100000,
            'loyalty.max_stamps_per_day' => 1,
        ]);
    }

    public function test_the_api_needs_the_shared_token(): void
    {
        $this->getJson('/api/pos/customers/080000000201')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/pos/customers/080000000201')->assertUnauthorized();

        config(['loyalty.pos_api_token' => '']);
        $this->withToken('')->getJson('/api/pos/customers/080000000201')->assertServiceUnavailable();
    }

    public function test_an_unknown_number_is_reported_without_registering_it(): void
    {
        $this->api('get', '/api/pos/customers/0800-0000-0201')
            ->assertOk()
            ->assertExactJson([
                'registered' => false,
                'phone' => '6280000000201',
                'program_active' => true,
                'stamp_min_order_total' => 100000,
            ]);

        $this->assertDatabaseMissing('loyalty_customers', ['phone_number' => '6280000000201']);
    }

    public function test_an_invalid_number_is_refused(): void
    {
        $this->api('get', '/api/pos/customers/abc')
            ->assertUnprocessable()
            ->assertJson(['reason' => 'invalid_phone']);
    }

    public function test_the_first_order_of_a_number_registers_it_and_gives_stamp_one(): void
    {
        $cashier = PosUser::query()->firstOrFail();
        $orderId = $this->posOrder(['customer_phone' => '0800-0000-0202', 'customer_name' => 'Sinta']);

        $this->api('post', '/api/pos/stamps', ['order_id' => $orderId, 'cashier_id' => $cashier->id])
            ->assertCreated()
            ->assertJson([
                'stamped' => true,
                'stamp_number' => 1,
                'customer_created' => true,
                'registered' => true,
                'customer' => ['name' => 'Sinta', 'phone' => '6280000000202'],
                'card' => ['stamps' => 1, 'slots' => 10, 'status' => 'active'],
                'ready_rewards' => [],
                'next_reward' => ['stamps' => 5, 'label' => 'Roti Kukus gratis', 'remaining' => 4],
            ]);

        $stamp = LoyaltyStamp::query()->where('order_id', $orderId)->sole();
        $this->assertSame($cashier->id, $stamp->created_by);

        $this->api('get', '/api/pos/customers/080000000202')
            ->assertOk()
            ->assertJson(['registered' => true, 'card' => ['stamps' => 1]]);
    }

    public function test_an_order_earns_only_one_stamp_however_often_it_is_sent(): void
    {
        $orderId = $this->posOrder(['customer_phone' => '080000000203']);

        $this->api('post', '/api/pos/stamps', ['order_id' => $orderId])->assertCreated();
        $this->api('post', '/api/pos/stamps', ['order_id' => $orderId])
            ->assertOk()
            ->assertJson(['stamped' => false, 'stamp_number' => 1, 'card' => ['stamps' => 1]]);

        $this->assertSame(1, LoyaltyStamp::query()->where('order_id', $orderId)->count());
    }

    public function test_guest_names_are_not_kept_as_the_customer_name(): void
    {
        $orderId = $this->posOrder(['customer_phone' => '080000000204', 'customer_name' => 'Guest-12']);

        $this->api('post', '/api/pos/stamps', ['order_id' => $orderId])
            ->assertCreated()
            ->assertJson(['customer' => ['name' => null]]);
    }

    public function test_orders_that_cannot_earn_a_stamp_are_refused_with_a_reason(): void
    {
        $cases = [
            'no_phone' => $this->posOrder(['customer_phone' => null]),
            'order_type' => $this->posOrder(['customer_phone' => '080000000205', 'order_type' => 'Online']),
            'below_minimum' => $this->posOrder(['customer_phone' => '080000000205', 'total' => 99999, 'total_with_tax' => 99999]),
            'invalid_phone' => $this->posOrder(['customer_phone' => '12']),
        ];

        foreach ($cases as $reason => $orderId) {
            $this->api('post', '/api/pos/stamps', ['order_id' => $orderId])
                ->assertUnprocessable()
                ->assertJson(['reason' => $reason]);
        }

        $this->api('post', '/api/pos/stamps', ['order_id' => 999999999])
            ->assertNotFound()
            ->assertJson(['reason' => 'order_not_found']);

        $this->assertDatabaseMissing('loyalty_customers', ['phone_number' => '6280000000205']);
    }

    public function test_an_order_earns_a_stamp_from_rp_100000(): void
    {
        $below = $this->posOrder(['customer_phone' => '080000000211', 'total' => 90909, 'total_with_tax' => 99999]);
        $exact = $this->posOrder(['customer_phone' => '080000000211', 'total' => 90910, 'total_with_tax' => 100000]);

        $this->api('post', '/api/pos/stamps', ['order_id' => $below])
            ->assertUnprocessable()
            ->assertJson(['reason' => 'below_minimum', 'message' => 'Total order di bawah minimal stamp (Rp 100.000).']);

        $this->api('post', '/api/pos/stamps', ['order_id' => $exact])
            ->assertCreated()
            ->assertJson(['stamp_number' => 1]);
    }

    public function test_a_number_earns_one_stamp_a_day(): void
    {
        $phone = '080000000211';
        $today = now()->startOfDay();
        $morning = $this->posOrder(['customer_phone' => $phone, 'order_date' => $today->copy()->addHours(9)]);
        $afternoon = $this->posOrder(['customer_phone' => $phone, 'order_date' => $today->copy()->addHours(16)]);

        $this->api('post', '/api/pos/stamps', ['order_id' => $morning])->assertCreated();
        $this->api('post', '/api/pos/stamps', ['order_id' => $afternoon])
            ->assertUnprocessable()
            ->assertJson(['reason' => 'daily_limit', 'message' => 'Nomor ini sudah dapat stamp hari ini.']);
        $this->assertDatabaseMissing('loyalty_stamps', ['order_id' => $afternoon]);

        // The morning order is still its stamp of the day.
        $this->api('post', '/api/pos/stamps', ['order_id' => $morning])->assertOk();
        $this->api('get', "/api/pos/customers/{$phone}")
            ->assertJson(['card' => ['stamps' => 1], 'daily_limit_reached' => true]);

        // Another number the same day is not held back.
        $this->api('post', '/api/pos/stamps', [
            'order_id' => $this->posOrder(['customer_phone' => '080000000212', 'order_date' => $today->copy()->addHours(16)]),
        ])->assertCreated();
    }

    public function test_the_day_is_the_order_date_and_the_limit_can_be_lifted(): void
    {
        $phone = '080000000213';
        $this->api('post', '/api/pos/stamps', [
            'order_id' => $this->posOrder(['customer_phone' => $phone, 'order_date' => now()->subDay()]),
        ])->assertCreated();
        $this->api('get', "/api/pos/customers/{$phone}")->assertJson(['daily_limit_reached' => false]);

        $this->api('post', '/api/pos/stamps', [
            'order_id' => $this->posOrder(['customer_phone' => $phone, 'order_date' => now()]),
        ])->assertCreated()->assertJson(['stamp_number' => 2]);

        config(['loyalty.max_stamps_per_day' => 0]);
        $this->api('post', '/api/pos/stamps', [
            'order_id' => $this->posOrder(['customer_phone' => $phone, 'order_date' => now()]),
        ])->assertCreated()->assertJson(['stamp_number' => 3, 'daily_limit_reached' => false]);
    }

    public function test_a_full_card_is_completed_and_the_next_stamp_starts_a_new_card(): void
    {
        $phone = '080000000206';
        $this->stampOrders($phone, 10);

        $first = LoyaltyCard::query()->whereHas('customer', fn ($q) => $q->where('phone_number', '6280000000206'))->sole();
        $this->assertSame(LoyaltyCardStatus::Completed, $first->status);
        $this->assertSame(10, $first->total_stamps);

        $this->api('get', "/api/pos/customers/{$phone}")
            ->assertJson([
                'card' => ['number' => $first->card_number, 'stamps' => 10, 'status' => 'completed'],
                'ready_rewards' => [
                    ['stamps' => 5, 'label' => 'Roti Kukus gratis', 'count' => 1],
                    ['stamps' => 10, 'label' => 'Minuman gratis', 'count' => 1],
                ],
                'next_reward' => null,
            ]);

        $response = $this->stampOrders($phone, 1);
        // The next number of the month (year-month-sequence).
        $next = LoyaltyCard::query()->where('loyalty_customer_id', $first->loyalty_customer_id)->whereKeyNot($first->id)->sole()->card_number;
        $this->assertSame(now()->format('y-m').'-002', $next);
        $response
            ->assertJson([
                'stamp_number' => 1,
                'card_number' => $next,
                'card' => ['stamps' => 1, 'status' => 'active'],
                // The full card's rewards are still there to claim.
                'ready_rewards' => [['stamps' => 5, 'count' => 1], ['stamps' => 10, 'count' => 1]],
            ]);
    }

    public function test_the_card_is_valid_for_three_months_from_its_first_stamps_issue(): void
    {
        $phone = '080000000215';

        // An order of the 28th whose stamp is only issued on the 30th
        // (sent late): the card counts from the issue.
        $this->travelTo('2026-11-30 10:15:00');
        $this->api('post', '/api/pos/stamps', [
            'order_id' => $this->posOrder(['customer_phone' => $phone, 'order_date' => '2026-11-28 19:00:00']),
        ])->assertCreated();

        $card = LoyaltyCard::query()->whereHas('customer', fn ($q) => $q->where('phone_number', '6280000000215'))->sole();
        // February has no 30th: valid through its last day.
        $this->assertSame('2027-02-28 23:59:59', $card->expires_at->format('Y-m-d H:i:s'));

        $this->travelTo('2026-12-20 16:00:00');
        $this->api('post', '/api/pos/stamps', [
            'order_id' => $this->posOrder(['customer_phone' => $phone, 'order_date' => '2026-12-20 16:00:00']),
        ])->assertCreated();

        $this->assertSame('2027-02-28 23:59:59', $card->refresh()->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_a_reward_is_redeemed_once_and_only_when_ready(): void
    {
        $phone = '080000000207';
        $cashier = PosUser::query()->firstOrFail();
        $this->stampOrders($phone, 4);

        $redeem = fn (int $stamps) => $this->api('post', '/api/pos/redemptions', [
            'phone' => $phone,
            'reward_stamps' => $stamps,
            'cashier_id' => $cashier->id,
        ]);

        $redeem(5)->assertUnprocessable()->assertJson(['reason' => 'reward_not_ready']);

        $this->stampOrders($phone, 1)->assertJson(['ready_rewards' => [['stamps' => 5, 'count' => 1]]]);

        $redeem(5)
            ->assertCreated()
            ->assertJson([
                'redeemed' => ['reward' => 'Roti Kukus gratis', 'reward_stamps' => 5],
                'card' => ['stamps' => 5, 'status' => 'active'],
                'ready_rewards' => [],
            ]);
        $this->assertDatabaseHas('loyalty_redemptions', ['redeemed_by' => $cashier->id]);

        $redeem(5)->assertUnprocessable()->assertJson(['reason' => 'reward_not_ready']);
        $redeem(7)->assertUnprocessable()->assertJson(['reason' => 'unknown_reward']);
    }

    public function test_after_the_card_expires_the_stamps_start_again_from_one_on_a_new_card(): void
    {
        $phone = '080000000217';

        // First stamp 10 January: valid through 10 April.
        $this->stampAt($phone, '2026-01-10 10:00:00');
        $this->stampAt($phone, '2026-01-11 10:00:00');
        $this->stampAt($phone, '2026-01-12 10:00:00');

        // Its last day still counts on the same card.
        $this->stampAt($phone, '2026-04-10 20:00:00')
            ->assertJson(['stamp_number' => 4, 'card' => ['stamps' => 4, 'status' => 'active']]);

        $first = LoyaltyCard::query()->whereHas('customer', fn ($q) => $q->where('phone_number', '6280000000217'))->sole();
        // The day after: back to 0, so this order is stamp 1 of a new card,
        // numbered in the month it is issued.
        $next = '26-04-001';
        $this->stampAt($phone, '2026-04-11 09:00:00')
            ->assertJson([
                'stamp_number' => 1,
                'card_number' => $next,
                'card' => ['number' => $next, 'stamps' => 1, 'status' => 'active'],
            ]);

        $this->assertSame(LoyaltyCardStatus::Expired, $first->refresh()->status);
        $this->assertSame(4, $first->total_stamps);
        // The new card is valid 3 months from its own first stamp.
        $this->assertSame(
            '2026-07-11 23:59:59',
            LoyaltyCard::query()->where('card_number', $next)->sole()->expires_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_reward_left_on_an_expired_card_can_no_longer_be_handed_over(): void
    {
        $phone = '080000000218';

        foreach (range(10, 14) as $day) {
            $this->stampAt($phone, "2026-01-{$day} 10:00:00");
        }

        $this->travelTo('2026-04-11 09:00:00');

        $this->api('get', "/api/pos/customers/{$phone}")
            ->assertOk()
            ->assertJson([
                'card' => ['stamps' => 5, 'status' => 'expired'],
                'ready_rewards' => [],
                'next_reward' => null,
            ]);

        $this->api('post', '/api/pos/redemptions', [
            'phone' => $phone,
            'reward_stamps' => 5,
            'cashier_id' => PosUser::query()->firstOrFail()->id,
        ])->assertUnprocessable()->assertJson(['reason' => 'reward_not_ready']);
    }

    public function test_a_reward_claimed_with_an_order_keeps_the_order_and_the_menu(): void
    {
        $phone = '080000000216';
        $this->stampOrders($phone, 5);
        $order = $this->posOrder(['customer_phone' => $phone, 'total' => 0, 'total_with_tax' => 0]);

        $this->api('post', '/api/pos/redemptions', [
            'phone' => $phone,
            'reward_stamps' => 5,
            'order_id' => $order,
            'menu_item_id' => 50,
            'menu_name' => 'Loyalty Minuman Gratis (Americano)',
        ])
            ->assertCreated()
            ->assertJson(['redeemed' => [
                'reward' => 'Roti Kukus gratis',
                'order_id' => $order,
                'menu_name' => 'Loyalty Minuman Gratis (Americano)',
            ]]);

        $this->assertDatabaseHas('loyalty_redemptions', [
            'order_id' => $order,
            'menu_item_id' => 50,
            'menu_name' => 'Loyalty Minuman Gratis (Americano)',
        ]);

        $this->api('post', '/api/pos/redemptions', ['phone' => $phone, 'reward_stamps' => 5, 'order_id' => 999999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order_id');
    }

    public function test_redeeming_the_last_reward_of_a_full_card_closes_it(): void
    {
        $phone = '080000000208';
        $this->stampOrders($phone, 10);

        $this->api('post', '/api/pos/redemptions', ['phone' => $phone, 'reward_stamps' => 10])->assertCreated();
        $this->api('post', '/api/pos/redemptions', ['phone' => $phone, 'reward_stamps' => 5])
            ->assertCreated()
            ->assertJson(['card' => ['status' => 'redeemed'], 'ready_rewards' => []]);
    }

    public function test_redeeming_for_an_unknown_number_is_refused(): void
    {
        $this->api('post', '/api/pos/redemptions', ['phone' => '080000000209', 'reward_stamps' => 5])
            ->assertNotFound()
            ->assertJson(['reason' => 'not_registered']);
    }

    public function test_a_scanned_voucher_names_the_customer_and_its_reward(): void
    {
        $cardNumber = $this->stampOrders('080000000220', 5)->json('card.number');

        $this->api('get', '/api/pos/vouchers/'.RewardVoucher::code($cardNumber, 5))
            ->assertOk()
            ->assertJson([
                'status' => 'ready',
                'ready' => true,
                'message' => null,
                'customer' => ['phone' => '6280000000220'],
                'card_number' => $cardNumber,
                'reward' => ['stamps' => 5, 'label' => 'Roti Kukus gratis'],
            ]);

        // Stamp 10 of the same card: a real voucher, not reached yet.
        $this->api('get', '/api/pos/vouchers/'.RewardVoucher::code($cardNumber, 10))
            ->assertOk()
            ->assertJson(['status' => 'not_ready', 'ready' => false, 'message' => 'Hadiah ini belum bisa ditukar.']);
    }

    public function test_a_voucher_works_once(): void
    {
        $phone = '080000000221';
        $cardNumber = $this->stampOrders($phone, 5)->json('card.number');

        $this->api('post', '/api/pos/redemptions', ['phone' => $phone, 'reward_stamps' => 5])->assertCreated();

        $this->api('get', '/api/pos/vouchers/'.RewardVoucher::code($cardNumber, 5))
            ->assertOk()
            ->assertJson(['status' => 'redeemed', 'ready' => false, 'message' => 'Hadiah ini sudah ditukar.']);
    }

    public function test_a_made_up_or_changed_voucher_is_refused(): void
    {
        $cardNumber = $this->stampOrders('080000000222', 5)->json('card.number');
        $code = RewardVoucher::code($cardNumber, 5);
        [$prefix, , , $signature] = explode('.', $code);

        $refused = [
            // The signature of stamp 5 on stamp 10.
            "{$prefix}.{$cardNumber}.10.{$signature}",
            // One character of the signature changed.
            substr($code, 0, -1).(str_ends_with($code, '0') ? '1' : '0'),
            // Signed, but no such card.
            RewardVoucher::code('NSK-999999-01', 5),
            '6281234567890',
            'bukan-voucher',
        ];

        foreach ($refused as $voucher) {
            $this->api('get', '/api/pos/vouchers/'.$voucher)
                ->assertNotFound()
                ->assertJson(['reason' => 'invalid_voucher']);
        }
    }

    public function test_a_customer_qr_only_names_the_customer(): void
    {
        $cardNumber = $this->stampOrders('080000000226', 1)->json('card.number');
        $code = RewardVoucher::code($cardNumber, RewardVoucher::MEMBER);

        // One stamp, no reward ready: still the customer's name and number.
        $this->api('get', '/api/pos/vouchers/'.$code)
            ->assertOk()
            ->assertJson([
                'status' => 'member',
                'ready' => false,
                'message' => null,
                'customer' => ['phone' => '6280000000226'],
                'card_number' => $cardNumber,
                'reward' => null,
            ]);
    }

    public function test_a_voucher_qr_stays_the_same_and_still_scans_later(): void
    {
        $cardNumber = $this->stampOrders('080000000224', 5)->json('card.number');
        $shown = RewardVoucher::code($cardNumber, 5);

        $this->travel(1)->weeks();

        $this->assertSame($shown, RewardVoucher::code($cardNumber, 5));
        $this->api('get', '/api/pos/vouchers/'.$shown)
            ->assertOk()
            ->assertJson(['status' => 'ready', 'customer' => ['phone' => '6280000000224']]);
    }

    public function test_a_voucher_is_not_ready_while_the_stamp_card_is_off(): void
    {
        $cardNumber = $this->stampOrders('080000000223', 5)->json('card.number');
        StampCardProgram::setActive(false);

        $this->api('get', '/api/pos/vouchers/'.RewardVoucher::code($cardNumber, 5))
            ->assertOk()
            ->assertJson(['status' => 'program_inactive', 'ready' => false]);
    }

    public function test_sync_command_stamps_orders_the_pos_could_not_deliver(): void
    {
        $missed = $this->posOrder(['customer_phone' => '080000000210']);
        $withoutPhone = $this->posOrder(['customer_phone' => null]);

        $this->artisan('loyalty:sync-stamps')->assertSuccessful();

        $this->assertDatabaseHas('loyalty_stamps', ['order_id' => $missed, 'stamp_number' => 1]);
        $this->assertDatabaseMissing('loyalty_stamps', ['order_id' => $withoutPhone]);

        // A second run gives nothing twice.
        $this->artisan('loyalty:sync-stamps')->assertSuccessful();
        $this->assertSame(1, LoyaltyStamp::query()->where('order_id', $missed)->count());
    }

    public function test_sync_command_keeps_to_one_stamp_a_day(): void
    {
        $first = $this->posOrder(['customer_phone' => '080000000214', 'order_date' => now()->startOfDay()->addHours(9)]);
        $second = $this->posOrder(['customer_phone' => '080000000214', 'order_date' => now()->startOfDay()->addHours(16)]);

        $this->artisan('loyalty:sync-stamps')->assertSuccessful();

        $this->assertDatabaseHas('loyalty_stamps', ['order_id' => $first]);
        $this->assertDatabaseMissing('loyalty_stamps', ['order_id' => $second]);
    }

    private function api(string $method, string $uri, array $data = []): TestResponse
    {
        return $this->withToken(self::TOKEN)->json($method, $uri, $data);
    }

    /**
     * Stamps $count new orders of $phone, each on a day of its own (one
     * stamp a day); returns the last response.
     */
    private function stampOrders(string $phone, int $count): TestResponse
    {
        $response = null;
        for ($i = 0; $i < $count; $i++) {
            $response = $this->api('post', '/api/pos/stamps', [
                'order_id' => $this->posOrder([
                    'customer_phone' => $phone,
                    'order_date' => now()->subDays(365)->addDays($this->orderDay++),
                ]),
            ])->assertCreated();
        }

        return $response;
    }

    /**
     * An order of $phone stamped at $at (the clock set to it, so the stamp
     * is issued then too).
     */
    private function stampAt(string $phone, string $at): TestResponse
    {
        $this->travelTo($at);

        return $this->api('post', '/api/pos/stamps', [
            'order_id' => $this->posOrder(['customer_phone' => $phone, 'order_date' => $at]),
        ])->assertCreated();
    }

    /**
     * A POS order, inserted only inside this test's rolled-back transaction.
     */
    private function posOrder(array $overrides = []): int
    {
        $this->orderSequence++;

        return DB::table('orders')->insertGetId([
            'order_code' => 'LOYALTY-TEST-'.uniqid().'-'.$this->orderSequence,
            'customer_name' => 'Pelanggan Uji',
            'customer_phone' => null,
            'order_type' => 'Offline',
            'order_status' => 'In Progress',
            'order_date' => now(),
            'total' => 100000,
            'tax' => 0,
            'total_with_tax' => 100000,
            ...$overrides,
        ]);
    }
}
