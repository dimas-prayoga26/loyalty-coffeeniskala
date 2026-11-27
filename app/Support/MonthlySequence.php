<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use OverflowException;

/**
 * Codes that read year, month and sequence without dashes, e.g. 26110002:
 * the year and month (app timezone, WIB) and the place among that month's
 * codes in 4 digits, from 0001 again every month. Used for customer codes
 * and card numbers. Call it inside the transaction that saves the code: the
 * lock keeps two cashiers from taking the same one, and a clash that still
 * happens fails on the unique index (the registration retries it).
 */
class MonthlySequence
{
    public static function next(Builder $query, string $column, ?CarbonInterface $at = null): string
    {
        $prefix = Carbon::instance($at ?? now())->timezone(config('app.timezone'))->format('ym');
        $last = $query
            ->where($column, 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck($column)
            ->filter(fn (string $code) => ctype_digit($code))
            ->map(fn (string $code) => (int) substr($code, strlen($prefix)))
            ->max();

        if ($last >= 9999) {
            throw new OverflowException("Nomor urut loyalty untuk periode {$prefix} sudah mencapai 9999.");
        }

        return sprintf('%s%04d', $prefix, ($last ?? 0) + 1);
    }
}
