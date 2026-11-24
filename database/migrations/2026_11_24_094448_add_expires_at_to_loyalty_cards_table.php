<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * loyalty_cards.expires_at: a card is valid for config('loyalty.card_valid_months')
 * months from when its first stamp was issued (loyalty_stamps.created_at),
 * through the end of that day. NULL until the card's first stamp. Cards
 * stamped before this column get theirs from the stamps they already have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_cards', function (Blueprint $table) {
            $table->dateTime('expires_at')->nullable()->after('status');
        });

        $this->backfill();
    }

    /**
     * Cards that have stamps but no expiry yet: from their first stamp's
     * issue (created_at), whatever date the stamp itself carries.
     */
    public function backfill(): void
    {
        $months = (int) config('loyalty.card_valid_months');

        DB::table('loyalty_stamps')
            ->join('loyalty_cards', 'loyalty_cards.id', '=', 'loyalty_stamps.loyalty_card_id')
            ->whereNull('loyalty_cards.expires_at')
            ->groupBy('loyalty_stamps.loyalty_card_id')
            ->selectRaw('loyalty_stamps.loyalty_card_id as card_id, min(loyalty_stamps.created_at) as first_issued_at')
            ->pluck('first_issued_at', 'card_id')
            ->each(function (string $firstIssuedAt, int $cardId) use ($months): void {
                DB::table('loyalty_cards')->where('id', $cardId)->update([
                    'expires_at' => Carbon::parse($firstIssuedAt)->addMonthsNoOverflow($months)->endOfDay(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('loyalty_cards', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
