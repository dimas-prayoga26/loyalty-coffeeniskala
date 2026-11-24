<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Customer Code Prefix
    |--------------------------------------------------------------------------
    |
    | Loyalty customer codes look like NSK-000001 (prefix + zero-padded id).
    | Card numbers append the card's sequence for that customer:
    | NSK-000001-01, NSK-000001-02, ...
    |
    */

    'customer_code_prefix' => env('LOYALTY_CUSTOMER_CODE_PREFIX', 'NSK'),

    /*
    |--------------------------------------------------------------------------
    | Stamps Per Card
    |--------------------------------------------------------------------------
    |
    | Number of slots shown on a card. For now this is display only; the
    | stamp rules (still to be specified) must use the same number.
    |
    */

    'stamps_per_card' => (int) env('LOYALTY_STAMPS_PER_CARD', 10),

    /*
    |--------------------------------------------------------------------------
    | Card Validity
    |--------------------------------------------------------------------------
    |
    | A card is valid for this many months from when its first stamp is
    | issued (recorded), up to the end of that day: first stamp issued 30
    | November -> valid through 28 February. Stored in
    | loyalty_cards.expires_at; a card without stamps has no expiry yet.
    |
    */

    'card_valid_months' => (int) env('LOYALTY_CARD_VALID_MONTHS', 3),

    /*
    |--------------------------------------------------------------------------
    | POS API
    |--------------------------------------------------------------------------
    |
    | pos-backend calls /api/pos/* with "Authorization: Bearer <token>". The
    | same value goes in pos-backend's LOYALTY_API_TOKEN. Empty: the API
    | answers 503 to everyone.
    |
    */

    'pos_api_token' => env('LOYALTY_POS_API_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Reward Voucher QR
    |--------------------------------------------------------------------------
    |
    | The QR on the back of a card changes every this many seconds (the
    | customer's page fetches the new one). The cashier can scan the one
    | shown now or the one just replaced; older ones (a screenshot) are
    | refused.
    |
    */

    'voucher_qr_seconds' => (int) env('LOYALTY_VOUCHER_QR_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Stamp Rules (sementara)
    |--------------------------------------------------------------------------
    |
    | A POS order earns one stamp when it has a customer phone, its type is
    | listed here (the POS only keeps the phone of Offline orders) and its
    | total_with_tax (what the customer pays) is at least the minimum.
    |
    */

    'stamp_order_types' => ['Offline'],

    'stamp_min_order_total' => (int) env('LOYALTY_STAMP_MIN_ORDER_TOTAL', 25000),

    // At most this many stamps per phone number per day, counted by the
    // order's date (Asia/Jakarta): a second order the same day earns none.
    // 0: no limit.
    'max_stamps_per_day' => (int) env('LOYALTY_MAX_STAMPS_PER_DAY', 1),

    // loyalty:sync-stamps looks this many days back for orders that never
    // got their stamp (the loyalty app was down when the POS called).
    'sync_days' => (int) env('LOYALTY_SYNC_DAYS', 2),

    // Demo/test numbers accepted although they are not a real mobile number
    // (e.g. 0987654321), comma separated. Leave empty in production.
    'test_numbers' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('LOYALTY_TEST_NUMBERS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Rewards (starting list only)
    |--------------------------------------------------------------------------
    |
    | The rewards live in loyalty_rewards and are managed from the POS
    | (Pengaturan → Program Promo: add, switch on/off, rename); this list only
    | fills that table once, on the first migrate. Each reward is redeemed on
    | its own: the free roti kukus at stamp 5 does not reset the card.
    |
    | stamps: the stamp that unlocks it (its slot is marked on the card)
    | label:  full name, shown in the reward list
    | short:  one or two words, printed inside the reward slot
    |
    | Claimed in the POS with the Kategori Promo menu marked for its stamp
    | (a free promo whose roti kukus / coffee the cashier picks): redeemed when
    | the order is saved, linked to the order and that promo menu.
    |
    */

    'rewards' => [
        ['stamps' => 5, 'label' => 'Roti Kukus gratis', 'short' => 'Roti Kukus'],
        ['stamps' => 10, 'label' => 'Minuman gratis', 'short' => 'Minuman'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stamp Images
    |--------------------------------------------------------------------------
    |
    | Product photos shown in the stamp slots of a card, in slot order: the
    | first image goes in slot 1. A card with more slots than images repeats
    | the list. Files are 400px square WebP in public/images/stamps.
    |
    | Slots 1-5 lead to the roti kukus (stamp 5), 6-10 to the drink (stamp
    | 10), so each half shows its reward.
    |
    */

    'stamp_images' => [
        ['file' => 'images/stamps/roti-kukus-original-coklat.webp', 'label' => 'Roti Kukus Original Coklat'],
        ['file' => 'images/stamps/roti-kukus-original-strawberry.webp', 'label' => 'Roti Kukus Original Strawberry'],
        ['file' => 'images/stamps/roti-kukus-pandan-coklat.webp', 'label' => 'Roti Kukus Pandan Coklat'],
        ['file' => 'images/stamps/roti-kukus-pandan-stroberi.webp', 'label' => 'Roti Kukus Pandan Stroberi'],
        ['file' => 'images/stamps/roti-kukus-pandan-tiramisu.webp', 'label' => 'Roti Kukus Pandan Tiramisu'],
        ['file' => 'images/stamps/americano.webp', 'label' => 'Americano'],
        ['file' => 'images/stamps/aren-latte.webp', 'label' => 'Aren Latte'],
        ['file' => 'images/stamps/butterscotch-latte.webp', 'label' => 'Butterscotch Latte'],
        ['file' => 'images/stamps/matcha-latte.webp', 'label' => 'Matcha Latte'],
        ['file' => 'images/stamps/montblanc.webp', 'label' => 'Montblanc'],
    ],

];
