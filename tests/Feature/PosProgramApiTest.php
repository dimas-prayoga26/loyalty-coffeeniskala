<?php

namespace Tests\Feature;

use App\Models\LoyaltyReward;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Pengaturan → Program Promo, as pos-backend calls it: the stamp card on or
 * off, and its rewards (loyalty_rewards) added, switched and renamed.
 * Runs on pos_live_raw inside a rolled-back transaction.
 */
class PosProgramApiTest extends TestCase
{
    use DatabaseTransactions;

    private const TOKEN = 'test-pos-token';

    private int $orderSequence = 0;

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

    public function test_the_program_lists_its_rules_and_rewards(): void
    {
        $this->getJson('/api/pos/program')->assertUnauthorized();

        $this->api('get', '/api/pos/program')
            ->assertOk()
            ->assertJson([
                'code' => 'stamp_card',
                'name' => 'Stamp Card',
                'active' => true,
                'rules' => ['min_order_total' => 100000, 'stamps_per_card' => 10, 'max_stamps_per_day' => 1],
                'rewards' => [
                    ['stamps' => 5, 'label' => 'Roti Kukus gratis', 'short' => 'Roti Kukus', 'active' => true],
                    ['stamps' => 10, 'label' => 'Minuman gratis', 'short' => 'Minuman', 'active' => true],
                ],
            ]);
    }

    public function test_switched_off_no_order_earns_a_stamp_and_no_reward_is_handed_over(): void
    {
        $phone = '080000000301';
        $this->stampOrders($phone, 5);

        $this->api('put', '/api/pos/program', ['active' => false])->assertOk()->assertJson(['active' => false]);

        $this->api('post', '/api/pos/stamps', ['order_id' => $this->posOrder(['customer_phone' => $phone])])
            ->assertUnprocessable()
            ->assertJson(['reason' => 'program_inactive', 'message' => 'Program Stamp Card sedang tidak aktif.']);
        $this->api('get', "/api/pos/customers/{$phone}")
            ->assertJson(['program_active' => false, 'ready_rewards' => [], 'card' => ['stamps' => 5]]);
        $this->api('get', '/api/pos/customers/080000000399')
            ->assertJson(['registered' => false, 'program_active' => false]);
        $this->api('post', '/api/pos/redemptions', ['phone' => $phone, 'reward_stamps' => 5])
            ->assertUnprocessable()
            ->assertJson(['reason' => 'program_inactive']);

        // The stamps stay: switched back on, the reward is ready again.
        $this->api('put', '/api/pos/program', ['active' => true])->assertOk()->assertJson(['active' => true]);
        $this->api('get', "/api/pos/customers/{$phone}")
            ->assertJson(['program_active' => true, 'ready_rewards' => [['stamps' => 5, 'count' => 1]]]);
    }

    public function test_switched_back_on_only_orders_from_then_on_count(): void
    {
        $phone = '080000000302';
        $this->api('put', '/api/pos/program', ['active' => false]);
        $whileOff = $this->posOrder(['customer_phone' => $phone, 'order_date' => now()->subMinutes(5)]);

        $this->api('put', '/api/pos/program', ['active' => true]);

        $this->api('post', '/api/pos/stamps', ['order_id' => $whileOff])
            ->assertUnprocessable()
            ->assertJson(['reason' => 'before_program']);
        $this->artisan('loyalty:sync-stamps')->assertSuccessful();
        $this->assertDatabaseMissing('loyalty_stamps', ['order_id' => $whileOff]);

        $this->api('post', '/api/pos/stamps', [
            'order_id' => $this->posOrder(['customer_phone' => $phone, 'order_date' => now()->addMinute()]),
        ])->assertCreated();
    }

    public function test_a_reward_switched_off_is_neither_ready_nor_next_nor_redeemable(): void
    {
        $phone = '080000000303';
        $this->stampOrders($phone, 5);
        $rotiKukus = LoyaltyReward::query()->where('stamps_required', 5)->sole();

        $this->api('put', "/api/pos/rewards/{$rotiKukus->id}", ['active' => false])
            ->assertOk()
            ->assertJsonPath('rewards.0.active', false);

        $this->api('get', "/api/pos/customers/{$phone}")
            ->assertJson(['ready_rewards' => [], 'next_reward' => ['stamps' => 10, 'label' => 'Minuman gratis']]);
        $this->api('post', '/api/pos/redemptions', ['phone' => $phone, 'reward_stamps' => 5])
            ->assertUnprocessable()
            ->assertJson(['reason' => 'unknown_reward']);
    }

