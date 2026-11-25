<?php

namespace App\Services\Loyalty;

use App\Enums\LoyaltyCardStatus;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyRedemption;

/**
 * The QR on the back of a stamp card: one code per reward of a card,
 * "NSKV1.<card number>.<stamps>.<signature>", signed with the app key so a
 * code can't be made up or changed to another card or reward. It stays the
 * same for as long as the card lives.
 *
 * The cashier scans it in the POS (GET /api/pos/vouchers/{code}): the
 * customer's name and number fill in and the reward goes in the order. It
 * works once: after that reward of the card is handed over it answers
 * "redeemed".
 *
 * Every card also has a code with stamps 0 (MEMBER): no reward, only the
 * customer, so the cashier needn't type their name and number before any
 * reward is ready (status "member").
 */
final class RewardVoucher
{
    public const PREFIX = 'NSKV1';

    /** The "stamps" of a card's customer QR: no reward, the customer only. */
    public const MEMBER = 0;

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

    public static function code(string $cardNumber, int $stamps): string
    {
        return implode('.', [self::PREFIX, $cardNumber, $stamps, self::signature($cardNumber, $stamps)]);
    }

    /**
     * The card and reward of a code, or null when it isn't one of ours (or
     * was changed).
     *
     * @return array{card_number: string, stamps: int}|null
     */
    public static function parse(string $code): ?array
    {
        $parts = explode('.', trim($code));

        if (count($parts) !== 4 || $parts[0] !== self::PREFIX || $parts[1] === '' || ! ctype_digit($parts[2])) {
            return null;
        }

        [, $cardNumber, $stamps, $signature] = $parts;

        if (! hash_equals(self::signature($cardNumber, (int) $stamps), strtolower($signature))) {
            return null;
        }

        return ['card_number' => $cardNumber, 'stamps' => (int) $stamps];
    }

    /**
     * The customer and reward behind a code, and whether the reward can be
     * handed over now (status 'ready'). A customer QR (stamps 0) has no
     * reward: status 'member', whatever the card's stamps. Null: not a
     * voucher of an existing card and reward.
     *
     * @return array{status: string, ready: bool, message: string|null, customer: array{code: string, name: string|null, phone: string}, card_number: string, reward: array{stamps: int, label: string, promo_menu_item_id: int|null}|null}|null
     */
    public static function resolve(string $code): ?array
    {
        $voucher = self::parse($code);
        if ($voucher === null) {
            return null;
        }

        $card = LoyaltyCard::query()
            ->with(['customer', 'redemptions.reward'])
            ->where('card_number', $voucher['card_number'])
            ->first();
        if ($card === null || $card->customer === null) {
            return null;
        }

        $customer = [
            'code' => $card->customer->customer_code,
            'name' => $card->customer->customer_name,
            'phone' => $card->customer->phone_number,
        ];
        if ($voucher['stamps'] === self::MEMBER) {
            return [
                'status' => 'member',
                'ready' => false,
                'message' => null,
                'customer' => $customer,
                'card_number' => $card->card_number,
                'reward' => null,
            ];
        }

        $reward = StampCardProgram::rewards(all: true)->firstWhere('stamps', $voucher['stamps']);
        if ($reward === null) {
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
            'customer' => $customer,
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

    /**
     * First 16 hex of HMAC-SHA256("Niskala|<card number>|<stamps>", app key).
     * "Niskala" marks what the app key signs here; changing it voids every
     * QR made before.
     */
    private static function signature(string $cardNumber, int $stamps): string
    {
        $key = (string) config('app.key');

        return substr(hash_hmac('sha256', "Niskala|{$cardNumber}|{$stamps}", $key), 0, self::SIGNATURE_LENGTH);
    }
}
