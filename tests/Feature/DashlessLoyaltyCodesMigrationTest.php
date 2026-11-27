<?php

namespace Tests\Feature;

use App\Models\LoyaltyCustomer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026_11_26_152906: dashed codes (25-01-004) become 4-digit ones without
 * dashes (25010004), and back on rollback.
 */
class DashlessLoyaltyCodesMigrationTest extends TestCase
{
    use DatabaseTransactions;

    private const MIGRATION = 'migrations/2026_11_26_152906_reformat_loyalty_codes_without_dashes.php';

    public function test_dashed_codes_lose_their_dashes_and_come_back_on_rollback(): void
    {
        $migration = require database_path(self::MIGRATION);
        // A month no other code in the test database is in.
        $customer = LoyaltyCustomer::create(['phone_number' => '6280000000931']);
        DB::table('loyalty_customers')->where('id', $customer->id)->update(['customer_code' => '25-01-004']);
        $oldCard = $this->card($customer->id, '25-01-004');
        // Issued after the switch: already 25010001, kept.
        $newCard = $this->card($customer->id, '25010001');
        $codes = fn () => [
            DB::table('loyalty_customers')->where('id', $customer->id)->value('customer_code'),
            DB::table('loyalty_cards')->whereIn('id', [$oldCard, $newCard])->orderBy('id')->pluck('card_number')->all(),
        ];

        $migration->up();

        $this->assertSame(['25010004', ['25010004', '25010001']], $codes());

        $migration->down();

        $this->assertSame(['25-01-004', ['25-01-004', '25-01-001']], $codes());
    }

    public function test_a_code_taken_by_a_newer_one_moves_to_the_end_of_its_month(): void
    {
        $migration = require database_path(self::MIGRATION);
        $customer = LoyaltyCustomer::create(['phone_number' => '6280000000932']);
        $oldCard = $this->card($customer->id, '25-02-001');
        $this->card($customer->id, '25020001');
        $this->card($customer->id, '25020002');

        $migration->up();

        $this->assertSame('25020003', DB::table('loyalty_cards')->where('id', $oldCard)->value('card_number'));
    }

    private function card(int $customerId, string $number): int
    {
        return DB::table('loyalty_cards')->insertGetId([
            'loyalty_customer_id' => $customerId,
            'card_number' => $number,
            'total_stamps' => 0,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
