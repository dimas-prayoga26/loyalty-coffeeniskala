<?php

namespace Tests\Feature;

use Illuminate\Testing\TestView;
use Tests\TestCase;

/**
 * Rewards come from loyalty_rewards (Program Promo): stamp 5 = free roti kukus,
 * stamp 10 = free drink.
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
        $view->assertSeeInOrder(['roti-kukus-original-coklat.webp', 'roti-kukus-original-strawberry.webp', 'roti-kukus-pandan-coklat.webp'], false)
            ->assertDontSee('roti-kukus-pandan-stroberi.webp', false)
            ->assertDontSee('americano.webp', false);
    }

    public function test_both_rewards_are_listed_with_the_stamps_still_needed(): void
    {
        $this->card($this->stamps(3))
            ->assertSeeInOrder(['Roti Kukus gratis', '2 stamp lagi', 'Minuman gratis', '7 stamp lagi'])
            ->assertDontSee('Siap ditukar');
    }

    public function test_the_fifth_stamp_unlocks_the_free_roti_kukus(): void
    {
        $this->card($this->stamps(5))
            ->assertSeeInOrder(['Roti Kukus gratis', 'Tunjukkan ke kasir', 'reward-row__state">Tukar<', 'Minuman gratis', '5 stamp lagi'], false)
            ->assertSee('Stamp ke-5 · Roti Kukus gratis')
            ->assertSee('Siap ditukar')
            ->assertSee('Hadiah siap ditukar');
    }

    public function test_a_claimed_roti_kukus_stays_claimed_while_stamps_go_on_to_the_drink(): void
    {
        $this->card($this->stamps(7), redeemedRewards: [5])
            ->assertSeeInOrder(['Roti Kukus gratis', 'Sudah ditukar', 'Minuman gratis', '3 stamp lagi'])
            ->assertDontSee('Siap ditukar')
            ->assertDontSee('Ditukar ');
    }

    public function test_a_claimed_reward_says_when_it_was_handed_over(): void
    {
        $this->blade(
            '<x-loyalty.stamp-card customer-code="NSK-000001" card-number="NSK-000001-01"
                :stamps="$stamps" :redeemed-rewards="[5]" :redeemed-at="[5 => \'2026-11-20 14:33:00\']" />',
            ['stamps' => $this->stamps(7)],
        )->assertSeeInOrder(['Roti Kukus gratis', 'Ditukar 20 November 2026, 14.33', 'Sudah ditukar', 'Minuman gratis']);
    }

    public function test_the_tenth_stamp_unlocks_the_free_drink(): void
    {
        $this->card($this->stamps(10), redeemedRewards: [5])
            ->assertSeeInOrder(['Roti Kukus gratis', 'Sudah ditukar', 'Minuman gratis', 'reward-row__state">Tukar<'], false)
            ->assertSee('Hadiah siap ditukar');
    }

    public function test_tukar_opens_the_voucher_of_its_reward(): void
    {
        $this->card($this->stamps(5))
            ->assertSee('popovertarget="voucher-nsk-000001-01-5"', false)
            ->assertSee('id="voucher-nsk-000001-01-5"', false)
            ->assertSeeInOrder(['Kartu stamp No. NSK-000001-01', 'Roti Kukus gratis', 'Hadiah stamp ke-5', 'images/qrcode_', 'Budi', 'Tunjukkan kode QR ini ke kasir', 'Tutup'])
            ->assertDontSee('voucher-nsk-000001-01-10', false);
    }

    public function test_only_a_ready_reward_has_a_tukar_button(): void
    {
        $this->card($this->stamps(4))
            ->assertDontSee('reward-row__claim', false)
            ->assertDontSee('popover', false);

        $this->card($this->stamps(7), redeemedRewards: [5])
            ->assertDontSee('reward-row__claim', false);
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
            ->assertSee('Roti Kukus gratis')
            ->assertDontSee('Minuman gratis');
    }
}
