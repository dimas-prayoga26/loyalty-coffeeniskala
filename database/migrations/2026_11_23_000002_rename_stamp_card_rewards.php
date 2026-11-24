<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The stamp card's rewards changed: 5 stamps = Roti Kukus (was Minuman),
 * 10 stamps = Minuman (was Makanan). config('loyalty.rewards') only seeds
 * an empty table, so databases seeded with the old list are renamed here.
 * A reward renamed by hand in the POS is left alone.
 */
return new class extends Migration
{
    /** stamps => [old label, new label, new short name] */
    private const RENAMES = [
        5 => ['Minuman gratis', 'Roti Kukus gratis', 'Roti Kukus'],
        10 => ['Makanan gratis', 'Minuman gratis', 'Minuman'],
    ];

    /** The short names before this migration, for down(). */
    private const OLD_SHORT = [5 => 'Minuman', 10 => 'Makanan'];

    public function up(): void
    {
        foreach (self::RENAMES as $stamps => [$old, $new, $short]) {
            DB::table('loyalty_rewards')
                ->where('stamps_required', $stamps)
                ->where('reward_name', $old)
                ->update(['reward_name' => $new, 'short_name' => $short, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $stamps => [$old, $new]) {
            DB::table('loyalty_rewards')
                ->where('stamps_required', $stamps)
                ->where('reward_name', $new)
                ->update(['reward_name' => $old, 'short_name' => self::OLD_SHORT[$stamps], 'updated_at' => now()]);
        }
    }
};
