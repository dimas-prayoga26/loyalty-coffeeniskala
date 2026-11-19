<?php

namespace Tests\Feature;

use App\Enums\LoyaltyCardStatus;
use App\Models\LoyaltyStamp;
use App\Models\Pos\PosOrder;
use App\Models\Pos\PosUser;
use App\Services\Loyalty\LoyaltyRegistrationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * The guarantees the database itself enforces, checked on pos_live_raw.
 * Every write is rolled back; POS rows are only read.
 */
class LoyaltyDatabaseIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_pos_order_can_earn_only_one_stamp(): void
    {
        $order = PosOrder::query()->orderByDesc('id')->firstOrFail();
        $card = app(LoyaltyRegistrationService::class)->registerOrRetrieve('0800-0000-0101')->card;

        $stamp = $card->stamps()->create([
            'order_id' => $order->id,
            'order_code' => $order->order_code,
            'stamp_number' => 1,
            'stamped_at' => now(),
        ]);

        $this->assertTrue($stamp->order->is($order));
        $this->assertTrue($order->loyaltyStamp->is($stamp));

        $this->expectException(UniqueConstraintViolationException::class);

        $card->stamps()->create([
            'order_id' => $order->id,
            'stamp_number' => 2,
            'stamped_at' => now(),
        ]);
    }

    public function test_a_customer_can_hold_only_one_active_card(): void
    {
        $customer = app(LoyaltyRegistrationService::class)->registerOrRetrieve('0800-0000-0102')->customer;

        $this->expectException(UniqueConstraintViolationException::class);

        $customer->cards()->create([
            'card_number' => $customer->customer_code.'-99',
            'status' => LoyaltyCardStatus::Active,
        ]);
    }

    public function test_loyalty_rows_never_block_deletes_in_the_pos(): void
    {
        $rules = DB::table('information_schema.referential_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('table_name', 'like', 'loyalty\_%')
            ->whereIn('referenced_table_name', ['orders', 'users'])
            ->select(['constraint_name as name', 'delete_rule as rule'])
            ->pluck('rule', 'name')
            ->all();

        $this->assertSame([
            'loyalty_redemptions_redeemed_by_foreign' => 'SET NULL',
            'loyalty_stamps_created_by_foreign' => 'SET NULL',
            'loyalty_stamps_order_id_foreign' => 'SET NULL',
        ], collect($rules)->sortKeys()->all());
    }

    public function test_pos_models_cannot_write_to_pos_tables(): void
    {
        $order = PosOrder::query()->firstOrFail();
        $user = PosUser::query()->firstOrFail();

        // forceFill: get past mass-assignment protection, so it's the
        // read-only guard that stops each write.
        foreach ([
            fn () => $order->forceFill(['note' => 'x'])->save(),
            fn () => $order->delete(),
            fn () => $order->increment('total'),
            fn () => (new PosOrder)->forceFill(['customer_name' => 'x'])->save(),
            fn () => $user->forceFill(['name' => 'x'])->save(),
        ] as $write) {
            try {
                $write();
                $this->fail('A POS model wrote to the POS database.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('read-only', $e->getMessage());
            }
        }
    }

    public function test_stamp_points_at_the_pos_user_who_created_it(): void
    {
        $user = PosUser::query()->firstOrFail();
        $card = app(LoyaltyRegistrationService::class)->registerOrRetrieve('0800-0000-0103')->card;

        $stamp = LoyaltyStamp::create([
            'loyalty_card_id' => $card->id,
            'stamp_number' => 1,
            'created_by' => $user->id,
            'stamped_at' => now(),
        ]);

        $this->assertTrue($stamp->createdBy->is($user));
    }
}
