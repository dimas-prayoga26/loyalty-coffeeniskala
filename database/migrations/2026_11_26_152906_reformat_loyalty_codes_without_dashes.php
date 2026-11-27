<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Card numbers and customer codes lose their dashes and get a 4-digit
 * sequence (see App\Support\MonthlySequence): 26-11-002 becomes 26110002.
 * A code already taken by a newer one moves to the end of its month.
 * down() turns them back into 26-11-002.
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
                $this->rewrite($table, $column, '/^(\d{2})-(\d{2})-(\d+)$/', '%s%04d', fn (array $m) => [$m[1].$m[2], (int) $m[3]]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            foreach (self::COLUMNS as $table => $column) {
                $this->rewrite($table, $column, '/^(\d{2})(\d{2})(\d{4,})$/', '%s-%03d', fn (array $m) => [$m[1].'-'.$m[2], (int) $m[3]]);
            }
        });
    }

    /**
     * @param  string  $format  sprintf of the new code, from its prefix and sequence
     * @param  Closure(array<int, string>): array{0: string, 1: int}  $convert  the new prefix and sequence of a matched code
     */
    private function rewrite(string $table, string $column, string $pattern, string $format, Closure $convert): void
    {
        $rows = DB::table($table)->orderBy('id')->get(['id', $column])
            ->filter(fn (object $row) => preg_match($pattern, (string) $row->{$column}));

        $taken = DB::table($table)->whereNotIn('id', $rows->pluck('id'))->pluck($column)->flip()->all();

        foreach ($rows as $row) {
            preg_match($pattern, (string) $row->{$column}, $match);
            [$prefix, $sequence] = $convert($match);
            $code = sprintf($format, $prefix, $sequence);

            while (isset($taken[$code])) {
                $code = sprintf($format, $prefix, ++$sequence);
            }

            $taken[$code] = true;
            DB::table($table)->where('id', $row->id)->update([$column => $code]);
        }
    }
};
