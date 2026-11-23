<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_customers', function (Blueprint $table) {
            // Never filled: the cashier only types a number.
            $table->dropColumn('email');

            // The customer's own PIN for /verify, as a bcrypt hash. NULL means
            // the first PIN, the last 4 digits of phone_number; /verify asks
            // the customer to change it before showing the card. Setting it
            // back to NULL resets a forgotten PIN.
            $table->string('pin')
                ->nullable()
                ->after('phone_number')
                ->comment('Hash bcrypt. NULL = PIN awal (4 angka terakhir nomor HP), wajib diganti. Set NULL untuk reset.');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_customers', function (Blueprint $table) {
            $table->dropColumn('pin');
            $table->string('email', 150)->nullable()->after('phone_number');
        });
    }
};
