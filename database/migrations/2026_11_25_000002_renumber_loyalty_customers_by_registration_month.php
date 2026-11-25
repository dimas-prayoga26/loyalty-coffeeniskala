<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customer codes become month-year-sequence like card numbers (11-2026-001,
 * see App\Support\MonthlySequence): the month each customer registered
 * (created_at) and their place among that month's customers, oldest first.
 * Everything points at the customer id, so only customer_code changes; a
 * customer signed in on the card page signs in again (the session holds the
 * code). down() gives back the old codes: NSK + the zero-padded id.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $customers = DB::table('loyalty_customers')->orderBy('created_at')->orderBy('id')->get(['id', 'created_at']);
            $this->clearCodes($customers->pluck('id'));

            $sequence = [];
            foreach ($customers as $customer) {
                $prefix = Carbon::parse($customer->created_at ?? now())->format('m-Y');
                $sequence[$prefix] = ($sequence[$prefix] ?? 0) + 1;
                DB::table('loyalty_customers')->where('id', $customer->id)->update([
                    'customer_code' => sprintf('%s-%03d', $prefix, $sequence[$prefix]),
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $ids = DB::table('loyalty_customers')->orderBy('id')->pluck('id');
            $this->clearCodes($ids);

            foreach ($ids as $id) {
                DB::table('loyalty_customers')->where('id', $id)->update(['customer_code' => sprintf('NSK-%06d', $id)]);
            }
        });
    }

    /** A temporary code first, so a new code never clashes with an old one (unique). */
    private function clearCodes($ids): void
    {
        foreach ($ids as $id) {
            DB::table('loyalty_customers')->where('id', $id)->update(['customer_code' => 'renumber-'.$id]);
        }
    }
};
