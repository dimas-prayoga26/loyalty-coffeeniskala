<?php

namespace App\Services\Loyalty;

use App\Enums\LoyaltyCardStatus;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyCustomer;
use App\Models\LoyaltyRedemption;
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
     * @return array{stamps: int, label: string, short: string}|null
     */
    public static function reward(int $stamps): ?array
    {
        return collect(config('loyalty.rewards'))->firstWhere('stamps', $stamps);
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

        return [
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
            'ready_rewards' => self::readyRewards($customer),
            'next_reward' => self::nextReward($current),
        ];
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
     * Rewards the cashier can hand over now, counted over all open cards.
     *
     * @return list<array{stamps: int, label: string, count: int}>
     */
    private static function readyRewards(LoyaltyCustomer $customer): array
    {
        $counts = [];

        foreach ($customer->cards as $card) {
            if (! in_array($card->status, self::OPEN, true)) {
                continue;
            }
            $redeemed = self::redeemedOn($card);
            foreach (config('loyalty.rewards') as $reward) {
                if ($card->total_stamps >= $reward['stamps'] && ! $redeemed->contains($reward['stamps'])) {
                    $counts[$reward['stamps']] = ($counts[$reward['stamps']] ?? 0) + 1;
                }
            }
        }

        ksort($counts);

        return collect($counts)
            ->map(fn (int $count, int $stamps) => [
                'stamps' => $stamps,
                'label' => self::reward($stamps)['label'],
                'count' => $count,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{stamps: int, label: string, remaining: int}|null
     */
    private static function nextReward(?LoyaltyCard $card): ?array
    {
        if ($card === null || $card->status !== LoyaltyCardStatus::Active) {
            return null;
        }

        $reward = collect(config('loyalty.rewards'))
            ->sortBy('stamps')
            ->first(fn (array $reward) => $reward['stamps'] > $card->total_stamps);

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
}
