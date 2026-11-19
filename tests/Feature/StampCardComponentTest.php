<?php

namespace Tests\Feature;

use Illuminate\Testing\TestView;
use Tests\TestCase;

/**
 * Rewards come from config('loyalty.rewards'): stamp 5 = free drink,
 * stamp 10 = free food.
 */
class StampCardComponentTest extends TestCase
{
    /**
     * @param  list<string>  $stamps
     * @param  list<int>  $redeemedRewards
     */
    private function card(array $stamps, array $redeemedRewards = [], string $status = 'active'): TestView
    {
        return $this->blade(
            '<x-loyalty.stamp-card customer-code="NSK-000001" card-number="NSK-000001-01"
                customer-name="Budi" phone="6281234567890" :stamps="$stamps"
                :redeemed-rewards="$redeemedRewards" :status="$status" />',
            ['stamps' => $stamps, 'redeemedRewards' => $redeemedRewards, 'status' => $status],
        );
    }

    /** @return list<string> */
    private function stamps(int $count): array
    {
        return array_map(fn (int $day) => sprintf('2026-10-%02d', $day), range(1, $count));
    }

    public function test_it_shows_collected_stamps_progress_and_a_masked_phone(): void
    {
        $view = $this->card($this->stamps(3));

        $view->assertSee('NSK-000001-01')
            ->assertSee('Budi')
            ->assertSee('NSK-000001 · 0812••••7890')
            ->assertSee('3 dari 10 stamp terkumpul')
            ->assertSee('3<span>/10</span>', false)
            ->assertDontSee('6281234567890');

        // Every slot is a button whose details show on tap.
        $view->assertSeeInOrder(['Stamp ke-1', 'Terkumpul 1 Oktober 2026', 'Stamp ke-4', 'Belum terkumpul'])
            ->assertSee('1/10');

        // Slot images in config order, only for collected slots.
        $view->assertSeeInOrder(['americano.webp', 'aren-latte.webp', 'butterscotch-latte.webp'], false)
            ->assertDontSee('matcha-latte.webp', false);
    }

    public function test_both_rewards_are_listed_with_the_stamps_still_needed(): void
    {
        $this->card($this->stamps(3))
            ->assertSeeInOrder(['Minuman gratis', '2 stamp lagi', 'Makanan gratis', '7 stamp lagi'])
            ->assertDontSee('Siap ditukar');
    }

    public function test_the_fifth_stamp_unlocks_the_free_drink(): void
    {
        $this->card($this->stamps(5))
            ->assertSeeInOrder(['Minuman gratis', 'Tunjukkan ke kasir', 'Siap ditukar', 'Makanan gratis', '5 stamp lagi'])
            ->assertSee('Hadiah siap ditukar');
    }

    public function test_a_claimed_drink_stays_claimed_while_stamps_go_on_to_the_food(): void
    {
        $this->card($this->stamps(7), redeemedRewards: [5])
            ->assertSeeInOrder(['Minuman gratis', 'Sudah ditukar', 'Makanan gratis', '3 stamp lagi'])
            ->assertDontSee('Siap ditukar');
    }

    public function test_the_tenth_stamp_unlocks_the_free_food(): void
    {
        $this->card($this->stamps(10), redeemedRewards: [5])
            ->assertSeeInOrder(['Minuman gratis', 'Sudah ditukar', 'Makanan gratis', 'Siap ditukar'])
            ->assertSee('Hadiah siap ditukar');
    }

    public function test_a_redeemed_card_has_had_every_reward(): void
    {
        $this->card($this->stamps(10), status: 'redeemed')
            ->assertSee('Semua hadiah ditukar')
            ->assertSee('stamp-card__overlay', false)
            ->assertDontSee('Siap ditukar');
    }

    public function test_rewards_beyond_the_card_are_left_out(): void
    {
        $this->blade('<x-loyalty.stamp-card customer-code="X" card-number="X-01" :stamps="[]" :slots="6" />')
            ->assertSee('Minuman gratis')
            ->assertDontSee('Makanan gratis');
    }
}
