<?php

namespace Tests\Feature;

use App\Models\LoyaltyCustomer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026_11_25_000001 / 000002: cards and customers from before
 * year-month-sequence codes get one, by the month each was issued /
 * registered, and get their old one back on rollback.
 */
class RenumberLoyaltyCodesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_cards_are_numbered_by_the_month_they_were_issued_and_back(): void
    {
        $migration = require database_path('migrations/2026_11_25_000001_renumber_loyalty_cards_by_issue_month.php');
        $budi = LoyaltyCustomer::create(['phone_number' => '6280000000901']);
        $sari = LoyaltyCustomer::create(['phone_number' => '6280000000902']);
        $card = fn (LoyaltyCustomer $customer, string $number, string $status, string $issuedAt) => DB::table('loyalty_cards')->insertGetId([
            'loyalty_customer_id' => $customer->id,
            'card_number' => $number,
            'total_stamps' => 0,
            'status' => $status,
            'created_at' => $issuedAt,
            'updated_at' => $issuedAt,
        ]);
        $budiFirst = $card($budi, $budi->customer_code.'-01', 'completed', '2025-01-31 23:00:00');
        $sariFirst = $card($sari, $sari->customer_code.'-01', 'active', '2025-02-02 09:00:00');
        $budiSecond = $card($budi, $budi->customer_code.'-02', 'active', '2025-02-05 09:00:00');
        // Months no other card in the test database was issued in.
        $numbers = fn () => DB::table('loyalty_cards')->whereIn('id', [$budiFirst, $sariFirst, $budiSecond])
            ->orderBy('id')->pluck('card_number', 'id')->all();

        $migration->up();

        $this->assertSame([
            $budiFirst => '25-01-001',
            $sariFirst => '25-02-001',
            $budiSecond => '25-02-002',
        ], $numbers());

        $migration->down();

        $this->assertSame([
            $budiFirst => $budi->customer_code.'-01',
            $sariFirst => $sari->customer_code.'-01',
            $budiSecond => $budi->customer_code.'-02',
        ], $numbers());
    }

    public function test_existing_customers_are_coded_by_the_month_they_registered_and_back(): void
    {
        $migration = require database_path('migrations/2026_11_25_000002_renumber_loyalty_customers_by_registration_month.php');
        $customer = function (string $phone, string $registeredAt) {
            $id = LoyaltyCustomer::create(['phone_number' => $phone])->id;
            DB::table('loyalty_customers')->where('id', $id)->update(['created_at' => $registeredAt]);

            return $id;
        };
        $budi = $customer('6280000000911', '2025-01-31 23:00:00');
        $sari = $customer('6280000000912', '2025-02-02 09:00:00');
        $andi = $customer('6280000000913', '2025-02-05 09:00:00');
        // Months no other customer in the test database registered in.
        $codes = fn () => DB::table('loyalty_customers')->whereIn('id', [$budi, $sari, $andi])
            ->orderBy('id')->pluck('customer_code', 'id')->all();

        $migration->up();

        $this->assertSame([$budi => '25-01-001', $sari => '25-02-001', $andi => '25-02-002'], $codes());

        $migration->down();

        $this->assertSame([
            $budi => sprintf('NSK-%06d', $budi),
            $sari => sprintf('NSK-%06d', $sari),
            $andi => sprintf('NSK-%06d', $andi),
        ], $codes());
    }
}
