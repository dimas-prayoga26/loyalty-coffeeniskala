<?php

namespace App\Services\Loyalty;

use App\Enums\LoyaltyCardStatus;
use App\Exceptions\LoyaltyRuleException;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyCustomer;
use App\Models\LoyaltyRedemption;

/**
 * The QR on the back of a stamp card: one code per reward of a card,
 * "NSKV1.<card number>.<stamps>.<time slot>.<signature>", signed with the
 * app key so a code can't be made up or changed to another card or reward.
 *
 * It changes every loyalty.voucher_qr_seconds (1 minute): the customer's page
 * fetches the new one (CustomerVerificationController::vouchers), and a code
 * is accepted only while shown and for the slot after it (scanned as it
 * changed). A screenshot of it soon stops working.
 *
 * The cashier scans it in the POS (GET /api/pos/vouchers/{code}): the
 * customer's name and number fill in and the reward goes in the order. It
 * works once: after that reward of the card is handed over it answers
 * "redeemed".
 */
final class RewardVoucher
{
    public const PREFIX = 'NSKV1';

    private const SIGNATURE_LENGTH = 16;

    /**
     * What the cashier is told when the reward can't be handed over.
     */
    private const MESSAGES = [
        'redeemed' => 'Hadiah ini sudah ditukar.',
        'not_ready' => 'Hadiah ini belum bisa ditukar.',
        'expired' => 'Kartu stamp ini sudah kedaluwarsa.',
        'card_closed' => 'Kartu stamp ini sudah ditutup.',
        'program_inactive' => 'Stamp Card sedang nonaktif.',
        'reward_inactive' => 'Hadiah ini sedang nonaktif.',
    ];

    /**
     * Seconds each QR is shown before the next one.
     */
    public static function period(): int
    {
        return max(10, (int) config('loyalty.voucher_qr_seconds', 60));
    }

    /**
     * The time slot a moment falls in (now by default).
     */
    public static function slot(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? now()->getTimestamp(), self::period());
    }

    /**
     * Seconds until the QR shown now is replaced.
     */
    public static function secondsLeft(): int
    {
        return self::period() - (now()->getTimestamp() % self::period());
    }

    public static function code(string $cardNumber, int $stamps, ?int $slot = null): string
    {
        $slot ??= self::slot();

        return implode('.', [self::PREFIX, $cardNumber, $stamps, $slot, self::signature($cardNumber, $stamps, $slot)]);
    }

    /**
     * The codes shown now on a customer's cards, one per reward of each
     * card on their page (whether it is ready or not).
     *
     * @return list<array{card_number: string, stamps: int, code: string}>
     */
    public static function codesFor(LoyaltyCustomer $customer): array
    {
        $rewards = StampCardProgram::rewards(all: true);
        $slot = self::slot();

        return LoyaltyCustomerSummary::cardsForDisplay($customer)
            ->flatMap(fn (array $card) => $rewards->map(fn (array $reward) => [
                'card_number' => $card['cardNumber'],
                'stamps' => $reward['stamps'],
                'code' => self::code($card['cardNumber'], $reward['stamps'], $slot),
            ]))
            ->values()
            ->all();
    }

    /**
     * The card, reward and time slot of a code, or null when it isn't one
     * of ours (or was changed).
     *
     * @return array{card_number: string, stamps: int, slot: int}|null
     */
    public static function parse(string $code): ?array
    {
        $parts = explode('.', trim($code));

        if (count($parts) !== 5 || $parts[0] !== self::PREFIX || $parts[1] === ''
            || ! ctype_digit($parts[2]) || ! ctype_digit($parts[3])) {
            return null;
        }

        [, $cardNumber, $stamps, $slot, $signature] = $parts;

        if (! hash_equals(self::signature($cardNumber, (int) $stamps, (int) $slot), strtolower($signature))) {
            return null;
        }

        return ['card_number' => $cardNumber, 'stamps' => (int) $stamps, 'slot' => (int) $slot];
    }

    /**
     * Shown now, or just replaced (the customer held it up as it changed).
     */
    public static function isCurrent(int $slot): bool
    {
        $now = self::slot();

        return $slot === $now || $slot === $now - 1;
    }

    /**
     * The customer and reward behind a code, and whether the reward can be
     * handed over now (status 'ready'). Null: not a voucher of an existing
     * card and reward.
     *
     * @return array{status: string, ready: bool, message: string|null, customer: array{code: string, name: string|null, phone: string}, card_number: string, reward: array{stamps: int, label: string, promo_menu_item_id: int|null}}|null
     *
     * @throws LoyaltyRuleException a code that has been replaced since
     */
    public static function resolve(string $code): ?array
    {
        $voucher = self::parse($code);
        if ($voucher === null) {
            return null;
        }

        if (! self::isCurrent($voucher['slot'])) {
            throw LoyaltyRuleException::voucherReplaced();
        }

        $card = LoyaltyCard::query()
            ->with(['customer', 'redemptions.reward'])
            ->where('card_number', $voucher['card_number'])
            ->first();
        $reward = StampCardProgram::rewards(all: true)->firstWhere('stamps', $voucher['stamps']);

        if ($card === null || $card->customer === null || $reward === null) {
            return null;
        }

        // A card past its validity is closed before its voucher is judged.
        if (LoyaltyCard::expireOverdue($card->loyalty_customer_id) > 0) {
            $card->refresh();
        }

        $status = self::status($card, $reward);

        return [
            'status' => $status,
            'ready' => $status === 'ready',
            'message' => self::MESSAGES[$status] ?? null,
            'customer' => [
                'code' => $card->customer->customer_code,
                'name' => $card->customer->customer_name,
                'phone' => $card->customer->phone_number,
            ],
            'card_number' => $card->card_number,
            'reward' => [
                'stamps' => $reward['stamps'],
                'label' => $reward['label'],
                // The POS Promo loyalty menu Tukar puts in the order.
                'promo_menu_item_id' => $reward['promo_menu_item_id'],
            ],
        ];
    }

    /**
     * Same rules as the cashier's Tukar (LoyaltyCustomerSummary's ready
     * rewards and LoyaltyRedemptionService), for this one card.
     *
     * @param  array{stamps: int, active: bool}  $reward
     */
    private static function status(LoyaltyCard $card, array $reward): string
    {
        $redeemed = $card->redemptions->contains(
            fn (LoyaltyRedemption $redemption) => (int) $redemption->reward?->stamps_required === $reward['stamps'],
        );

        return match (true) {
            $redeemed => 'redeemed',
            ! StampCardProgram::isActive() => 'program_inactive',
            ! $reward['active'] => 'reward_inactive',
            $card->status === LoyaltyCardStatus::Expired => 'expired',
            ! in_array($card->status, [LoyaltyCardStatus::Active, LoyaltyCardStatus::Completed], true) => 'card_closed',
            $card->total_stamps < $reward['stamps'] => 'not_ready',
            default => 'ready',
        };
    }

    private static function signature(string $cardNumber, int $stamps, int $slot): string
    {
        $key = (string) config('app.key');

        return substr(hash_hmac('sha256', "reward-voucher|{$cardNumber}|{$stamps}|{$slot}", $key), 0, self::SIGNATURE_LENGTH);
    }
}
