<?php

namespace App\Models\Pos;

use App\Models\Concerns\ReadOnlyModel;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A POS account (users table, owned by the POS): cashiers and admins who
 * stamp cards and redeem rewards. Read-only.
 */
#[Table('users')]
#[Hidden(['password'])]
class PosUser extends Authenticatable
{
    use ReadOnlyModel;

    /**
     * The POS users table has no remember_token column.
     */
    protected $rememberTokenName = '';
}
