<?php

namespace Tests\Feature;

use App\Enums\LoyaltyCardStatus;
use App\Exceptions\InvalidWhatsAppNumberException;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyCustomer;
use App\Services\Loyalty\LoyaltyRegistrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Runs on pos_live_raw; DatabaseTransactions rolls every write back.
 * 0800 numbers are toll-free, never a customer's WhatsApp.
 */
class LoyaltyRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    private LoyaltyRegistrationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(LoyaltyRegistrationService::class);
    }

    public function test_an_unregistered_number_creates_the_customer_and_a_first_active_card(): void
    {
        $result = $this->service->registerOrRetrieve('0800-0000-0001', 'Budi');

        $this->assertTrue($result->customerCreated);
        $this->assertTrue($result->cardCreated);

        $customer = LoyaltyCustomer::where('phone_number', '6280000000001')->sole();
        $this->assertTrue($customer->is($result->customer));
        $this->assertSame('Budi', $customer->customer_name);
        $this->assertSame(sprintf('NSK-%06d', $customer->id), $customer->customer_code);

        $card = $customer->cards()->sole();
        $this->assertTrue($card->is($result->card));
        $this->assertSame($customer->customer_code.'-01', $card->card_number);
        $this->assertSame(LoyaltyCardStatus::Active, $card->status);
        $this->assertSame(0, $card->total_stamps);
    }

    public function test_a_registered_number_returns_the_existing_customer_and_active_card(): void
    {
        $first = $this->service->registerOrRetrieve('0800-0000-0002');
        $again = $this->service->registerOrRetrieve('+62 800 0000 0002');

        $this->assertFalse($again->customerCreated);
        $this->assertFalse($again->cardCreated);
        $this->assertTrue($again->customer->is($first->customer));
        $this->assertTrue($again->card->is($first->card));

        $this->assertSame(1, LoyaltyCustomer::where('phone_number', '6280000000002')->count());
        $this->assertSame(1, $first->customer->cards()->count());
    }

    public function test_an_existing_customer_is_never_modified(): void
    {
        $this->service->registerOrRetrieve('0800-0000-0003', 'Budi', 'budi@example.com');
        $again = $this->service->registerOrRetrieve('0800-0000-0003', 'Someone Else', 'other@example.com');

        $customer = $again->customer->fresh();
        $this->assertSame('Budi', $customer->customer_name);
        $this->assertSame('budi@example.com', $customer->email);
    }

    public function test_a_customer_without_an_active_card_gets_a_new_one(): void
    {
        $first = $this->service->registerOrRetrieve('0800-0000-0004');
        $first->card->update(['status' => LoyaltyCardStatus::Completed]);

        $again = $this->service->registerOrRetrieve('0800-0000-0004');

        $this->assertFalse($again->customerCreated);
        $this->assertTrue($again->cardCreated);
        $this->assertFalse($again->card->is($first->card));
        $this->assertSame($first->customer->customer_code.'-02', $again->card->card_number);
        $this->assertSame(LoyaltyCardStatus::Active, $again->card->status);

        $this->assertSame(LoyaltyCardStatus::Completed, $first->card->fresh()->status);
        $this->assertSame(2, $first->customer->cards()->count());
    }

    public function test_blank_name_and_email_are_stored_as_null(): void
    {
        $result = $this->service->registerOrRetrieve('0800-0000-0005', '  ', '');

        $this->assertNull($result->customer->fresh()->customer_name);
        $this->assertNull($result->customer->fresh()->email);
    }

    public function test_an_invalid_number_creates_nothing(): void
    {
        $customers = LoyaltyCustomer::count();
        $cards = LoyaltyCard::count();

        try {
            $this->service->registerOrRetrieve('0812abc');
            $this->fail('An invalid number must be rejected.');
        } catch (InvalidWhatsAppNumberException) {
            // expected
        }

        $this->assertSame($customers, LoyaltyCustomer::count());
        $this->assertSame($cards, LoyaltyCard::count());
    }
}
