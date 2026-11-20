<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Program Promo, switched on and off from the POS (Pengaturan → Program
 * Promo): the stamp card program, and its rewards kept in loyalty_rewards
 * instead of config (the config's rewards are only the starting list).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            // When it was last switched on: orders before it earn no stamp.
            $table->dateTime('active_since')->nullable();
            $table->timestamps();
        });

        DB::table('loyalty_programs')->insert([
            'code' => 'stamp_card',
            'name' => 'Stamp Card',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // One word printed inside the reward's slot on the card.
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->string('short_name', 30)->nullable()->after('reward_name');
        });

        foreach (config('loyalty.rewards', []) as $reward) {
            $existing = DB::table('loyalty_rewards')->where('stamps_required', $reward['stamps'])->first();

            if ($existing) {
                DB::table('loyalty_rewards')->where('id', $existing->id)->whereNull('short_name')
                    ->update(['short_name' => $reward['short'] ?? null]);

                continue;
            }

            DB::table('loyalty_rewards')->insert([
                'stamps_required' => $reward['stamps'],
                'reward_name' => $reward['label'],
                'short_name' => $reward['short'] ?? null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->dropColumn('short_name');
        });

        Schema::dropIfExists('loyalty_programs');
    }
};
