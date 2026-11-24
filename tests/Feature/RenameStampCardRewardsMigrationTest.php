<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026_11_23_000002_rename_stamp_card_rewards on a database still holding
 * the old reward list. Runs on pos_live_raw, rolled back.
 */
class RenameStampCardRewardsMigrationTest extends TestCase
{
    use DatabaseTransactions;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_23_000002_rename_stamp_card_rewards.php');
    }

    private function reward(int $stamps): object
    {
        return DB::table('loyalty_rewards')->where('stamps_required', $stamps)->sole();
    }

    private function setReward(int $stamps, string $label, string $short): void
    {
        DB::table('loyalty_rewards')->updateOrInsert(
            ['stamps_required' => $stamps],
            ['reward_name' => $label, 'short_name' => $short, 'is_active' => true],
        );
    }

    public function test_the_old_rewards_get_the_new_names_and_back(): void
    {
        $this->setReward(5, 'Minuman gratis', 'Minuman');
        $this->setReward(10, 'Makanan gratis', 'Makanan');

        $this->migration()->up();

        $this->assertSame(['Roti Kukus gratis', 'Roti Kukus'], [$this->reward(5)->reward_name, $this->reward(5)->short_name]);
        $this->assertSame(['Minuman gratis', 'Minuman'], [$this->reward(10)->reward_name, $this->reward(10)->short_name]);

        $this->migration()->down();

        $this->assertSame(['Minuman gratis', 'Minuman'], [$this->reward(5)->reward_name, $this->reward(5)->short_name]);
        $this->assertSame(['Makanan gratis', 'Makanan'], [$this->reward(10)->reward_name, $this->reward(10)->short_name]);
    }

    public function test_a_reward_renamed_by_hand_is_left_alone(): void
    {
        $this->setReward(5, 'Kopi Susu gratis', 'Kopi Susu');
        $this->setReward(10, 'Makanan gratis', 'Makanan');

        $this->migration()->up();

        $this->assertSame('Kopi Susu gratis', $this->reward(5)->reward_name);
        $this->assertSame('Minuman gratis', $this->reward(10)->reward_name);
    }
}