    public function test_a_reward_is_linked_to_the_promo_menu_tukar_gives(): void
    {
        $menuId = (int) DB::table('menu_items')->value('id');
        $rotiKukus = LoyaltyReward::query()->where('stamps_required', 5)->sole();

        $this->api('put', "/api/pos/rewards/{$rotiKukus->id}", ['promo_menu_item_id' => $menuId])
            ->assertOk()
            ->assertJsonPath('rewards.0.promo_menu_item_id', $menuId);
        $this->api('put', "/api/pos/rewards/{$rotiKukus->id}", ['promo_menu_item_id' => 999999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('promo_menu_item_id');

        // The cashier's Tukar knows which menu to put in the order.
        $phone = '080000000305';
        $this->stampOrders($phone, 5)
            ->assertJson(['ready_rewards' => [['stamps' => 5, 'promo_menu_item_id' => $menuId]]]);

        $this->api('put', "/api/pos/rewards/{$rotiKukus->id}", ['promo_menu_item_id' => null])
            ->assertOk()
            ->assertJsonPath('rewards.0.promo_menu_item_id', null);
    }

    public function test_a_reward_is_added_and_renamed_from_the_pos(): void
    {
        $this->api('post', '/api/pos/rewards', ['stamps' => 3, 'label' => 'Snack gratis', 'short' => 'Snack'])
            ->assertCreated()
            ->assertJsonPath('rewards.0', fn (array $reward) => $reward['stamps'] === 3
                && $reward['label'] === 'Snack gratis' && $reward['active'] === true);

        $this->api('post', '/api/pos/rewards', ['stamps' => 5, 'label' => 'Lagi'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stamps' => 'Sudah ada hadiah untuk stamp ke-5.']);
        $this->api('post', '/api/pos/rewards', ['stamps' => 11, 'label' => 'Terlalu jauh'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stamps');

        $snack = LoyaltyReward::query()->where('stamps_required', 3)->sole();
        $this->api('put', "/api/pos/rewards/{$snack->id}", ['label' => 'Roti gratis', 'short' => 'Roti'])
            ->assertOk()
            ->assertJsonPath('rewards.0.label', 'Roti gratis');

        // A new card at stamp 3 now has it ready.
        $phone = '080000000304';
        $this->stampOrders($phone, 3)->assertJson(['ready_rewards' => [['stamps' => 3, 'label' => 'Roti gratis']]]);
    }

    public function test_the_history_lists_the_rewards_handed_over_newest_first(): void
    {
        $this->getJson('/api/pos/redemptions')->assertUnauthorized();

        $phone = '080000000311';
        $cashier = DB::table('users')->first(['id', 'name']);
        $this->stampOrders($phone, 10);
        $orderId = $this->posOrder(['customer_phone' => $phone]);
        $orderCode = DB::table('orders')->where('id', $orderId)->value('order_code');
        $this->api('post', '/api/pos/redemptions', [
            'phone' => $phone,
            'reward_stamps' => 5,
            'cashier_id' => $cashier->id,
            'order_id' => $orderId,
            'menu_name' => 'Loyalty Minuman (Americano)',
        ])->assertCreated();
        $this->travel(1)->minutes();
        $this->api('post', '/api/pos/redemptions', ['phone' => $phone, 'reward_stamps' => 10])->assertCreated();

        // Typed as the cashier types it; stored as 628...
        $response = $this->api('get', '/api/pos/redemptions?q=0800-0000-0311&per_page=1')->assertOk();

        $response->assertJsonPath('meta', ['page' => 1, 'per_page' => 1, 'total' => 2, 'last_page' => 2])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reward', ['stamps' => 10, 'label' => 'Minuman gratis'])
            ->assertJsonPath('data.0.order', null)
            ->assertJsonPath('data.0.cashier', null)
            ->assertJsonPath('data.0.customer.phone', '6280000000311');

        $this->api('get', '/api/pos/redemptions?q=080000000311&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('data.0.reward', ['stamps' => 5, 'label' => 'Roti Kukus gratis'])
            ->assertJsonPath('data.0.menu_name', 'Loyalty Minuman (Americano)')
            ->assertJsonPath('data.0.order', ['id' => $orderId, 'code' => $orderCode])
            ->assertJsonPath('data.0.cashier', $cashier->name);

        $code = $response->json('data.0.customer.code');
        $this->api('get', '/api/pos/redemptions?q='.$code)->assertJsonPath('meta.total', 2);
        $this->api('get', '/api/pos/redemptions?q=089999999999')->assertJsonPath('meta.total', 0);
    }

    public function test_the_stamp_history_lists_the_stamps_given_newest_first(): void
    {
        $this->getJson('/api/pos/stamps')->assertUnauthorized();

        $phone = '080000000321';
        $cashier = DB::table('users')->first(['id', 'name']);
        $this->stampOrders($phone, 2);
        $orderId = $this->posOrder(['customer_phone' => $phone]);
        $orderCode = DB::table('orders')->where('id', $orderId)->value('order_code');
        $this->api('post', '/api/pos/stamps', ['order_id' => $orderId, 'cashier_id' => $cashier->id])->assertCreated();

        // Typed as the cashier types it; stored as 628...
        $response = $this->api('get', '/api/pos/stamps?q=0800-0000-0321&per_page=2')->assertOk();

        $response->assertJsonPath('meta', ['page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.stamp_number', 3)
            ->assertJsonPath('data.0.order', ['id' => $orderId, 'code' => $orderCode])
            ->assertJsonPath('data.0.cashier', $cashier->name)
            ->assertJsonPath('data.0.customer.phone', '6280000000321')
            ->assertJsonPath('data.1.stamp_number', 2)
            ->assertJsonPath('data.1.cashier', null);
        $this->assertMatchesRegularExpression('/^\d{8}$/', $response->json('data.0.card_number'));

        // The POS deleted the order: its code stays.
        DB::table('orders')->where('id', $orderId)->delete();
        $this->api('get', '/api/pos/stamps?q=080000000321&per_page=1')
            ->assertJsonPath('data.0.order', ['id' => null, 'code' => $orderCode]);

        $code = $response->json('data.0.customer.code');
        $this->api('get', '/api/pos/stamps?q='.$code)->assertJsonPath('meta.total', 3);
        $this->api('get', '/api/pos/stamps?q=089999999999')->assertJsonPath('meta.total', 0);
    }

    public function test_the_member_list_shows_every_stamp_each_member_has_earned(): void
    {
        $this->travelTo('2024-11-25 10:00:00');
        $this->getJson('/api/pos/members')->assertUnauthorized();

        // 12 stamps: a full first card (Roti Kukus handed over, the drink still
        // to take) and 2 on the second. And a member with 3.
        $this->stampOrders('080000000331', 12);
        $this->api('post', '/api/pos/redemptions', ['phone' => '080000000331', 'reward_stamps' => 5])->assertCreated();
        $this->stampOrders('080000000332', 3);

        // Typed as the cashier types it; stored as 628...; most stamps first.
        $response = $this->api('get', '/api/pos/members?q=08000000033')->assertOk();

        $response->assertJsonPath('meta', ['page' => 1, 'per_page' => 20, 'total' => 2, 'last_page' => 1])
            ->assertJsonPath('data.0.customer.phone', '6280000000331')
            ->assertJsonPath('data.0.total_stamps', 12)
            ->assertJsonPath('data.0.cards', 2)
            ->assertJsonPath('data.0.card.stamps', 2)
            ->assertJsonPath('data.0.card.status', 'active')
            ->assertJsonPath('data.0.rewards_redeemed', 1)
            ->assertJsonPath('data.0.rewards_ready', 1)
            ->assertJsonPath('data.1.customer.phone', '6280000000332')
            ->assertJsonPath('data.1.total_stamps', 3)
            // Issued after the first member's two cards: the third of the month.
            ->assertJsonPath('data.1.card.number', now()->format('ym').'0003')
            ->assertJsonPath('data.1.card.stamps', 3)
            ->assertJsonPath('data.1.card.slots', 10)
            ->assertJsonPath('data.1.card.status', 'active')
            ->assertJsonPath('data.1.rewards_redeemed', 0)
            ->assertJsonPath('data.1.rewards_ready', 0);
        $this->assertSame(now()->format('ym').'0002', $response->json('data.0.card.number'));
        // Valid until 3 months after its first stamp (end of that day).
        $this->assertSame(
            now()->addMonthsNoOverflow(3)->endOfDay()->toIso8601String(),
            $response->json('data.1.card.expires_at'),
        );
        $this->assertNotNull($response->json('data.0.last_stamp_at'));

        $this->api('get', '/api/pos/members?q='.$response->json('data.1.customer.code'))
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.total_stamps', 3);
        $this->api('get', '/api/pos/members?q=08000000033&per_page=1&page=2')
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.customer.phone', '6280000000332');
        $this->api('get', '/api/pos/members?q=089999999999')->assertJsonPath('meta.total', 0);
    }

    private function api(string $method, string $uri, array $data = []): TestResponse
    {
        return $this->withToken(self::TOKEN)->json($method, $uri, $data);
    }

    /**
     * Stamps $count orders of $phone, each on a day of its own; returns the last response.
     */
    private function stampOrders(string $phone, int $count): TestResponse
    {
        $response = null;
        for ($i = 0; $i < $count; $i++) {
            $response = $this->api('post', '/api/pos/stamps', [
                'order_id' => $this->posOrder([
                    'customer_phone' => $phone,
                    'order_date' => now()->subDays(100)->addDays($i),
                ]),
            ])->assertCreated();
        }

        return $response;
    }

    private function posOrder(array $overrides = []): int
    {
        $this->orderSequence++;

        return DB::table('orders')->insertGetId([
            'order_code' => 'PROGRAM-TEST-'.uniqid().'-'.$this->orderSequence,
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
