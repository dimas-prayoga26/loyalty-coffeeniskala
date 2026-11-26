<?php

namespace Tests\Feature;

use App\Enums\LoyaltyCardStatus;
use App\Models\LoyaltyCustomer;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Services\Loyalty\LoyaltyRegistrationService;
use App\Services\Loyalty\RewardVoucher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * /verify (number) -> /verify/pin -> /verify-success/{customer_code}, on
 * loyalty_customers. Runs on pos_live_raw, rolled back; Cloudflare's
 * siteverify is faked.
 *
 * 0800-0000-0301 normalizes to 6280000000301, so its first PIN is 0301.
 */
class CustomerVerificationTest extends TestCase
{
    use DatabaseTransactions;

    private bool $turnstilePasses = true;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'challenges.cloudflare.com/*' => fn () => Http::response([
                'success' => $this->turnstilePasses,
                // A token from this form on the domain it is posted to.
                'hostname' => request()->getHost(),
                'action' => 'verify',
            ]),
        ]);
    }

    public function test_the_form_asks_the_number_first_with_the_turnstile_widget(): void
    {
        config(['services.turnstile.site_key' => 'site-key-for-test']);

        $this->get('/verify')
            ->assertOk()
            ->assertSee('name="phone"', false)
            ->assertDontSee('name="pin"', false)
            ->assertSee('Lanjut')
            ->assertSee('data-sitekey="site-key-for-test"', false)
            ->assertSee('data-action="verify"', false)
            ->assertSee('challenges.cloudflare.com/turnstile/v0/api.js', false);
    }

    public function test_the_pin_is_asked_after_the_number(): void
    {
        $this->customerWithStamps('0800-0000-0301', null, 1);

        $this->enterPhone('0800-0000-0301')
            ->assertSessionHasNoErrors()
            ->assertRedirect('/verify/pin');

        $this->get('/verify/pin')
            ->assertOk()
            ->assertSee('0800••••0301')
            ->assertSee('Ganti nomor')
            ->assertSee('name="pin"', false)
            ->assertDontSee('Belum pernah ganti PIN')
            ->assertDontSee('name="phone"', false)
            ->assertDontSee('challenges.cloudflare.com', false);
    }

    public function test_the_pin_page_needs_a_number_first(): void
    {
        $this->get('/verify/pin')
            ->assertRedirect('/verify')
            ->assertSessionHas('status');

        $this->enterPin('0301')
            ->assertRedirect('/verify')
            ->assertSessionHas('status');
    }

    public function test_once_the_card_is_open_the_pin_page_asks_for_a_number_again(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', null, 1, pin: '2468');

        $this->verify('0800-0000-0301', '2468')
            ->assertRedirect('/verify-success/'.$code);

        $this->get('/verify/pin')->assertRedirect('/verify');
    }

    public function test_a_number_with_its_own_pin_opens_its_stamp_card(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', 'Pelanggan Uji', 4, pin: '2468');

        $this->verify('+62 800-0000-0301', '2468')
            ->assertRedirect('/verify-success/'.$code);

        $this->get('/verify-success/'.$code)
            ->assertOk()
            ->assertSee('Halo, Pelanggan Uji')
            ->assertSee($this->cardNumberOf($code))
            ->assertSee('0800••••0301')
            ->assertSee('4 dari 10 stamp terkumpul')
            ->assertSee('terkumpul '.$this->stampDay(3))
            ->assertSeeInOrder(['Kumpulkan', 'Roti Kukus GRATIS', '1 stamp lagi', 'Minuman GRATIS', '6 stamp lagi'])
            ->assertDontSee('Ganti PIN dulu')
            ->assertSee('noindex', false)
            ->assertHeader('Cache-Control', 'no-store, private');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'siteverify'));
    }

    public function test_the_first_pin_is_the_last_4_digits_and_keeps_the_card_blurred(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', 'Pelanggan Uji', 4);

        $this->verify('0800-0000-0301', '0301')
            ->assertRedirect('/verify-success/'.$code);

        $this->get('/verify-success/'.$code)
            ->assertOk()
            ->assertSee('Halo, Pelanggan Uji')
            ->assertSee('Ganti PIN dulu')
            ->assertSee('class="pin-gate__behind" aria-hidden="true" inert', false)
            ->assertSee('name="new_pin"', false)
            ->assertSee('name="new_pin_confirmation"', false)
            ->assertSee('4 dari 10 stamp terkumpul')
            // Anyone who knows the number knows this PIN: no visit dates.
            ->assertDontSee('terkumpul '.$this->stampDay(3))
            ->assertDontSee($this->stampDay(0))
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_a_wrong_pin_is_refused_and_says_how_many_tries_are_left(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', null, 1);

        $this->verify('0800-0000-0301', '1111')
            ->assertSessionHasErrors(['pin' => 'PIN salah. Sisa 4 kali percobaan.'])
            ->assertSessionMissing('_old_input.pin');

        $this->get('/verify-success/'.$code)->assertRedirect('/verify');
        // Still on the PIN step for that number: another try needs no number.
        $this->get('/verify/pin')->assertOk()->assertSee('0800••••0301');
    }

    public function test_five_wrong_pins_lock_the_number_for_a_while(): void
    {
        $this->customerWithStamps('0800-0000-0301', null, 1);
        $this->enterPhone('0800-0000-0301');

        foreach (range(4, 1) as $left) {
            $this->enterPin('1111')
                ->assertSessionHasErrors(['pin' => "PIN salah. Sisa {$left} kali percobaan."]);
        }

        $this->enterPin('1111')
            ->assertSessionHasErrors(['pin' => 'PIN salah terlalu sering. Coba lagi dalam 15 menit.']);

        // Even the right PIN waits now, also after entering the number again.
        $this->verify('0800-0000-0301', '0301')
            ->assertSessionHasErrors(['pin' => 'PIN salah terlalu sering. Coba lagi dalam 15 menit.']);
    }

    public function test_the_pin_must_be_4_digits(): void
    {
        $this->customerWithStamps('0800-0000-0301', null, 1);
        $this->enterPhone('0800-0000-0301');

        $this->enterPin('')
            ->assertSessionHasErrors(['pin' => 'Tulis PIN Anda.']);

        $this->enterPin('12a4')
            ->assertSessionHasErrors(['pin' => 'PIN berupa 4 angka.']);
    }

    public function test_changing_the_first_pin_opens_the_card(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', 'Pelanggan Uji', 4);
        $this->verify('0800-0000-0301', '0301');

        $this->changePin($code, '0301', '0301')
            ->assertSessionHasErrors(['new_pin' => 'PIN baru tidak boleh sama dengan 4 angka terakhir nomor HP.']);

        $this->changePin($code, '2468', '2469')
            ->assertSessionHasErrors(['new_pin' => 'Kedua PIN tidak sama. Tulis ulang keduanya.']);

        $this->changePin($code, '24', '24')
            ->assertSessionHasErrors(['new_pin' => 'PIN baru berupa 4 angka.']);

        $this->changePin($code, '2468', '2468')
            ->assertRedirect('/verify-success/'.$code)
            ->assertSessionHas('status');

        $customer = LoyaltyCustomer::where('customer_code', $code)->sole();
        $this->assertFalse($customer->hasDefaultPin());
        $this->assertNotSame('2468', $customer->pin);
        $this->assertTrue(Hash::check('2468', $customer->pin));

        $this->get('/verify-success/'.$code)
            ->assertOk()
            ->assertSee('PIN baru tersimpan')
            ->assertDontSee('Ganti PIN dulu')
            ->assertSee('terkumpul '.$this->stampDay(3));
    }

    public function test_after_the_change_only_the_new_pin_opens_the_card(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', null, 1);
        $this->verify('0800-0000-0301', '0301');
        $this->changePin($code, '2468', '2468');

        $this->verify('0800-0000-0301', '0301')
            ->assertSessionHasErrors(['pin' => 'PIN salah. Sisa 4 kali percobaan.']);

        $this->verify('0800-0000-0301', '2468')
            ->assertRedirect('/verify-success/'.$code);
    }

    public function test_a_pin_already_changed_is_not_changed_again_here(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', null, 1, pin: '2468');
        $this->verify('0800-0000-0301', '2468');

        $this->changePin($code, '1357', '1357')
            ->assertRedirect('/verify-success/'.$code);

        $customer = LoyaltyCustomer::where('customer_code', $code)->sole();
        $this->assertTrue($customer->checkPin('2468'));
    }

    public function test_the_pin_cannot_be_changed_without_verifying_the_number(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', null, 1);

        $this->changePin($code, '2468', '2468')
            ->assertRedirect('/verify')
            ->assertSessionHas('status');

        $this->assertTrue(LoyaltyCustomer::where('customer_code', $code)->sole()->hasDefaultPin());
    }

    public function test_the_card_page_links_to_changing_the_pin(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', null, 1, pin: '2468');
        $this->verify('0800-0000-0301', '2468');

        $this->get('/verify-success/'.$code)
            ->assertOk()
            ->assertSee('href="'.route('verify.pin.edit', $code).'"', false);
    }

    public function test_the_card_page_shows_fixed_reward_qrs(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', 'Sinta', 5, pin: '2468');
        $this->verify('0800-0000-0301', '2468');
        $cardNumber = LoyaltyCustomer::where('customer_code', $code)->sole()->cards()->sole()->card_number;

        $this->get('/verify-success/'.$code)
            ->assertOk()
            ->assertSee('data-voucher-qr="'.RewardVoucher::code($cardNumber, 5).'"', false)
            ->assertSee('data-voucher-qr="'.RewardVoucher::code($cardNumber, RewardVoucher::MEMBER).'"', false)
            ->assertDontSee('data-voucher-timer', false);
    }

    public function test_the_blurred_card_has_no_change_pin_link(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', null, 1);
        $this->verify('0800-0000-0301', '0301');

        $this->get('/verify-success/'.$code)
            ->assertOk()
            ->assertDontSee('href="'.route('verify.pin.edit', $code).'"', false);
    }

    public function test_the_change_pin_form_asks_the_old_pin_and_the_new_pin_twice(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', null, 1, pin: '2468');
        $this->verify('0800-0000-0301', '2468');

        $this->get('/verify-success/'.$code.'/pin')
            ->assertOk()
            ->assertSeeInOrder(['Ganti PIN', 'PIN lama', 'PIN baru', 'Ulangi PIN baru'])
            ->assertSee('name="current_pin"', false)
            ->assertSee('name="new_pin"', false)
            ->assertSee('name="new_pin_confirmation"', false)
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('href="'.route('verify.success', $code).'"', false)
            ->assertSee('noindex', false)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_change_pin_form_does_not_open_without_verifying_the_number(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', null, 1, pin: '2468');

        $this->get('/verify-success/'.$code.'/pin')
            ->assertRedirect('/verify')
            ->assertSessionHas('status');

        $this->replacePin($code, '2468', '1357')
            ->assertRedirect('/verify')
            ->assertSessionHas('status');

        $this->assertTrue(LoyaltyCustomer::where('customer_code', $code)->sole()->checkPin('2468'));
    }

    public function test_a_first_pin_goes_back_to_the_card_instead_of_the_change_pin_form(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', null, 1);
        $this->verify('0800-0000-0301', '0301');

        $this->get('/verify-success/'.$code.'/pin')
            ->assertRedirect('/verify-success/'.$code);

        $this->replacePin($code, '0301', '1357')
            ->assertRedirect('/verify-success/'.$code);

        $this->assertTrue(LoyaltyCustomer::where('customer_code', $code)->sole()->hasDefaultPin());
    }

    public function test_changing_the_pin_with_the_old_pin_replaces_it(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', null, 1, pin: '2468');
        $this->verify('0800-0000-0301', '2468');

        $this->replacePin($code, '2468', '1357')
            ->assertSessionHasNoErrors()
            ->assertRedirect('/verify-success/'.$code)
            ->assertSessionHas('status');

        $customer = LoyaltyCustomer::where('customer_code', $code)->sole();
        $this->assertTrue($customer->checkPin('1357'));
        $this->assertFalse($customer->checkPin('2468'));

        $this->get('/verify-success/'.$code)
            ->assertOk()
            ->assertSee('PIN baru tersimpan');
    }

    public function test_a_wrong_old_pin_keeps_the_pin_and_says_how_many_tries_are_left(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', null, 1, pin: '2468');
        $this->verify('0800-0000-0301', '2468');

        $this->replacePin($code, '1111', '1357')
            ->assertSessionHasErrors(['current_pin' => 'PIN salah. Sisa 4 kali percobaan.'])
            ->assertSessionMissing('_old_input.current_pin')
            ->assertSessionMissing('_old_input.new_pin')
            ->assertSessionMissing('_old_input.new_pin_confirmation');

        $this->assertTrue(LoyaltyCustomer::where('customer_code', $code)->sole()->checkPin('2468'));
    }

    public function test_wrong_old_pins_lock_the_number_like_the_pin_step(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', null, 1, pin: '2468');
        $this->verify('0800-0000-0301', '2468');

        foreach (range(4, 1) as $left) {
            $this->replacePin($code, '1111', '1357')
                ->assertSessionHasErrors(['current_pin' => "PIN salah. Sisa {$left} kali percobaan."]);
        }

        $this->replacePin($code, '1111', '1357')
            ->assertSessionHasErrors(['current_pin' => 'PIN salah terlalu sering. Coba lagi dalam 15 menit.']);

        // The lock is the number's: the PIN step waits too, even for the right PIN.
        $this->verify('0800-0000-0301', '2468')
            ->assertSessionHasErrors(['pin' => 'PIN salah terlalu sering. Coba lagi dalam 15 menit.']);
    }

    public function test_the_change_pin_form_refuses_an_invalid_new_pin(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', null, 1, pin: '2468');
        $this->verify('0800-0000-0301', '2468');

        $this->put('/verify-success/'.$code.'/pin', [])
            ->assertSessionHasErrors([
                'current_pin' => 'Tulis PIN lama Anda.',
                'new_pin' => 'Tulis PIN baru Anda.',
            ]);

        $this->replacePin($code, '24', '1357')
            ->assertSessionHasErrors(['current_pin' => 'PIN lama berupa 4 angka.']);

        $this->replacePin($code, '2468', '12a4')
            ->assertSessionHasErrors(['new_pin' => 'PIN baru berupa 4 angka.']);

        $this->replacePin($code, '2468', '1357', '1358')
            ->assertSessionHasErrors(['new_pin' => 'Kedua PIN tidak sama. Tulis ulang keduanya.']);

        $this->replacePin($code, '2468', '2468')
            ->assertSessionHasErrors(['new_pin' => 'PIN baru harus beda dari PIN lama.']);

        $this->replacePin($code, '2468', '0301')
            ->assertSessionHasErrors(['new_pin' => 'PIN baru tidak boleh sama dengan 4 angka terakhir nomor HP.']);

        $this->assertTrue(LoyaltyCustomer::where('customer_code', $code)->sole()->checkPin('2468'));
    }

    private function cardNumberOf(string $customerCode): string
    {
        return LoyaltyCustomer::where('customer_code', $customerCode)->sole()->cards()->sole()->card_number;
    }

    public function test_the_card_page_says_until_when_the_card_is_valid(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', null, 1, pin: '2468');
        $this->setExpiry($code, '2027-02-19 23:59:59');
        $this->verify('0800-0000-0301', '2468');

        $this->get('/verify-success/'.$code)
            ->assertOk()
            ->assertSeeInOrder([$this->cardNumberOf($code), 'Berlaku s.d.', '19 Februari 2027']);
    }

    public function test_an_expired_card_says_its_stamps_start_again_from_zero(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', null, 3, pin: '2468');
        $this->setExpiry($code, now()->subDay()->endOfDay()->toDateTimeString());
        $this->verify('0800-0000-0301', '2468');

        $this->get('/verify-success/'.$code)
            ->assertOk()
            ->assertSee('Masa berlaku kartu ini sudah habis. Stamp mulai lagi dari 0 di order berikutnya.')
            ->assertSee('Kartu kedaluwarsa')
            ->assertSee('Berakhir')
            ->assertDontSee('Berlaku s.d.');

        $card = LoyaltyCustomer::where('customer_code', $code)->sole()->cards()->sole();
        $this->assertSame(LoyaltyCardStatus::Expired, $card->status);
    }

    public function test_the_blurred_card_does_not_say_until_when_it_is_valid(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', null, 1);
        $this->setExpiry($code, '2027-02-19 23:59:59');
        $this->verify('0800-0000-0301', '0301');

        $this->get('/verify-success/'.$code)
            ->assertOk()
            ->assertSee('Ganti PIN dulu')
            ->assertDontSee('Berlaku')
            ->assertDontSee('19 Februari 2027');
    }

    public function test_the_card_says_when_a_reward_was_handed_over(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', null, 6, pin: '2468');
        $this->redeem($code, 5, '2026-11-20 14:33:00');

        $this->verify('0800-0000-0301', '2468');

        $this->get('/verify-success/'.$code)
            ->assertOk()
            ->assertSeeInOrder(['Kumpulkan', 'Roti Kukus GRATIS', 'Ditukar 20 November 2026, 14.33', 'Minuman GRATIS']);
    }

    public function test_the_blurred_card_does_not_say_when_a_reward_was_handed_over(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', null, 6);
        $this->redeem($code, 5, '2026-11-20 14:33:00');

        $this->verify('0800-0000-0301', '0301');

        $this->get('/verify-success/'.$code)
            ->assertOk()
            ->assertSee('Ganti PIN dulu')
            ->assertDontSee('20 November 2026');
    }

    public function test_the_card_page_does_not_open_without_verifying_the_number(): void
    {
        $code = $this->customerWithStamps('0800-0000-0302', null, 1);

        $this->get('/verify-success/'.$code)
            ->assertRedirect('/verify')
            ->assertSessionHas('status');
    }

    public function test_a_number_without_a_card_is_turned_away_before_the_pin(): void
    {
        $this->enterPhone('0800-0000-0399')
            ->assertSessionHasErrors(['phone' => 'Belum ada kartu stamp untuk nomor ini. Cek lagi nomornya, atau sebutkan nomor HP Anda ke kasir saat memesan.']);

        $this->get('/verify/pin')->assertRedirect('/verify');
    }

    public function test_an_invalid_number_gets_a_clear_message(): void
    {
        $this->enterPhone('abc')
            ->assertSessionHasErrors(['phone' => 'Nomor HP ini sepertinya kurang tepat. Contoh: 0812 3456 7890.']);
    }

    public function test_an_empty_number_is_asked_for(): void
    {
        $this->enterPhone('')
            ->assertSessionHasErrors(['phone' => 'Tulis nomor HP Anda dulu.']);
    }

    public function test_the_security_check_comes_first(): void
    {
        $this->customerWithStamps('0800-0000-0301', null, 1);

        $this->post('/verify', ['phone' => '0800-0000-0301'])
            ->assertSessionHasErrors(['cf-turnstile-response' => 'Selesaikan verifikasi keamanan terlebih dahulu.']);

        $this->turnstilePasses = false;

        $this->post('/verify', ['phone' => '0800-0000-0301', 'cf-turnstile-response' => 'bad'])
            ->assertSessionHasErrors(['cf-turnstile-response' => 'Verifikasi keamanan gagal. Silakan coba lagi.']);

        $this->get('/verify/pin')->assertRedirect('/verify');
    }

    public function test_an_unknown_code_goes_back_to_the_form(): void
    {
        $this->get('/verify-success/NSK-999999')
            ->assertRedirect('/verify')
            ->assertSessionHas('status');
    }

    /** Both steps: the number, then its PIN. */
    private function verify(string $phone, string $pin): TestResponse
    {
        $this->enterPhone($phone);

        return $this->enterPin($pin);
    }

    private function enterPhone(string $phone): TestResponse
    {
        return $this->post('/verify', ['phone' => $phone, 'cf-turnstile-response' => 'token']);
    }

    private function enterPin(string $pin): TestResponse
    {
        return $this->post('/verify/pin', ['pin' => $pin]);
    }

    private function changePin(string $code, string $pin, string $confirmation): TestResponse
    {
        return $this->post('/verify-success/'.$code.'/pin', [
            'new_pin' => $pin,
            'new_pin_confirmation' => $confirmation,
        ]);
    }

    /** Changing a PIN the customer already set, from the "Ganti PIN" form. */
    private function replacePin(string $code, string $currentPin, string $newPin, ?string $confirmation = null): TestResponse
    {
        return $this->put('/verify-success/'.$code.'/pin', [
            'current_pin' => $currentPin,
            'new_pin' => $newPin,
            'new_pin_confirmation' => $confirmation ?? $newPin,
        ]);
    }

    /** The day the stamp of customerWithStamps() was given, as the card writes it. */
    private function stampDay(int $daysAgo): string
    {
        return now()->subDays($daysAgo)->locale(app()->getLocale())->translatedFormat('j F Y');
    }

    private function setExpiry(string $code, string $expiresAt): void
    {
        LoyaltyCustomer::where('customer_code', $code)->sole()->cards()->sole()
            ->forceFill(['expires_at' => $expiresAt])->save();
    }

    /** The cashier hands over the reward of stamp $stamps on the customer's card. */
    private function redeem(string $code, int $stamps, string $at): void
    {
        $customer = LoyaltyCustomer::where('customer_code', $code)->sole();

        LoyaltyRedemption::create([
            'loyalty_customer_id' => $customer->id,
            'loyalty_card_id' => $customer->cards()->sole()->id,
            'reward_id' => LoyaltyReward::where('stamps_required', $stamps)->sole()->id,
            'redeemed_at' => $at,
        ]);
    }

    /**
     * Stamps on consecutive days, the newest today.
     */
    private function customerWithStamps(string $phone, ?string $name, int $stamps, ?string $pin = null): string
    {
        $result = app(LoyaltyRegistrationService::class)->registerOrRetrieve($phone, $name);

        for ($i = 1; $i <= $stamps; $i++) {
            $result->card->stamps()->create(['stamp_number' => $i, 'stamped_at' => now()->subDays($stamps - $i)]);
        }
        $result->card->update(['total_stamps' => $stamps]);

        if ($pin !== null) {
            $result->customer->changePin($pin);
        }

        return $result->customer->customer_code;
    }
}
