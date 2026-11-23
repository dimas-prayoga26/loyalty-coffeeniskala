<?php

namespace App\Console\Commands;

use App\Enums\LoyaltyCardStatus;
use App\Exceptions\InvalidWhatsAppNumberException;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyCustomer;
use App\Services\Loyalty\LoyaltyRegistrationService;
use App\Support\WhatsAppNumber;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Demo/test: fills a number's card up to N stamps without POS orders, e.g.
 * "loyalty:give-stamps 1234567890 5". The stamps are dated one per day
 * before today, so today's stamp is still open. Safe to run again: a number
 * whose open card already has N stamps (or a full one) is left as it is.
 * --unredeemed first removes the rewards already handed over on that card,
 * so a demo number can show its reward again.
 */
#[Signature('loyalty:give-stamps {phone : Nomor HP pelanggan} {stamps : Jumlah stamp di kartunya} {--name= : Nama pelanggan (hanya untuk nomor baru)} {--unredeemed : Hadiah yang sudah ditukar di kartu itu dibuat belum ditukar}')]
#[Description('Isi kartu stamp satu nomor sampai jumlah stamp tertentu (demo/tes)')]
class GiveLoyaltyStamps extends Command
{
    public function handle(LoyaltyRegistrationService $registration): int
    {
        $slots = (int) config('loyalty.stamps_per_card');
        $target = filter_var($this->argument('stamps'), FILTER_VALIDATE_INT);

        if ($target === false || $target < 1 || $target > $slots) {
            $this->error("Jumlah stamp harus 1 sampai {$slots}.");

            return self::FAILURE;
        }

        try {
            $phone = WhatsAppNumber::normalize((string) $this->argument('phone'));
        } catch (InvalidWhatsAppNumberException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('unredeemed') && ($freed = $this->unredeem($phone)) > 0) {
            $this->info("{$phone}: {$freed} hadiah dibuat belum ditukar.");
        }

        if ($card = $this->openCardWith($phone, $target)) {
            $this->info("{$phone}: kartu {$card->card_number} sudah {$card->total_stamps} stamp.");

            return self::SUCCESS;
        }

        $card = $registration->registerOrRetrieve($phone, $this->option('name'))->card;
        $added = DB::transaction(fn () => $this->fill($card->id, $target));

        $card->refresh();
        $this->info("{$phone}: kartu {$card->card_number} sekarang {$card->total_stamps} stamp (+{$added}).");

        return self::SUCCESS;
    }

    /**
     * The customer's newest open card when it already has $target stamps:
     * a full (Completed) card, its rewards not all handed over, counts.
     */
    private function openCardWith(string $phone, int $target): ?LoyaltyCard
    {
        $card = LoyaltyCustomer::query()->where('phone_number', $phone)->first()
            ?->cards()
            ->whereIn('status', [LoyaltyCardStatus::Active, LoyaltyCardStatus::Completed])
            ->orderByDesc('id')
            ->first();

        return $card && $card->total_stamps >= $target ? $card : null;
    }

    /**
     * Deletes the redemptions on the customer's newest card that is not
     * expired; a card closed because every reward was taken opens again.
     */
    private function unredeem(string $phone): int
    {
        $card = LoyaltyCustomer::query()->where('phone_number', $phone)->first()
            ?->cards()
            ->whereIn('status', [LoyaltyCardStatus::Active, LoyaltyCardStatus::Completed, LoyaltyCardStatus::Redeemed])
            ->orderByDesc('id')
            ->first();

        if ($card === null) {
            return 0;
        }

        return DB::transaction(function () use ($card): int {
            $freed = $card->redemptions()->delete();

            if ($card->status === LoyaltyCardStatus::Redeemed) {
                $card->update(['status' => LoyaltyCardStatus::Completed]);
            }

            return $freed;
        });
    }

    private function fill(int $cardId, int $target): int
    {
        $card = LoyaltyCard::query()->lockForUpdate()->findOrFail($cardId);
        $added = 0;

        for ($number = $card->total_stamps + 1; $number <= $target; $number++) {
            $card->stamps()->create([
                'stamp_number' => $number,
                // Stamp $target yesterday, the ones before it a day apart.
                'stamped_at' => now()->subDays($target - $number + 1)->setTime(12, 0),
            ]);
            $added++;
        }

        $card->total_stamps = max($card->total_stamps, $target);
        if ($card->total_stamps >= (int) config('loyalty.stamps_per_card')) {
            $card->status = LoyaltyCardStatus::Completed;
        }
        $card->save();

        return $added;
    }
}
