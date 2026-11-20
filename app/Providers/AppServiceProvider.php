<?php

namespace App\Providers;

use App\Support\WhatsAppNumber;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->protectPosDatabase();

        WhatsAppNumber::allowTestNumbers(config('loyalty.test_numbers', []));
    }

    /**
     * The app shares its database with the live POS.
     *
     * migrate:rollback/reset/refresh only run the down() of loyalty
     * migrations, so they only drop loyalty_* tables: allowed outside
     * production. migrate:fresh and db:wipe drop EVERY table in the
     * database, POS tables included, so they are never allowed. The fresh
     * command is also what RefreshDatabase / DatabaseMigrations run in tests.
     */
    private function protectPosDatabase(): void
    {
        DB::prohibitDestructiveCommands($this->app->isProduction());

        FreshCommand::prohibit();
        WipeCommand::prohibit();
    }
}
