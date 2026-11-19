<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_customer_id')
                ->constrained('loyalty_customers')
                ->restrictOnDelete();
            $table->foreignId('loyalty_card_id')
                ->constrained('loyalty_cards')
                ->restrictOnDelete();
            $table->foreignId('reward_id')
                ->constrained('loyalty_rewards')
                ->restrictOnDelete();
            // POS users.id is INT UNSIGNED.
            $table->unsignedInteger('redeemed_by')->nullable();
            $table->dateTime('redeemed_at');
            $table->timestamps();

            $table->foreign('redeemed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_redemptions');
    }
};
