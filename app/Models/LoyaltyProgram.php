<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A promo program switched on and off from the POS (Pengaturan → Program
 * Promo). active_since: when it was last switched on; orders before it
 * earn nothing.
 */
#[Fillable(['code', 'name', 'is_active', 'active_since'])]
class LoyaltyProgram extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'active_since' => 'datetime',
        ];
    }
}
