<?php

namespace App\Console\Commands;

use App\Exceptions\LoyaltyRuleException;
use App\Models\Pos\PosOrder;
use App\Services\Loyalty\LoyaltyStampService;
use App\Services\Loyalty\StampCardProgram;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * The POS asks for a stamp right after saving an order, without waiting.
 * When that call is lost (loyalty down, network), this gives the stamp
 * later. Scheduled every 5 minutes in routes/console.php.
 */
#[Signature('loyalty:sync-stamps {--days= : How many days back (default: config loyalty.sync_days)}')]
#[Description('Beri stamp ke order POS ber-nomor HP yang belum dapat stamp')]
class SyncLoyaltyStamps extends Command
{
    public function handle(LoyaltyStampService $stamps): int
    {
        // Program Promo off: nothing earns a stamp.
        if (! StampCardProgram::isActive()) {
            $this->info('Program Stamp Card tidak aktif: tidak ada stamp yang diberikan.');

            return self::SUCCESS;
        }

        $days = (int) ($this->option('days') ?? config('loyalty.sync_days'));

        $orders = PosOrder::query()
            ->whereNotNull('customer_phone')
            ->where('customer_phone', '<>', '')
            ->whereIn('order_type', config('loyalty.stamp_order_types'))
            ->where('order_date', '>=', now()->subDays(max(1, $days))->startOfDay())
            ->whereDoesntHave('loyaltyStamp')
            ->orderBy('id')
            ->get();

        $given = 0;
        $skipped = 0;

        foreach ($orders as $order) {
            if (! $stamps->isEligible($order)) {
                $skipped++;

                continue;
            }

            try {
                $stamps->stampOrder($order->id)->created ? $given++ : $skipped++;
            } catch (LoyaltyRuleException $e) {
                $skipped++;
                $this->line("#{$order->id}: {$e->getMessage()}");
            } catch (Throwable $e) {
                $skipped++;
                report($e);
                $this->error("#{$order->id}: {$e->getMessage()}");
            }
        }

        $this->info("Stamp diberikan: {$given}, dilewati: {$skipped}.");

        return self::SUCCESS;
    }
}
