<?php

namespace Tests\Feature;

use App\Services\Loyalty\RewardVoucher;
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
            ->assertSeeInOrder(['Kumpulkan', 'Roti Kukus GRATIS', '2 stamp lagi', 'Minuman GRATIS', '7 stamp lagi'])
            ->assertDontSee('Siap ditukar');
    }

    public function test_the_fifth_stamp_unlocks_the_free_roti_kukus(): void
    {
        $this->card($this->stamps(5))
            ->assertSeeInOrder(['stamp-slot__print--steam', 'images/card/roti-kukus.svg', 'roti-kukus-pandan-tiramisu-tanpa-papan.webp'])
            ->assertSeeInOrder(['Kumpulkan', 'Roti Kukus GRATIS', 'Siap diambil', 'reward-row__state">Tukar<', 'Minuman GRATIS', '5 stamp lagi'], false)
            ->assertSee('Stamp ke-5 · Roti Kukus gratis')
            ->assertSee('Siap ditukar')
            ->assertSee('Hadiah siap ditukar');
    }

    public function test_a_claimed_roti_kukus_stays_claimed_while_stamps_go_on_to_the_drink(): void
    {
        $this->card($this->stamps(7), redeemedRewards: [5])
            ->assertSeeInOrder(['Kumpulkan', 'Roti Kukus GRATIS', 'Sudah ditukar', 'Minuman GRATIS', '3 stamp lagi'])
            ->assertDontSee('Siap ditukar')
            ->assertDontSee('Ditukar ');
    }

    public function test_reward_spots_show_the_reward_and_the_others_a_sunburst(): void
    {
        $view = $this->card($this->stamps(3))
            ->assertSeeInOrder([
                'images/card/roti-kukus.svg', 'Stamp ke-5 · Roti Kukus gratis',
                'images/card/minuman.svg', 'Stamp ke-10 · Minuman gratis',
            ], false);

        $view->assertDontSee('stamp-slot__print--steam');
        $this->assertSame(8, substr_count((string) $view, 'images/card/sunburst.svg'));
    }

    public function test_a_reward_that_is_neither_food_nor_drink_keeps_a_sunburst_with_its_name(): void
    {
        $this->blade(
            '<x-loyalty.stamp-card customer-code="X" card-number="X-01" :stamps="[]" :rewards="$rewards" />',
            ['rewards' => [['stamps' => 10, 'label' => 'Tumbler gratis', 'short' => 'Tumbler', 'active' => true]]],
        )
            ->assertSee('gratis</small>Tumbler', false)
            ->assertSeeInOrder(['Kumpulkan', '10', 'stamp untuk', 'Tumbler GRATIS'])
            ->assertDontSee('images/card/minuman.svg', false)
            ->assertDontSee('images/card/roti-kukus.svg', false);
    }

    public function test_a_claimed_reward_is_stamped_on_its_slot_too(): void
    {
        $view = $this->card($this->stamps(10), redeemedRewards: [5])
            ->assertSeeInOrder(['roti-kukus-pandan-tiramisu-tanpa-papan.webp', 'stamp-slot__claimed">Ditukar<', 'Roti Kukus gratis, sudah ditukar', 'Stamp ke-6'], false);

        // Only the claimed one: the free drink is still waiting.
        $this->assertSame(1, substr_count((string) $view, 'stamp-slot__claimed'));

        $this->card($this->stamps(5))->assertDontSee('stamp-slot__claimed', false);
    }

    public function test_a_claimed_reward_says_when_it_was_handed_over(): void
    {
        $this->blade(
            '<x-loyalty.stamp-card customer-code="NSK-000001" card-number="NSK-000001-01"
                :stamps="$stamps" :redeemed-rewards="[5]" :redeemed-at="[5 => \'2026-11-20 14:33:00\']" />',
            ['stamps' => $this->stamps(7)],
        )->assertSeeInOrder(['Kumpulkan', 'Roti Kukus GRATIS', 'Ditukar 20 November 2026, 14.33', 'Minuman GRATIS']);
    }

    public function test_the_tenth_stamp_unlocks_the_free_drink(): void
    {
        $this->card($this->stamps(10), redeemedRewards: [5])
            ->assertSee('images/stamps/montblanc.webp')
            ->assertDontSee('images/card/minuman.svg')
            ->assertSeeInOrder(['Kumpulkan', 'Roti Kukus GRATIS', 'Sudah ditukar', 'Minuman GRATIS', 'reward-row__state">Tukar<'], false)
            ->assertSee('Hadiah siap ditukar');
    }

    public function test_tukar_turns_the_card_over_to_the_voucher_on_its_back(): void
    {
        $this->card($this->stamps(5))
            ->assertSee('data-card-flip="5"', false)
            ->assertSee('aria-controls="voucher-nsk-000001-01-5"', false)
            ->assertSee('id="voucher-nsk-000001-01-5"', false)
            // The back stays out of reach until the card is turned over.
            ->assertSee('data-card-back inert', false)
            ->assertSeeInOrder([
                'data-card-front', 'reward-row__state">Tukar<',
                'data-card-back', 'Kartu stamp No. NSK-000001-01', 'Roti Kukus gratis', 'Hadiah stamp ke-5 · Budi',
                'data-voucher-qr="'.RewardVoucher::code('NSK-000001-01', 5).'"',
                'Tunjukkan QR ini ke kasir', 'Balik ke depan kartu',
            ], false)
            ->assertDontSee('popover', false)
            ->assertDontSee('voucher-nsk-000001-01-10', false);
    }

    public function test_each_ready_reward_has_its_own_voucher_qr(): void
    {
        $this->card($this->stamps(10))
            ->assertSee('data-voucher-qr="'.RewardVoucher::code('NSK-000001-01', 5).'"', false)
            ->assertSee('data-voucher-qr="'.RewardVoucher::code('NSK-000001-01', 10).'"', false)
            ->assertDontSee('images/qrcode_', false);

        $this->assertNotSame(RewardVoucher::code('NSK-000001-01', 5), RewardVoucher::code('NSK-000002-01', 5));
    }

    public function test_two_ready_rewards_share_the_back_after_the_customer_qr(): void
    {
        $view = $this->card($this->stamps(10))
            ->assertSee('data-card-flip="5"', false)
            ->assertSee('data-card-flip="10"', false)
            ->assertSeeInOrder(['data-voucher="0"', 'data-voucher="5"', 'Roti Kukus gratis', 'data-voucher="10"', 'Minuman gratis'], false);

        // A turn by hand shows the customer QR; TUKAR shows its reward's.
        $this->assertDoesNotMatchRegularExpression('/data-voucher="0"\s+hidden/', (string) $view);
        $this->assertMatchesRegularExpression('/data-voucher="5"\s+hidden/', (string) $view);
        $this->assertMatchesRegularExpression('/data-voucher="10"\s+hidden/', (string) $view);
    }

    public function test_every_card_has_the_customer_qr_on_its_back(): void
    {
        // No stamp yet, no reward ready: the back still has the customer QR,
        // which only fills in the name and number at the till.
        $this->card([])
            ->assertSee('data-card-flipper', false)
            ->assertSee('data-card-back inert', false)
            ->assertSee('data-card-flip="0"', false)
            ->assertSee('aria-controls="voucher-nsk-000001-01-0"', false)
            ->assertSeeInOrder([
                'data-card-front', 'Tunjukkan QR',
                'data-card-back', 'id="voucher-nsk-000001-01-0"', 'Kartu stamp No. NSK-000001-01', 'Budi',
                'data-voucher-qr="'.RewardVoucher::code('NSK-000001-01', RewardVoucher::MEMBER).'"',
                'data-voucher-stamps="0"',
                'Kasir tidak perlu mencatat nomor HP Anda', 'Balik ke depan kartu',
            ], false)
            ->assertDontSee('voucher-nsk-000001-01-5', false);
    }

    public function test_only_a_ready_reward_has_a_tukar_button(): void
    {
        // The back has the customer QR only, no reward voucher.
        $this->card($this->stamps(4))
            ->assertDontSee('reward-row__claim', false)
            ->assertDontSee('data-card-flip="5"', false)
            ->assertDontSee('voucher-nsk-000001-01-5', false);

        $this->card($this->stamps(7), redeemedRewards: [5])
            ->assertDontSee('reward-row__claim', false)
            ->assertDontSee('voucher-nsk-000001-01-5', false);
    }

    public function test_a_redeemed_card_has_had_every_reward(): void
    {
        $this->card($this->stamps(10), status: 'redeemed')
            ->assertSee('Semua hadiah ditukar')
            ->assertSee('stamp-card__overlay', false)
            ->assertDontSee('Siap ditukar');
    }

    public function test_the_card_says_until_when_it_is_valid(): void
    {
        $this->blade(
            '<x-loyalty.stamp-card customer-code="NSK-000001" card-number="NSK-000001-01"
                :stamps="$stamps" expires-at="2027-02-19 23:59:59" />',
            ['stamps' => $this->stamps(1)],
        )->assertSeeInOrder(['No. NSK-000001-01', 'Berlaku s.d.', '19 Februari 2027']);
    }

    public function test_a_card_without_stamps_says_how_long_it_will_be_valid(): void
    {
        config(['loyalty.card_valid_months' => 3]);

        $this->card([])
            ->assertSee('Berlaku 3 bulan sejak stamp pertama')
            ->assertDontSee('Berlaku s.d.');
    }

    public function test_a_card_whose_rewards_were_all_taken_shows_no_validity(): void
    {
        $this->blade(
            '<x-loyalty.stamp-card customer-code="NSK-000001" card-number="NSK-000001-01"
                :stamps="$stamps" status="redeemed" expires-at="2027-02-19 23:59:59" />',
            ['stamps' => $this->stamps(10)],
        )->assertDontSee('Berlaku');
    }

    public function test_an_expired_card_says_when_it_ended(): void
    {
        $this->blade(
            '<x-loyalty.stamp-card customer-code="NSK-000001" card-number="NSK-000001-01"
                :stamps="$stamps" status="expired" expires-at="2026-02-19 23:59:59" />',
            ['stamps' => $this->stamps(3)],
        )
            ->assertSeeInOrder(['Kartu kedaluwarsa', 'Berakhir', '19 Februari 2026'])
            ->assertDontSee('Berlaku s.d.');
    }

    public function test_rewards_beyond_the_card_are_left_out(): void
    {
        $this->blade('<x-loyalty.stamp-card customer-code="X" card-number="X-01" :stamps="[]" :slots="6" />')
            ->assertSee('Roti Kukus gratis')
            ->assertDontSee('Minuman gratis');
    }
}
