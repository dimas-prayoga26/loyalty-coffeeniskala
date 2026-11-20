<?php

namespace App\Services\Loyalty;

use App\Models\LoyaltyProgram;
use App\Models\LoyaltyReward;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The stamp card program as the POS sets it (Pengaturan → Program Promo):
 * on or off, and its rewards (loyalty_rewards), each on or off. Off, no
 * order earns a stamp and no reward is handed over; the stamps and the
 * history stay. Switched back on, orders from then on count again.
 */
final class StampCardProgram
{
    public const CODE = 'stamp_card';

    public static function program(): LoyaltyProgram
    {
        return LoyaltyProgram::query()->firstOrCreate(
            ['code' => self::CODE],
            ['name' => 'Stamp Card', 'is_active' => true],
        );
    }

    public static function isActive(): bool
    {
        return self::program()->is_active;
    }

    /**
     * The rewards in stamp order (only the active ones unless $all).
     *
     * @return Collection<int, array{id: int, stamps: int, label: string, short: string, active: bool, promo_menu_item_id: int|null}>
     */
    public static function rewards(bool $all = false): Collection
    {
        return LoyaltyReward::query()
            ->whereNotNull('stamps_required')
            ->when(! $all, fn ($query) => $query->where('is_active', true))
            ->orderBy('stamps_required')
            ->get()
            ->map(fn (LoyaltyReward $reward) => self::rewardToArray($reward))
            ->values();
    }

    /**
     * @return array{id: int, stamps: int, label: string, short: string, active: bool}|null
     */
    public static function reward(int $stamps): ?array
    {
        return self::rewards()->firstWhere('stamps', $stamps);
    }

    /**
     * @return array{id: int, stamps: int, label: string, short: string, active: bool}
     */
    public static function rewardToArray(LoyaltyReward $reward): array
    {
        return [
            'id' => $reward->id,
            'stamps' => (int) $reward->stamps_required,
            'label' => $reward->reward_name,
            'short' => $reward->short_name ?: Str::before($reward->reward_name, ' '),
            'active' => (bool) $reward->is_active,
            // The POS Promo loyalty menu Tukar gives for it (null: none yet).
            'promo_menu_item_id' => $reward->promo_menu_item_id ? (int) $reward->promo_menu_item_id : null,
        ];
    }

    /**
     * Switched on after being off, it counts orders from now on.
     */
    public static function setActive(bool $active): LoyaltyProgram
    {
        $program = self::program();

        if ($active && ! $program->is_active) {
            $program->active_since = now();
        }
        $program->is_active = $active;
        $program->save();

        return $program;
    }

    /**
     * What Pengaturan → Program Promo shows.
     *
     * @return array<string, mixed>
     */
    public static function summary(): array
    {
        $program = self::program();

        return [
            'code' => $program->code,
            'name' => $program->name,
            'active' => $program->is_active,
            'active_since' => $program->active_since?->toIso8601String(),
            'rules' => [
                'min_order_total' => (int) config('loyalty.stamp_min_order_total'),
                'stamps_per_card' => (int) config('loyalty.stamps_per_card'),
                'max_stamps_per_day' => (int) config('loyalty.max_stamps_per_day'),
            ],
            'rewards' => self::rewards(all: true)->all(),
        ];
    }
}
