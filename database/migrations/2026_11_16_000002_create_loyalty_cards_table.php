<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_customer_id')
                ->constrained('loyalty_customers')
                ->restrictOnDelete();
            $table->string('card_number', 30)->unique();
            $table->unsignedSmallInteger('total_stamps')->default(0);
            $table->string('status', 20)->default('active');
            // 1 for the active card, NULL otherwise. MySQL has no partial
            // unique index; a unique index ignores NULLs, so the unique key
            // below allows any number of old cards but only one active card.
            $table->unsignedTinyInteger('active_flag')
                ->nullable()
                ->storedAs("(case when `status` = 'active' then 1 else null end)");
            $table->timestamps();

            $table->unique(['loyalty_customer_id', 'active_flag'], 'loyalty_cards_one_active_per_customer');
            $table->index(['loyalty_customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_cards');
    }
};
