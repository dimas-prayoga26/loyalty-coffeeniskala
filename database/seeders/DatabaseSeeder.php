<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Intentionally empty. The database is the POS's own (pos_live_raw):
     * seeders must never write to POS tables such as users or orders.
     */
    public function run(): void
    {
        //
    }
}
