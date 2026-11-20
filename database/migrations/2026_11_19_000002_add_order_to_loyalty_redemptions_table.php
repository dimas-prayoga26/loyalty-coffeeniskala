<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reward claimed in the POS: the order it went out with and the menu
 * given (its name kept, in case the menu is renamed or deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_redemptions', function (Blueprint $table) {
            $table->unsignedInteger('order_id')->nullable()->after('reward_id');
            $table->unsignedInteger('menu_item_id')->nullable()->after('order_id');
            $table->string('menu_name', 120)->nullable()->after('menu_item_id');

            $table->index('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_redemptions', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropIndex(['order_id']);
            $table->dropColumn(['order_id', 'menu_item_id', 'menu_name']);
        });
    }
};
