<?php

namespace App\Services\Loyalty;

use App\Enums\LoyaltyCardStatus;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyCustomer;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyStamp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a customer's loyalty looks like right now, for the POS (toArray)
 * and for the customer's own page (cardsForDisplay).
 *
 * Open cards: the Active one, plus Completed (full) cards that still have
 * a reward to hand over.
 */
final class LoyaltyCustomerSummary
{
    private const OPEN = [LoyaltyCardStatus::Active, LoyaltyCardStatus::Completed];

    /**
     * An active reward of the stamp card (Pengaturan → Program Promo).
     *
     * @return array{id: int, stamps: int, label: string, short: string, active: bool}|null
     */
    public static function reward(int $stamps): ?array
    {
        return StampCardProgram::reward($stamps);
    }

    public static function findByPhone(string $normalizedPhone): ?LoyaltyCustomer
    {
        return self::load(LoyaltyCustomer::query()->where('phone_number', $normalizedPhone)->first());
    }

    public static function load(?LoyaltyCustomer $customer): ?LoyaltyCustomer
    {
        return $customer?->loadMissing([
            'cards' => fn ($query) => $query->orderBy('id'),
            'cards.stamps' => fn ($query) => $query->orderBy('stamp_number'),
            'cards.redemptions.reward',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(LoyaltyCustomer $customer): array
    {
        $customer = self::load($customer);
        $current = self::currentCard($customer);
        $rewards = StampCardProgram::rewards();

        return [
            // Off (Program Promo): the POS shows no stamp card at all.
            'program_active' => StampCardProgram::isActive(),
            'registered' => true,
            'customer' => [
                'code' => $customer->customer_code,
                'name' => $customer->customer_name,
                'phone' => $customer->phone_number,
            ],
            'card' => $current ? [
                'number' => $current->card_number,
                'stamps' => $current->total_stamps,
                'slots' => (int) config('loyalty.stamps_per_card'),
                'status' => $current->status->value,
            ] : null,
            'ready_rewards' => self::readyRewards($customer, $rewards),
            'next_reward' => self::nextReward($current, $rewards),
            // Today's stamp already given: another order today earns none.
            'daily_limit_reached' => self::stampsToday($customer) >= LoyaltyStampService::maxStampsPerDay(),
        ];
    }

    /**
     * One member in the POS list (Stamp Card → Riwayat → Member): every stamp
     * earned so far on all cards, the card now and the rewards handed over or
     * ready. The customer comes with stamps_count, stamps_max_stamped_at and
     * redemptions_count (PosProgramController::members()).
     *
     * @param  Collection<int, array{stamps: int, label: string}>  $rewards
     * @return array<string, mixed>
     */
    public static function memberRow(LoyaltyCustomer $customer, Collection $rewards): array
    {
        $customer = self::load($customer);
        $current = self::currentCard($customer);
        $lastStampAt = $customer->getAttribute('stamps_max_stamped_at');

        return [
            'id' => $customer->id,
            'customer' => [
                'code' => $customer->customer_code,
                'name' => $customer->customer_name,
                'phone' => $customer->phone_number,
            ],
            'registered_at' => $customer->created_at?->toIso8601String(),
            'total_stamps' => (int) $customer->getAttribute('stamps_count'),
            'last_stamp_at' => $lastStampAt ? Carbon::parse($lastStampAt)->toIso8601String() : null,
            'cards' => $customer->cards->count(),
            'card' => $current ? [
                'number' => $current->card_number,
                'stamps' => $current->total_stamps,
                'slots' => (int) config('loyalty.stamps_per_card'),
                'status' => $current->status->value,
            ] : null,
            'rewards_redeemed' => (int) $customer->getAttribute('redemptions_count'),
            'rewards_ready' => (int) collect(self::readyRewards($customer, $rewards))->sum('count'),
        ];
    }

    private static function stampsToday(LoyaltyCustomer $customer): int
    {
        return $customer->cards
            ->flatMap(fn (LoyaltyCard $card) => $card->stamps)
            ->filter(fn (LoyaltyStamp $stamp) => $stamp->stamped_at?->isToday())
            ->count();
    }

    /**
     * Props for <x-loyalty.stamp-card>, current card first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function cardsForDisplay(LoyaltyCustomer $customer): Collection
    {
        $customer = self::load($customer);
        $current = self::currentCard($customer);

        $cards = $customer->cards
            ->filter(fn (LoyaltyCard $card) => $card->status === LoyaltyCardStatus::Completed && ! $card->is($current))
            ->prepend($current)
            ->filter();

        return $cards->values()->map(fn (LoyaltyCard $card) => [
            'customerCode' => $customer->customer_code,
            'customerName' => $customer->customer_name,
            'cardNumber' => $card->card_number,
            'phone' => $customer->phone_number,
            'stamps' => $card->stamps->pluck('stamped_at')->all(),
            'slots' => (int) config('loyalty.stamps_per_card'),
            'status' => $card->status,
            'redeemedRewards' => self::redeemedOn($card)->all(),
            'redeemedAt' => self::redeemedAtOn($card)->all(),
            'expiresAt' => $card->expires_at,
        ]);
    }

    /**
     * The Active card, else the newest card (full, waiting for its next
     * stamp to open a new one).
     */
    private static function currentCard(LoyaltyCustomer $customer): ?LoyaltyCard
    {
        return $customer->cards->first(fn (LoyaltyCard $card) => $card->status === LoyaltyCardStatus::Active)
            ?? $customer->cards->last();
    }

    /**
     * Active rewards the cashier can hand over now, counted over all open
     * cards. None while the program is off.
     *
     * @param  Collection<int, array{stamps: int, label: string}>  $rewards
     * @return list<array{stamps: int, label: string, count: int}>
     */
    private static function readyRewards(LoyaltyCustomer $customer, Collection $rewards): array
    {
        if (! StampCardProgram::isActive()) {
            return [];
        }

        $counts = [];

        foreach ($customer->cards as $card) {
            if (! in_array($card->status, self::OPEN, true)) {
                continue;
            }
            $redeemed = self::redeemedOn($card);
            foreach ($rewards as $reward) {
                if ($card->total_stamps >= $reward['stamps'] && ! $redeemed->contains($reward['stamps'])) {
                    $counts[$reward['stamps']] = ($counts[$reward['stamps']] ?? 0) + 1;
                }
            }
        }

        ksort($counts);

        return collect($counts)
            ->map(fn (int $count, int $stamps) => [
                'stamps' => $stamps,
                'label' => $rewards->firstWhere('stamps', $stamps)['label'],
                'count' => $count,
                // The POS Promo loyalty menu Tukar puts in the order.
                'promo_menu_item_id' => $rewards->firstWhere('stamps', $stamps)['promo_menu_item_id'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array{stamps: int, label: string}>  $rewards
     * @return array{stamps: int, label: string, remaining: int}|null
     */
    private static function nextReward(?LoyaltyCard $card, Collection $rewards): ?array
    {
        if ($card === null || $card->status !== LoyaltyCardStatus::Active) {
            return null;
        }

        $reward = $rewards->first(fn (array $reward) => $reward['stamps'] > $card->total_stamps);

        return $reward ? [
            'stamps' => $reward['stamps'],
            'label' => $reward['label'],
            'remaining' => $reward['stamps'] - $card->total_stamps,
        ] : null;
    }

    /**
     * @return Collection<int, int>
     */
    private static function redeemedOn(LoyaltyCard $card): Collection
    {
        return $card->redemptions
            ->map(fn (LoyaltyRedemption $redemption) => $redemption->reward?->stamps_required)
            ->filter()
            ->values();
    }

    /**
     * When each reward on the card was handed over, by its stamp number.
     *
     * @return Collection<int, Carbon>
     */
    private static function redeemedAtOn(LoyaltyCard $card): Collection
    {
        return $card->redemptions
            ->filter(fn (LoyaltyRedemption $redemption) => $redemption->reward?->stamps_required && $redemption->redeemed_at)
            ->sortBy('redeemed_at')
            ->mapWithKeys(fn (LoyaltyRedemption $redemption) => [
                (int) $redemption->reward->stamps_required => $redemption->redeemed_at,
            ]);
    }
}
