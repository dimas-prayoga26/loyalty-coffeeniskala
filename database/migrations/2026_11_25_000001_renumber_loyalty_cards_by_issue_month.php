<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Card numbers become year-month-sequence (26-11-001, see
 * LoyaltyCard::nextCardNumber()): the month each card was issued
 * (created_at) and its place among that month's cards, oldest first.
 * Only card_number changes; stamps and redemptions point at the card id.
 * down() rebuilds the old numbers (customer code + the card's place among
 * the customer's cards, NSK-000004-01).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $cards = DB::table('loyalty_cards')->orderBy('created_at')->orderBy('id')->get(['id', 'created_at']);
            $this->clearNumbers($cards->pluck('id'));

            $sequence = [];
            foreach ($cards as $card) {
                $prefix = Carbon::parse($card->created_at ?? now())->format('y-m');
                $sequence[$prefix] = ($sequence[$prefix] ?? 0) + 1;
                DB::table('loyalty_cards')->where('id', $card->id)->update([
                    'card_number' => sprintf('%s-%03d', $prefix, $sequence[$prefix]),
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $cards = DB::table('loyalty_cards')
                ->join('loyalty_customers', 'loyalty_customers.id', '=', 'loyalty_cards.loyalty_customer_id')
                ->orderBy('loyalty_cards.loyalty_customer_id')
                ->orderBy('loyalty_cards.id')
                ->get(['loyalty_cards.id', 'loyalty_cards.loyalty_customer_id', 'loyalty_customers.customer_code']);
            $this->clearNumbers($cards->pluck('id'));

            $sequence = [];
            foreach ($cards as $card) {
                $sequence[$card->loyalty_customer_id] = ($sequence[$card->loyalty_customer_id] ?? 0) + 1;
                DB::table('loyalty_cards')->where('id', $card->id)->update([
                    'card_number' => sprintf('%s-%02d', $card->customer_code, $sequence[$card->loyalty_customer_id]),
                ]);
            }
        });
    }

    /** A temporary number first, so a new number never clashes with an old one (unique). */
    private function clearNumbers($ids): void
    {
        foreach ($ids as $id) {
            DB::table('loyalty_cards')->where('id', $id)->update(['card_number' => 'renumber-'.$id]);
        }
    }
};
