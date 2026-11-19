<?php

namespace App\Services\Loyalty;

use App\Enums\LoyaltyCardStatus;
use App\Exceptions\InvalidWhatsAppNumberException;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyCustomer;
use App\Support\WhatsAppNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The cashier enters a customer's WhatsApp number; this returns that
 * customer and their active loyalty card, creating either one only when it
 * doesn't exist yet. Never duplicates a customer, never updates one.
 */
class LoyaltyRegistrationService
{
    private const ATTEMPTS = 3;

    /**
     * @throws InvalidWhatsAppNumberException
     */
    public function registerOrRetrieve(
        string $whatsappNumber,
        ?string $customerName = null,
        ?string $email = null,
    ): LoyaltyRegistrationResult {
        $phone = WhatsAppNumber::normalize($whatsappNumber);

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(
                    fn () => $this->resolve($phone, $customerName, $email),
                    self::ATTEMPTS,
                );
            } catch (UniqueConstraintViolationException $e) {
                // Another cashier registered the same number between our
                // lookup and our insert. Their transaction has committed, so
                // the next attempt finds their customer instead.
                if ($attempt >= self::ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    private function resolve(string $phone, ?string $customerName, ?string $email): LoyaltyRegistrationResult
    {
        // The row lock serializes everything below per customer, so two
        // cashiers can't both issue a first card for the same person.
        $customer = LoyaltyCustomer::query()
            ->where('phone_number', $phone)
            ->lockForUpdate()
            ->first();

        $customerCreated = $customer === null;
        $customer ??= LoyaltyCustomer::create([
            'phone_number' => $phone,
            'customer_name' => self::blankToNull($customerName),
            'email' => self::blankToNull($email),
        ]);

        $card = $customer->activeCard()->lockForUpdate()->first();

        $cardCreated = $card === null;
        $card ??= $this->issueCard($customer);

        return new LoyaltyRegistrationResult($customer, $card, $customerCreated, $cardCreated);
    }

    /**
     * A new customer, or one whose earlier cards are no longer active,
     * gets a new active card. What happens to a non-active card (e.g. a
     * full card waiting for its reward) is up to the stamp/redemption rules.
     */
    private function issueCard(LoyaltyCustomer $customer): LoyaltyCard
    {
        $sequence = $customer->cards()->count() + 1;

        return $customer->cards()->create([
            'card_number' => sprintf('%s-%02d', $customer->customer_code, $sequence),
            'total_stamps' => 0,
            'status' => LoyaltyCardStatus::Active,
        ]);
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
