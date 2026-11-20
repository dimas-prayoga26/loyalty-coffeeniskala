<?php

namespace App\Services\Loyalty;

use App\Enums\LoyaltyCardStatus;
use App\Exceptions\InvalidWhatsAppNumberException;
use App\Exceptions\LoyaltyRuleException;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyStamp;
use App\Models\Pos\PosOrder;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gives a POS order its one stamp. The order is read from the POS database,
 * never taken from the request, so the POS only says which order.
 *
 * Safe to call more than once for the same order (the POS right after
 * saving, loyalty:sync-stamps later): loyalty_stamps.order_id is unique, a
 * repeat returns the stamp already given.
 *
 * A card that reaches stamps_per_card becomes Completed; the next stamp
 * goes on a new card (registerOrRetrieve issues it).
 *
 * A number earns at most max_stamps_per_day stamps on the order's day: the
 * next order that day is refused (daily_limit), whichever card it is on.
 */
class LoyaltyStampService
{
    private const ATTEMPTS = 3;

    public function __construct(private LoyaltyRegistrationService $registration) {}

    /**
     * @throws LoyaltyRuleException
     */
    public function stampOrder(int $orderId, ?int $cashierId = null): LoyaltyStampResult
    {
        $order = PosOrder::query()->find($orderId) ?? throw LoyaltyRuleException::orderNotFound($orderId);

        if ($existing = $this->existingStamp($orderId)) {
            return new LoyaltyStampResult($existing, created: false);
        }

        $this->assertEligible($order);

        for ($attempt = 1; ; $attempt++) {
            try {
                $registration = $this->registration->registerOrRetrieve(
                    (string) $order->customer_phone,
                    $this->customerNameOf($order),
                );
            } catch (InvalidWhatsAppNumberException) {
                throw LoyaltyRuleException::invalidPhone();
            }

            try {
                $stamp = DB::transaction(fn () => $this->addStamp($registration->card->id, $order, $cashierId));
            } catch (UniqueConstraintViolationException $e) {
                // The same order stamped by a concurrent call (order_id),
                // or another order took this stamp number first.
                if ($existing = $this->existingStamp($orderId)) {
                    return new LoyaltyStampResult($existing, created: false);
                }
                if ($attempt >= self::ATTEMPTS) {
                    throw $e;
                }

                continue;
            }

            if ($stamp !== null) {
                return new LoyaltyStampResult($stamp, created: true, customerCreated: $registration->customerCreated);
            }

            // A concurrent stamp filled the card; the next attempt gets the
            // customer's new card.
            if ($attempt >= self::ATTEMPTS) {
                throw new RuntimeException("Order #{$orderId}: no active card to stamp.");
            }
        }
    }

    /**
     * Same checks as stampOrder, without writing: for loyalty:sync-stamps.
     */
    public function isEligible(PosOrder $order): bool
    {
        try {
            $this->assertEligible($order);

            return true;
        } catch (LoyaltyRuleException) {
            return false;
        }
    }

    private function addStamp(int $cardId, PosOrder $order, ?int $cashierId): ?LoyaltyStamp
    {
        $card = LoyaltyCard::query()->lockForUpdate()->findOrFail($cardId);

        if ($card->status !== LoyaltyCardStatus::Active) {
            return null;
        }

        // Checked under the card lock: two orders of one number at once
        // can't both take the day's stamp.
        $day = $order->order_date ?? now();
        if (self::stampsOn($card->loyalty_customer_id, $day) >= self::maxStampsPerDay()) {
            throw LoyaltyRuleException::dailyLimitReached();
        }

        $number = $card->total_stamps + 1;

        $stamp = $card->stamps()->create([
            'order_id' => $order->id,
            'order_code' => $order->order_code,
            'stamp_number' => $number,
            'created_by' => $cashierId,
            'stamped_at' => $order->order_date ?? now(),
        ]);

        $card->total_stamps = $number;
        if ($number >= config('loyalty.stamps_per_card')) {
            $card->status = LoyaltyCardStatus::Completed;
        }
        $card->save();

        return $stamp->setRelation('card', $card);
    }

    /**
     * @throws LoyaltyRuleException
     */
    private function assertEligible(PosOrder $order): void
    {
        // Program Promo: off, no stamps; switched back on, only orders since.
        $program = StampCardProgram::program();
        if (! $program->is_active) {
            throw LoyaltyRuleException::programInactive();
        }
        if ($program->active_since && $order->order_date && $order->order_date->lt($program->active_since)) {
            throw LoyaltyRuleException::beforeProgram();
        }

        if (trim((string) $order->customer_phone) === '') {
            throw LoyaltyRuleException::orderWithoutPhone();
        }

        if (! in_array($order->order_type, config('loyalty.stamp_order_types'), true)) {
            throw LoyaltyRuleException::orderTypeNotEligible((string) $order->order_type);
        }

        $minimum = (int) config('loyalty.stamp_min_order_total');
        if ((float) $order->total_with_tax < $minimum) {
            throw LoyaltyRuleException::belowMinimum($minimum);
        }
    }

    /**
     * The POS names walk-in customers Guest-1, Guest-2, ...: not a name.
     */
    private function customerNameOf(PosOrder $order): ?string
    {
        $name = trim((string) $order->customer_name);

        return $name === '' || preg_match('/^guest(-\d+)?$/i', $name) ? null : $name;
    }

    /**
     * Stamps the customer got on $day, over all their cards.
     */
    public static function stampsOn(int $customerId, CarbonInterface $day): int
    {
        return LoyaltyStamp::query()
            ->whereHas('card', fn ($query) => $query->where('loyalty_customer_id', $customerId))
            ->whereBetween('stamped_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->count();
    }

    /**
     * PHP_INT_MAX when there is no daily limit (max_stamps_per_day 0).
     */
    public static function maxStampsPerDay(): int
    {
        $max = (int) config('loyalty.max_stamps_per_day');

        return $max > 0 ? $max : PHP_INT_MAX;
    }

    private function existingStamp(int $orderId): ?LoyaltyStamp
    {
        return LoyaltyStamp::query()->with('card')->where('order_id', $orderId)->first();
    }
}
