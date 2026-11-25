<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026_11_25_000001 / 000002 first ran with month-year codes (11-2026-001)
 * before MonthlySequence became year-month (26-11-001). This rewrites those
 * card numbers and customer codes: 11-2026-004 becomes 26-11-004. A code
 * already taken by a newer year-month one moves to the end of its month.
 * down() turns year-month codes back into month-year ones.
 */
return new class extends Migration
{
    /** @var array<string, string> table => code column */
    private const COLUMNS = [
        'loyalty_cards' => 'card_number',
        'loyalty_customers' => 'customer_code',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::COLUMNS as $table => $column) {
                $this->rewrite($table, $column, '/^(\d{2})-\d{2}(\d{2})-(\d+)$/', fn (array $m) => [$m[2].'-'.$m[1], (int) $m[3]]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            foreach (self::COLUMNS as $table => $column) {
                $this->rewrite($table, $column, '/^(\d{2})-(\d{2})-(\d+)$/', fn (array $m) => [$m[2].'-20'.$m[1], (int) $m[3]]);
            }
        });
    }

    /**
     * @param  Closure(array<int, string>): array{0: string, 1: int}  $convert  the new prefix and sequence of a matched code
     */
    private function rewrite(string $table, string $column, string $pattern, Closure $convert): void
    {
        $rows = DB::table($table)->orderBy('id')->get(['id', $column])
            ->filter(fn (object $row) => preg_match($pattern, (string) $row->{$column}));

        $taken = DB::table($table)->whereNotIn('id', $rows->pluck('id'))->pluck($column)->flip()->all();

        foreach ($rows as $row) {
            preg_match($pattern, (string) $row->{$column}, $match);
            [$prefix, $sequence] = $convert($match);
            $code = sprintf('%s-%03d', $prefix, $sequence);

            while (isset($taken[$code])) {
                $code = sprintf('%s-%03d', $prefix, ++$sequence);
            }

            $taken[$code] = true;
            DB::table($table)->where('id', $row->id)->update([$column => $code]);
        }
    }
};
