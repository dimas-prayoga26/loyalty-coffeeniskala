<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A request the loyalty rules turn down: an order that can't earn a stamp,
 * a reward that isn't ready. $reason is a stable code for the POS, the
 * message is shown to the cashier.
 */
class LoyaltyRuleException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function orderNotFound(int $orderId): self
    {
        return new self('order_not_found', "Order #{$orderId} tidak ditemukan di POS.", 404);
    }

    public static function orderWithoutPhone(): self
    {
        return new self('no_phone', 'Order ini tidak punya nomor HP pelanggan.');
    }

    public static function orderTypeNotEligible(string $type): self
    {
        return new self('order_type', "Order {$type} tidak mendapat stamp.");
    }

    public static function belowMinimum(int $minimum): self
    {
        return new self('below_minimum', 'Total order di bawah minimal stamp (Rp '.number_format($minimum, 0, ',', '.').').');
    }

    public static function programInactive(): self
    {
        return new self('program_inactive', 'Program Stamp Card sedang tidak aktif.');
    }

    public static function beforeProgram(): self
    {
        return new self('before_program', 'Order ini dibuat sebelum Program Stamp Card aktif.');
    }

    public static function dailyLimitReached(): self
    {
        return new self('daily_limit', 'Nomor ini sudah dapat stamp hari ini.');
    }

    public static function invalidPhone(): self
    {
        return new self('invalid_phone', 'Nomor HP tidak valid.');
    }

    public static function customerNotRegistered(): self
    {
        return new self('not_registered', 'Nomor ini belum punya kartu stamp.', 404);
    }

    public static function unknownReward(int $stamps): self
    {
        return new self('unknown_reward', "Tidak ada hadiah untuk stamp ke-{$stamps}.");
    }

    public static function rewardNotReady(string $label): self
    {
        return new self('reward_not_ready', "{$label} belum bisa ditukar.");
    }
}
