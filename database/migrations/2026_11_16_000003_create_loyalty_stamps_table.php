<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_stamps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_card_id')
                ->constrained('loyalty_cards')
                ->restrictOnDelete();
            // orders.id and users.id are INT UNSIGNED in the POS, so these
            // can't be foreignId() (BIGINT): FK column types must match.
            // Unique: one POS order can earn at most one stamp, ever.
            $table->unsignedInteger('order_id')->nullable()->unique();
            // Copy of orders.order_code, the trace left if the POS deletes
            // the order.
            $table->string('order_code', 40)->nullable();
            $table->unsignedTinyInteger('stamp_number');
            $table->unsignedInteger('created_by')->nullable();
            $table->dateTime('stamped_at');
            $table->timestamps();

            $table->unique(['loyalty_card_id', 'stamp_number']);

            // POS admins hard-delete orders (DELETE FROM orders). RESTRICT
            // would make that fail; SET NULL keeps it working and keeps the
            // stamp.
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_stamps');
    }
};
