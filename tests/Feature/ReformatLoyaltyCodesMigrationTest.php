<?php

namespace Tests\Feature;

use App\Models\LoyaltyCustomer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026_11_25_144041: month-year codes (11-2026-004) become year-month ones
 * (26-11-004), and back on rollback.
 */
class ReformatLoyaltyCodesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_month_year_codes_become_year_month_and_back(): void
    {
        $migration = require database_path('migrations/2026_11_25_144041_reformat_loyalty_codes_to_year_month.php');
        // A month no other code in the test database is in.
        $customer = LoyaltyCustomer::create(['phone_number' => '6280000000921']);
        DB::table('loyalty_customers')->where('id', $customer->id)->update(['customer_code' => '01-2025-004']);
        $oldCard = $this->card($customer->id, '01-2025-004');
        // Issued after the year-month switch: already 25-01-001, kept.
        $newCard = $this->card($customer->id, '25-01-001');
        $codes = fn () => [
            DB::table('loyalty_customers')->where('id', $customer->id)->value('customer_code'),
            DB::table('loyalty_cards')->whereIn('id', [$oldCard, $newCard])->orderBy('id')->pluck('card_number')->all(),
        ];

        $migration->up();

        $this->assertSame(['25-01-004', ['25-01-004', '25-01-001']], $codes());

        $migration->down();

        $this->assertSame(['01-2025-004', ['01-2025-004', '01-2025-001']], $codes());
    }

    public function test_a_code_taken_by_a_newer_one_moves_to_the_end_of_its_month(): void
    {
        $migration = require database_path('migrations/2026_11_25_144041_reformat_loyalty_codes_to_year_month.php');
        $customer = LoyaltyCustomer::create(['phone_number' => '6280000000922']);
        $oldCard = $this->card($customer->id, '02-2025-001');
        $this->card($customer->id, '25-02-001');
        $this->card($customer->id, '25-02-002');

        $migration->up();

        $this->assertSame('25-02-003', DB::table('loyalty_cards')->where('id', $oldCard)->value('card_number'));
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
