<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Codes that read month-year-sequence, e.g. 11-2026-001: the month (app
 * timezone, WIB) and the place among that month's codes, from 001 again
 * every month. Used for customer codes and card numbers. Call it inside the
 * transaction that saves the code: the lock keeps two cashiers from taking
 * the same one, and a clash that still happens fails on the unique index
 * (the registration retries it).
 */
class MonthlySequence
{
    public static function next(Builder $query, string $column, ?CarbonInterface $at = null): string
    {
        $prefix = Carbon::instance($at ?? now())->timezone(config('app.timezone'))->format('m-Y');
        $last = $query
            ->where($column, 'like', $prefix.'-%')
            ->lockForUpdate()
            ->pluck($column)
            ->map(fn (string $code) => (int) substr(strrchr($code, '-'), 1))
            ->max();

        return sprintf('%s-%03d', $prefix, ($last ?? 0) + 1);
    }
}
