<?php

namespace Database\Seeders;

use App\Models\LoyaltyCard;
use App\Models\LoyaltyCustomer;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyStamp;
use App\Services\Loyalty\LoyaltyRegistrationService;
use App\Support\WhatsAppNumber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Local demo of a card that ran out, to see it on /verify:
 *
 *     php artisan db:seed --class=ExpiredLoyaltyCardSeeder
 *
 * Number 0800-1234-5678 (not anyone's: 0800 is no mobile prefix), PIN 2468.
 * 9 weekly stamps starting about 5 months ago, so the card's 3 months are
 * long over: it is Expired, its free Roti Kukus (stamp 5) never claimed and
 * now forfeited. The next order would be stamp 1 of a new card.
 *
 * Writes only this number's loyalty_* rows. Running it again builds the
 * demo card afresh (same customer code).
 */
class ExpiredLoyaltyCardSeeder extends Seeder
{
    public const PHONE = '0800-1234-5678';

    public const PIN = '2468';

    private const NAME = 'Demo Kedaluwarsa';

    private const STAMPS = 9;

    public function run(LoyaltyRegistrationService $registration): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->error('Seeder demo ini hanya untuk lokal.');

            return;
        }

        $phone = WhatsAppNumber::normalize(self::PHONE);

        DB::transaction(function () use ($registration, $phone): void {
            $this->clearCards($phone);

            $result = $registration->registerOrRetrieve($phone, self::NAME);
            $result->customer->update(['customer_name' => self::NAME]);
            $result->customer->changePin(self::PIN);

            // A visit a week, the first about 5 months ago; each stamp is
            // recorded as issued on its own day, like a real one.
            $card = $result->card;
            $firstStampAt = now()->subMonths(5)->startOfWeek()->setTime(10, 0);

            for ($number = 1; $number <= self::STAMPS; $number++) {
                $stampedAt = $firstStampAt->copy()->addWeeks($number - 1);

                $card->stamps()->forceCreate([
                    'stamp_number' => $number,
                    'stamped_at' => $stampedAt,
                    'created_at' => $stampedAt,
                    'updated_at' => $stampedAt,
                ]);
            }

            $card->forceFill([
                'total_stamps' => self::STAMPS,
                'expires_at' => LoyaltyCard::expiryAfterFirstStamp($firstStampAt),
            ])->save();

            // Closed as the hourly loyalty:expire-cards would.
            LoyaltyCard::expireOverdue($result->customer->id);

            $this->command?->info(sprintf(
                'Kartu %s: %d stamp sejak %s, berakhir %s (kedaluwarsa). Nomor %s, PIN %s.',
                $card->card_number,
                self::STAMPS,
                $firstStampAt->translatedFormat('j F Y'),
                $card->expires_at->translatedFormat('j F Y'),
                self::PHONE,
                self::PIN,
            ));
        });
    }

    /**
     * The demo number's cards, stamps and redemptions, from an earlier run.
     */
    private function clearCards(string $phone): void
    {
        $customer = LoyaltyCustomer::query()->where('phone_number', $phone)->first();

        if ($customer === null) {
            return;
        }

        $cardIds = $customer->cards()->pluck('id');

        LoyaltyRedemption::query()->where('loyalty_customer_id', $customer->id)->delete();
        LoyaltyStamp::query()->whereIn('loyalty_card_id', $cardIds)->delete();
        LoyaltyCard::query()->whereIn('id', $cardIds)->delete();
    }
}
