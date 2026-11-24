<?php

namespace App\Services\Loyalty;

use App\Enums\LoyaltyCardStatus;
use App\Exceptions\InvalidWhatsAppNumberException;
use App\Exceptions\LoyaltyRuleException;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyCustomer;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Support\WhatsAppNumber;
use Illuminate\Support\Facades\DB;

/**
 * The cashier hands over a reward (an active one of Program Promo, picked
 * by the stamp that unlocks it). It comes off the customer's oldest card
 * that has it ready, so a full card's reward is used before the new card's.
 * Nothing is handed over while the program is off.
 *
 * Each reward is redeemed once per card. A Completed (full) card whose
 * active rewards are all redeemed becomes Redeemed. A card past its
 * validity is Expired first: what was still on it can't be handed over.
 */
class LoyaltyRedemptionService
{
    /**
     * $given: the POS order it went out with and the menu given
     * (order_id, menu_item_id, menu_name), when claimed with an order.
     *
     * @param  array{order_id?: int|null, menu_item_id?: int|null, menu_name?: string|null}  $given
     *
     * @throws LoyaltyRuleException
     */
    public function redeem(string $phone, int $rewardStamps, ?int $cashierId = null, array $given = []): LoyaltyRedemption
    {
        if (! StampCardProgram::isActive()) {
            throw LoyaltyRuleException::programInactive();
        }

        $reward = LoyaltyCustomerSummary::reward($rewardStamps)
            ?? throw LoyaltyRuleException::unknownReward($rewardStamps);

        try {
            $phone = WhatsAppNumber::normalize($phone);
        } catch (InvalidWhatsAppNumberException) {
            throw LoyaltyRuleException::invalidPhone();
        }

        return DB::transaction(function () use ($phone, $reward, $cashierId, $given) {
            // Locks the customer: two cashiers can't hand over the same reward.
            $customer = LoyaltyCustomer::query()->where('phone_number', $phone)->lockForUpdate()->first()
                ?? throw LoyaltyRuleException::customerNotRegistered();

            LoyaltyCard::expireOverdue($customer->id);

            $card = $customer->cards()
                ->whereIn('status', [LoyaltyCardStatus::Active, LoyaltyCardStatus::Completed])
                ->where('total_stamps', '>=', $reward['stamps'])
                ->whereDoesntHave('redemptions.reward', fn ($query) => $query->where('stamps_required', $reward['stamps']))
                ->orderBy('id')
                ->lockForUpdate()
                ->first()
                ?? throw LoyaltyRuleException::rewardNotReady($reward['label']);

            $rewardRow = LoyaltyReward::query()->firstOrCreate(
                ['stamps_required' => $reward['stamps']],
                ['reward_name' => $reward['label'], 'is_active' => true],
            );

            $redemption = LoyaltyRedemption::create([
                'loyalty_customer_id' => $customer->id,
                'loyalty_card_id' => $card->id,
                'reward_id' => $rewardRow->id,
                'order_id' => $given['order_id'] ?? null,
                'menu_item_id' => $given['menu_item_id'] ?? null,
                'menu_name' => $given['menu_name'] ?? null,
                'redeemed_by' => $cashierId,
                'redeemed_at' => now(),
            ]);

            $this->closeCardWhenDone($card);

            return $redemption->setRelation('card', $card)->setRelation('reward', $rewardRow);
        });
    }

    private function closeCardWhenDone(LoyaltyCard $card): void
    {
        if ($card->status !== LoyaltyCardStatus::Completed) {
            return;
        }

        $redeemed = $card->redemptions()->with('reward')->get()
            ->map(fn (LoyaltyRedemption $redemption) => $redemption->reward?->stamps_required);

        $allRedeemed = StampCardProgram::rewards()
            ->filter(fn (array $reward) => $reward['stamps'] <= (int) config('loyalty.stamps_per_card'))
            ->every(fn (array $reward) => $redeemed->contains($reward['stamps']));

        if ($allRedeemed) {
            $card->update(['status' => LoyaltyCardStatus::Redeemed]);
        }
    }
}
