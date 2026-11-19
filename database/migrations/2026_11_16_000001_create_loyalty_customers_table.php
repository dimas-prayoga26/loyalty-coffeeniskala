<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_customers', function (Blueprint $table) {
            $table->id();
            // Derived from the id (NSK-000001), so it is filled right after
            // the insert, inside the same transaction.
            $table->string('customer_code', 20)->nullable()->unique();
            // The cashier may only type a WhatsApp number at registration.
            $table->string('customer_name', 100)->nullable();
            // Normalized, digits only, with country code: 6281234567890.
            $table->string('phone_number', 20)->unique();
            $table->string('email', 150)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_customers');
    }
};
