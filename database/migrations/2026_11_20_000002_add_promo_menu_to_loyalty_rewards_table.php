<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The POS menu (Kategori Promo, Promo loyalty) Tukar gives for a reward,
 * set in Pengaturan → Program Promo. A deleted menu leaves the reward
 * without one (SET NULL) until another is picked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->unsignedInteger('promo_menu_item_id')->nullable()->after('short_name');
            $table->foreign('promo_menu_item_id')->references('id')->on('menu_items')->nullOnDelete();
        });

        // Menus the POS marked for a reward's stamp before (menu_items.
        // loyalty_reward_stamps) keep giving that reward.
        if (Schema::hasColumn('menu_items', 'loyalty_reward_stamps')) {
            foreach (DB::table('menu_items')->whereNotNull('loyalty_reward_stamps')->get(['id', 'loyalty_reward_stamps']) as $menu) {
                DB::table('loyalty_rewards')
                    ->where('stamps_required', $menu->loyalty_reward_stamps)
                    ->whereNull('promo_menu_item_id')
                    ->update(['promo_menu_item_id' => $menu->id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->dropForeign(['promo_menu_item_id']);
            $table->dropColumn('promo_menu_item_id');
        });
    }
};
