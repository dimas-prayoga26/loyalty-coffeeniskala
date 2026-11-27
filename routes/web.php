<?php

use App\Enums\LoyaltyCardStatus;
use App\Http\Controllers\CustomerVerificationController;
use Illuminate\Support\Facades\Route;

// Not Route::redirect: its relative URL drops the folder the app is served
// from (production: coffeeniskala.com/loyalty), sending / to the site's /verify.
Route::get('/', fn () => redirect()->route('verify.create'));

Route::controller(CustomerVerificationController::class)->group(function () {
    Route::get('/verify', 'create')->name('verify.create');
    Route::post('/verify', 'store')->middleware('throttle:10,1')->name('verify.store');
    Route::get('/verify/pin', 'pinForm')->name('verify.pin.create');
    Route::post('/verify/pin', 'confirmPin')->middleware('throttle:10,1')->name('verify.pin.store');
    Route::get('/verify-success/{customerCode}', 'success')->name('verify.success');
    Route::post('/verify-success/{customerCode}/pin', 'updatePin')->middleware('throttle:10,1')->name('verify.pin');
    Route::get('/verify-success/{customerCode}/pin', 'editPin')->name('verify.pin.edit');
    Route::put('/verify-success/{customerCode}/pin', 'replacePin')->middleware('throttle:10,1')->name('verify.pin.update');
});

// Design preview of the stamp card with sample data. Local only.
if (app()->isLocal()) {
    Route::get('/preview/stamp-card', function () {
        $daysAgo = fn (int ...$days) => array_map(fn ($d) => now()->subDays($d), $days);

        return view('loyalty.stamp-card-preview', ['samples' => [
            [
                'title' => 'Kartu baru',
                'description' => 'Pelanggan baru terdaftar, belum ada stamp.',
                'customerName' => 'Rina Maharani',
                'customerCode' => '26110003',
                'cardNumber' => '26110003',
                'phone' => '6285711122233',
                'stamps' => [],
                'status' => LoyaltyCardStatus::Active,
            ],
            [
                'title' => 'Hampir dapat roti kukus',
                'description' => '4 stamp: tinggal 1 stamp lagi untuk roti kukus gratis.',
                'customerName' => 'Andi Pratama',
                'customerCode' => '26110005',
                'cardNumber' => '26110005',
                'phone' => '6282233344455',
                'stamps' => $daysAgo(26, 19, 12, 5),
                'status' => LoyaltyCardStatus::Active,
            ],
            [
                'title' => 'Roti kukus gratis terbuka',
                'description' => 'Stamp ke-5 baru saja ditambahkan: roti kukus gratis siap ditukar.',
                'customerName' => 'Budi Santoso',
                'customerCode' => '26110001',
                'cardNumber' => '26110001',
                'phone' => '6281234567890',
                'stamps' => $daysAgo(40, 31, 24, 15, 0),
                'status' => LoyaltyCardStatus::Active,
                'freshStamp' => true,
            ],
            [
                'title' => 'Roti kukus sudah ditukar',
                'description' => 'Roti kukus gratis sudah diambil, stamp lanjut menuju minuman gratis.',
                'customerName' => 'Dewi Lestari',
                'customerCode' => '26110004',
                'cardNumber' => '26110004',
                'phone' => '6281399988877',
                'stamps' => $daysAgo(50, 43, 36, 29, 22, 12, 4),
                'status' => LoyaltyCardStatus::Active,
                'redeemedRewards' => [5],
                'redeemedAt' => [5 => now()->subDays(20)->setTime(14, 35)],
            ],
            [
                'title' => 'Kartu penuh',
                'description' => 'Stamp ke-10 terkumpul: minuman gratis siap ditukar.',
                'customerName' => 'Sista Ayu',
                'customerCode' => '26110002',
                'cardNumber' => '26110006',
                'phone' => '6289876543210',
                'stamps' => $daysAgo(62, 55, 48, 41, 33, 27, 20, 13, 7, 1),
                'status' => LoyaltyCardStatus::Active,
                'redeemedRewards' => [5],
                'redeemedAt' => [5 => now()->subDays(30)->setTime(9, 12)],
            ],
            [
                'title' => 'Sudah ditukar',
                'description' => 'Kartu lama yang kedua hadiahnya sudah diambil.',
                'customerName' => 'Sista Ayu',
                'customerCode' => '26110002',
                'cardNumber' => '26110002',
                'phone' => '6289876543210',
                'stamps' => $daysAgo(140, 128, 119, 110, 101, 95, 88, 80, 71, 66),
                'status' => LoyaltyCardStatus::Redeemed,
                'redeemedAt' => [5 => now()->subDays(98)->setTime(16, 5), 10 => now()->subDays(64)->setTime(11, 48)],
            ],
        ]]);
    })->name('preview.stamp-card');
}
