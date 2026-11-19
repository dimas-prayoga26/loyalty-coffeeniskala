<?php

namespace Tests\Feature;

use App\Services\Loyalty\LoyaltyRegistrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * /verify -> /verify-success/{customer_code}, on loyalty_customers. Runs on
 * pos_live_raw, rolled back; Cloudflare's siteverify is faked.
 */
class CustomerVerificationTest extends TestCase
{
    use DatabaseTransactions;

    private bool $turnstilePasses = true;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'challenges.cloudflare.com/*' => fn () => Http::response(['success' => $this->turnstilePasses]),
        ]);
    }

    public function test_the_form_shows_the_phone_field_and_the_turnstile_widget(): void
    {
        config(['services.turnstile.site_key' => 'site-key-for-test']);

        $this->get('/verify')
            ->assertOk()
            ->assertSee('name="phone"', false)
            ->assertSee('data-sitekey="site-key-for-test"', false)
            ->assertSee('challenges.cloudflare.com/turnstile/v0/api.js', false);
    }

    public function test_a_registered_number_opens_its_stamp_card(): void
    {
        $code = $this->customerWithStamps('0800-0000-0301', 'Pelanggan Uji', 4);

        $this->post('/verify', ['phone' => '+62 800-0000-0301', 'cf-turnstile-response' => 'token'])
            ->assertRedirect('/verify-success/'.$code);

        $this->get('/verify-success/'.$code)
            ->assertOk()
            ->assertSee('Halo, Pelanggan Uji')
            ->assertSee($code.'-01')
            ->assertSee('0800••••0301')
            ->assertSee('4 dari 10 stamp terkumpul')
            ->assertSeeInOrder(['Minuman gratis', '1 stamp lagi', 'Makanan gratis', '6 stamp lagi'])
            ->assertSee('noindex', false)
            ->assertHeader('Cache-Control', 'no-store, private');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'siteverify'));
    }

    public function test_the_card_page_does_not_open_without_verifying_the_number(): void
    {
        $code = $this->customerWithStamps('0800-0000-0302', null, 1);

        $this->get('/verify-success/'.$code)
            ->assertRedirect('/verify')
            ->assertSessionHas('status');
    }

    public function test_a_number_without_a_card_is_turned_away(): void
    {
        $this->post('/verify', ['phone' => '0800-0000-0399', 'cf-turnstile-response' => 'token'])
            ->assertSessionHasErrors(['phone' => 'Belum ada kartu stamp untuk nomor ini. Cek lagi nomornya, atau sebutkan nomor HP Anda ke kasir saat memesan.']);
    }

    public function test_an_invalid_number_gets_a_clear_message(): void
    {
        $this->post('/verify', ['phone' => 'abc', 'cf-turnstile-response' => 'token'])
            ->assertSessionHasErrors(['phone' => 'Nomor HP ini sepertinya kurang tepat. Contoh: 0812 3456 7890.']);
    }

    public function test_an_empty_number_is_asked_for(): void
    {
        $this->post('/verify', ['phone' => '', 'cf-turnstile-response' => 'token'])
            ->assertSessionHasErrors(['phone' => 'Tulis nomor HP Anda dulu.']);
    }

    public function test_the_security_check_comes_first(): void
    {
        $this->post('/verify', ['phone' => '0800-0000-0301'])
            ->assertSessionHasErrors(['cf-turnstile-response' => 'Selesaikan verifikasi keamanan terlebih dahulu.']);

        $this->turnstilePasses = false;

        $this->post('/verify', ['phone' => '0800-0000-0301', 'cf-turnstile-response' => 'bad'])
            ->assertSessionHasErrors(['cf-turnstile-response' => 'Verifikasi keamanan gagal. Silakan coba lagi.']);
    }

    public function test_an_unknown_code_goes_back_to_the_form(): void
    {
        $this->get('/verify-success/NSK-999999')
            ->assertRedirect('/verify')
            ->assertSessionHas('status');
    }

    private function customerWithStamps(string $phone, ?string $name, int $stamps): string
    {
        $result = app(LoyaltyRegistrationService::class)->registerOrRetrieve($phone, $name);

        for ($i = 1; $i <= $stamps; $i++) {
            $result->card->stamps()->create(['stamp_number' => $i, 'stamped_at' => now()->subDays($stamps - $i)]);
        }
        $result->card->update(['total_stamps' => $stamps]);

        return $result->customer->customer_code;
    }
}
