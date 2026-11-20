<?php

use App\Http\Controllers\Api\PosLoyaltyController;
use App\Http\Controllers\Api\PosProgramController;
use Illuminate\Support\Facades\Route;

// Server to server, from pos-backend only (Bearer LOYALTY_POS_API_TOKEN).
// Behind Cloudflare, /api/pos/* needs a WAF skip rule: a server can't pass
// a bot challenge.
Route::prefix('pos')
    ->middleware(['pos.api', 'throttle:300,1'])
    ->controller(PosLoyaltyController::class)
    ->group(function () {
        Route::get('/customers/{phone}', 'customer')->where('phone', '[^/]+');
        Route::post('/stamps', 'stamp');
        Route::post('/redemptions', 'redeem');
    });

// Pengaturan → Program Promo (pos-backend lets admins only).
Route::prefix('pos')
    ->middleware(['pos.api', 'throttle:300,1'])
    ->controller(PosProgramController::class)
    ->group(function () {
        Route::get('/program', 'show');
        Route::put('/program', 'update');
        Route::post('/rewards', 'storeReward');
        Route::put('/rewards/{reward}', 'updateReward')->whereNumber('reward');
        // The rewards handed over (POST /redemptions hands one over).
        Route::get('/redemptions', 'redemptions');
    });
