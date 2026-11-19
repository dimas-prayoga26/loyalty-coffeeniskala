<?php

use App\Enums\LoyaltyCardStatus;
use App\Http\Controllers\CustomerVerificationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/verify');

Route::controller(CustomerVerificationController::class)->group(function () {
    Route::get('/verify', 'create')->name('verify.create');
    Route::post('/verify', 'store')->middleware('throttle:10,1')->name('verify.store');
    Route::get('/verify-success/{customerCode}', 'success')->name('verify.success');
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
                'customerCode' => 'NSK-000003',
                'cardNumber' => 'NSK-000003-01',
                'phone' => '6285711122233',
                'stamps' => [],
                'status' => LoyaltyCardStatus::Active,
            ],
            [
                'title' => 'Hampir dapat minuman',
                'description' => '4 stamp: tinggal 1 stamp lagi untuk minuman gratis.',
                'customerName' => 'Andi Pratama',
                'customerCode' => 'NSK-000005',
                'cardNumber' => 'NSK-000005-01',
                'phone' => '6282233344455',
                'stamps' => $daysAgo(26, 19, 12, 5),
                'status' => LoyaltyCardStatus::Active,
            ],
            [
                'title' => 'Minuman gratis terbuka',
                'description' => 'Stamp ke-5 baru saja ditambahkan: minuman gratis siap ditukar.',
                'customerName' => 'Budi Santoso',
                'customerCode' => 'NSK-000001',
                'cardNumber' => 'NSK-000001-01',
                'phone' => '6281234567890',
                'stamps' => $daysAgo(40, 31, 24, 15, 0),
                'status' => LoyaltyCardStatus::Active,
                'freshStamp' => true,
            ],
            [
                'title' => 'Minuman sudah ditukar',
                'description' => 'Minuman gratis sudah diambil, stamp lanjut menuju makanan gratis.',
                'customerName' => 'Dewi Lestari',
                'customerCode' => 'NSK-000004',
                'cardNumber' => 'NSK-000004-01',
                'phone' => '6281399988877',
                'stamps' => $daysAgo(50, 43, 36, 29, 22, 12, 4),
                'status' => LoyaltyCardStatus::Active,
                'redeemedRewards' => [5],
            ],
            [
                'title' => 'Kartu penuh',
                'description' => 'Stamp ke-10 terkumpul: makanan gratis siap ditukar.',
                'customerName' => 'Sista Ayu',
                'customerCode' => 'NSK-000002',
                'cardNumber' => 'NSK-000002-02',
                'phone' => '6289876543210',
                'stamps' => $daysAgo(62, 55, 48, 41, 33, 27, 20, 13, 7, 1),
                'status' => LoyaltyCardStatus::Active,
                'redeemedRewards' => [5],
            ],
            [
                'title' => 'Sudah ditukar',
                'description' => 'Kartu lama yang kedua hadiahnya sudah diambil.',
                'customerName' => 'Sista Ayu',
                'customerCode' => 'NSK-000002',
                'cardNumber' => 'NSK-000002-01',
                'phone' => '6289876543210',
                'stamps' => $daysAgo(140, 128, 119, 110, 101, 95, 88, 80, 71, 66),
                'status' => LoyaltyCardStatus::Redeemed,
            ],
        ]]);
    })->name('preview.stamp-card');
}
