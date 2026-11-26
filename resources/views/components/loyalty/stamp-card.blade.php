{{--
    Niskala loyalty stamp card, drawn as the printed card a barista stamps
    (design: Kartu Loyalti Niskala): rust-brown card stock with cream print,
    a sunburst spot per stamp, the reward's picture on its own spot, the
    "Kumpulkan" lines and a box for the owner's name. Printed parts sit
    still; the things a person adds (product stickers, pen dates, the
    rubber TUKAR stamp) are the ones that move.

    <x-loyalty.stamp-card
        customer-code="26-11-001"
        card-number="26-11-001"
        customer-name="Budi"            (optional)
        phone="6281234567890"           (optional, normalized form)
        :stamps="$datesOfCollectedStamps" (Carbon|string, oldest first; null = date not shown)
        :slots="10"
        :status="$card->status"         (LoyaltyCardStatus or its value)
        :redeemed-rewards="[5]"         (stamp numbers whose reward was claimed)
        :redeemed-at="[5 => $date]"     (optional: when each of those was handed over)
        :expires-at="$card->expires_at" (optional: last day the card is valid)
        :fresh-stamp="true"             (the newest sticker is placed last, slower)
        :rewards="[...]"                (optional, defaults to the program's rewards: Pengaturan → Program Promo)
    />

    Slot images come from config('loyalty.stamp_images'), in slot order; the
    printed sunburst and reward pictures from public/images/card.
    Tapping a slot shows its details (resources/js/stamp-card.js).

    A card with a reward ready to claim has two sides: the voucher with the
    QR is printed on the back, and TUKAR turns the card over to it
    (resources/js/card-flip.js).
--}}
@props([
    'customerCode',
    'cardNumber',
    'customerName' => null,
    'phone' => null,
    'stamps' => [],
    'slots' => 10,
    'status' => \App\Enums\LoyaltyCardStatus::Active,
    'redeemedRewards' => [],
    'redeemedAt' => [],
    'expiresAt' => null,
    'freshStamp' => false,
    'rewards' => null,
])

@use('App\Enums\LoyaltyCardStatus')
@use('Illuminate\Support\Carbon')

@php
    $status = $status instanceof LoyaltyCardStatus ? $status : LoyaltyCardStatus::from($status);
    $stamps = collect($stamps)->values();
    $slots = max(1, (int) $slots);
    $collected = min($stamps->count(), $slots);
    $images = config('loyalty.stamp_images');
    $redeemed = collect($redeemedRewards)->map(fn ($stamp) => (int) $stamp);
    $redeemedAt = collect($redeemedAt)->filter()->map(fn ($date) => Carbon::parse($date)->locale(app()->getLocale()));

    // Each reward: locked -> ready (stamp reached) -> redeemed (claimed at
    // the cashier). A redeemed card has had all of them; on an expired card
    // the unclaimed ones are forfeited. A reward switched off in Program
    // Promo still shows where it was already claimed.
    $rewards = collect($rewards ?? \App\Services\Loyalty\StampCardProgram::rewards(all: true)
            ->filter(fn (array $reward) => $reward['active'] || $redeemed->contains($reward['stamps']))
            ->all())
        ->filter(fn (array $reward) => $reward['stamps'] >= 1 && $reward['stamps'] <= $slots)
        ->sortBy('stamps')
        ->map(function (array $reward) use ($collected, $status, $redeemed) {
            $state = match (true) {
                $status === LoyaltyCardStatus::Redeemed, $redeemed->contains($reward['stamps']) => 'redeemed',
                $status === LoyaltyCardStatus::Expired => 'expired',
                $collected >= $reward['stamps'] => 'ready',
                default => 'locked',
            };

            return [
                ...$reward,
                'state' => $state,
                'stateLabel' => match ($state) {
                    'redeemed' => 'Sudah ditukar',
                    'expired' => 'Hangus',
                    'ready' => 'Siap ditukar',
                    'locked' => ($reward['stamps'] - $collected).' stamp lagi',
                },
            ];
        })
        ->keyBy('stamps');

    // Fixed per-slot tilt: varied like stickers put on by hand, identical on every render.
    $tilts = [-7, 4, -3, 6, -5, 3, -6, 5, -2, 7];

    // What is printed on a spot (public/images/card): a sunburst, or on a
    // reward's spot its picture, a cup for a drink and a roti kukus for
    // food. A reward that is neither keeps the sunburst, with its name on it.
    $picture = fn (string $name) => asset('images/card/'.$name.'.svg');
    $rewardPicture = function (array $reward): ?string {
        $name = \Illuminate\Support\Str::lower($reward['label'].' '.$reward['short']);

        return match (true) {
            \Illuminate\Support\Str::contains($name, ['minum', 'kopi', 'coffee', 'latte', 'drink']) => 'minuman',
            \Illuminate\Support\Str::contains($name, ['roti', 'kue', 'bread', 'bun']) => 'roti-kukus',
            default => null,
        };
    };

    // As printed in "Kumpulkan": Roti Kukus gratis -> Roti Kukus GRATIS.
    $rewardTitle = fn (array $reward) => preg_replace('/\bgratis\b/iu', 'GRATIS', $reward['label']);

    $formatDate = fn ($date) => Carbon::parse($date)->locale(app()->getLocale());

    // 6281234567890 -> 0812••••7890
    $maskedPhone = filled($phone) ? \App\Support\WhatsAppNumber::masked($phone) : null;
    $identity = collect([$customerCode, $maskedPhone])->filter()->implode(' · ');

    // Validity, the card's fine print at the bottom: the last day, written
    // in with the first stamp ("Berakhir" once the card has expired); before
    // any stamp, the printed rule. Not on a card whose rewards were all taken.
    $validUntil = filled($expiresAt) && $status !== LoyaltyCardStatus::Redeemed ? $formatDate($expiresAt) : null;
    $validityRule = $validUntil === null && $collected === 0 && $status === LoyaltyCardStatus::Active
        ? 'Berlaku '.config('loyalty.card_valid_months').' bulan sejak stamp pertama'
        : null;

    // One voucher per ready reward, unique on the page: voucher-26-11-001-5;
    // the customer QR is stamps 0 (voucher-26-11-001-0).
    $voucherId = fn (array $reward) => 'voucher-'.\Illuminate\Support\Str::slug($cardNumber).'-'.$reward['stamps'];
    $memberVoucherId = $voucherId(['stamps' => \App\Services\Loyalty\RewardVoucher::MEMBER]);
    $memberCode = \App\Services\Loyalty\RewardVoucher::code($cardNumber, \App\Services\Loyalty\RewardVoucher::MEMBER);
    // What its QR holds: signed, one per reward of this card, works once.
    $voucherCode = fn (array $reward) => \App\Services\Loyalty\RewardVoucher::code($cardNumber, $reward['stamps']);
    $readyRewards = $rewards->where('state', 'ready');

    $statusMessage = match (true) {
        $status === LoyaltyCardStatus::Redeemed => 'Semua hadiah ditukar',
        $status === LoyaltyCardStatus::Expired => 'Kartu kedaluwarsa',
        $rewards->contains('state', 'ready') => 'Hadiah siap ditukar',
        default => 'stamp terkumpul',
    };

    $overlay = match ($status) {
        LoyaltyCardStatus::Redeemed => 'Ditukar',
        LoyaltyCardStatus::Expired => 'Kedaluwarsa',
        default => null,
    };
@endphp

<article
    {{ $attributes->class([
        'stamp-card',
        'has-back',
        'is-redeemed' => $status === LoyaltyCardStatus::Redeemed,
        'is-expired' => $status === LoyaltyCardStatus::Expired,
    ]) }}
    style="--collected: {{ $collected }}"
    aria-label="Kartu stamp {{ $cardNumber }}"
    data-stamp-card
>
    <div class="stamp-card__flipper" data-card-flipper>
        <div class="paper-card stamp-card__face" data-card-front>
            <div class="paper-card__frame">
                <header class="stamp-card__head">
                    <div class="stamp-card__brand">
                        <x-brand.logo class="stamp-card__logo" />
                        <div>
                            <p class="stamp-card__wordmark">NISKALA</p>
                            <p class="stamp-card__sub">Coffee</p>
                        </div>
                    </div>
                    <p class="stamp-card__number">
                        Kartu stamp
                        <span>No. {{ $cardNumber }}</span>
                    </p>
                </header>

                <ol class="stamp-grid" aria-label="{{ $collected }} dari {{ $slots }} stamp terkumpul">
                    @for ($i = 0; $i < $slots; $i++)
                        @php
                            $filled = $i < $collected;
                            $reward = $rewards->get($i + 1);
                            $printed = $reward ? $rewardPicture($reward) : null;
                            $image = $images[$i % count($images)];
                            $date = $filled && $stamps[$i] !== null ? $formatDate($stamps[$i]) : null;
                            $day = $date?->translatedFormat('j F Y');

                            // Shown when the slot is tapped or hovered.
                            [$tipTitle, $tipText] = match (true) {
                                $filled && $reward => ['Stamp ke-'.($i + 1).' · '.$reward['label'], collect([$day, $reward['stateLabel']])->filter()->implode(' · ')],
                                $filled => ['Stamp ke-'.($i + 1), trim('Terkumpul '.$day)],
                                $reward !== null => ['Stamp ke-'.($i + 1).' · '.$reward['label'], $reward['stateLabel']],
                                default => ['Stamp ke-'.($i + 1), 'Belum terkumpul'],
                            };
                        @endphp
                        <li
                            @class([
                                'stamp-slot',
                                'is-filled' => $filled,
                                'is-reward' => $reward,
                                'is-claimed' => $reward && $reward['state'] === 'redeemed',
                                'is-fresh' => $freshStamp && $filled && $i === $collected - 1,
                            ])
                            style="--tilt: {{ $tilts[$i % count($tilts)] }}deg; --i: {{ $i }}"
                        >
                            <button type="button" class="stamp-slot__button">
                                <span class="stamp-slot__spot" aria-hidden="true">
                                    @unless ($filled && $printed === 'minuman')
                                        <span
                                            @class([
                                                'stamp-slot__print',
                                                'stamp-slot__print--steam' => $filled && $i === 4 && $printed === 'roti-kukus',
                                            ])
                                            style="--picture: url('{{ $picture($printed ?? 'sunburst') }}')"
                                        ></span>
                                    @endunless
                                    @if ($reward && ! $printed)
                                        <span class="stamp-slot__free"><small>gratis</small>{{ $reward['short'] }}</span>
                                    @endif
                                    @if ($filled)
                                        <span class="stamp-slot__sticker" data-product="{{ $image['kind'] ?? '' }}">
                                            <img src="{{ asset($image['file']) }}" alt="" width="400" height="400" loading="lazy" decoding="async">
                                        </span>
                                    @endif
                                    {{-- Handed over: the cashier rings the reward's spot and
                                         stamps DITUKAR over the bottom of it. --}}
                                    @if ($reward && $reward['state'] === 'redeemed')
                                        <span class="stamp-slot__claimed">Ditukar</span>
                                    @endif
                                </span>
                                <span class="stamp-slot__date" aria-hidden="true">{{ $date?->format('j/n') }}</span>
                                <span class="sr-only">
                                    Stamp {{ $i + 1 }}{{ $reward ? ' (hadiah: '.$reward['label'].($reward['state'] === 'redeemed' ? ', sudah ditukar' : '').')' : '' }}:
                                    {{ $filled ? trim('terkumpul '.$day) : 'belum terkumpul' }}
                                </span>
                            </button>
                            {{-- Visual repeat of the button's accessible name. --}}
                            <span class="stamp-slot__tip" aria-hidden="true">
                                <strong>{{ $tipTitle }}</strong>
                                <span>{{ $tipText }}</span>
                            </span>
                        </li>
                    @endfor
                </ol>

                {{-- "Kumpulkan": the printed lines (5 stamp untuk Roti Kukus
                     GRATIS), each with where that reward stands under it. --}}
                @if ($rewards->isNotEmpty())
                    <div class="reward-list">
                        <p class="reward-list__title">Kumpulkan</p>
                        <ul class="reward-list__rows" aria-label="Hadiah">
                            @foreach ($rewards as $reward)
                                <li class="reward-row is-{{ $reward['state'] }}">
                                    <span class="reward-row__at">{{ $reward['stamps'] }}</span>
                                    <span class="reward-row__for">stamp untuk</span>
                                    <span class="reward-row__text">
                                        <span class="reward-row__label">{{ $rewardTitle($reward) }}</span>
                                        <span class="reward-row__status">
                                            @if ($reward['state'] === 'ready')
                                                <span class="reward-row__hint">Siap diambil</span>
                                                {{-- The rubber stamp is a button: it turns the card
                                                     over to this reward's voucher on the back. The
                                                     slot's tip keeps "Siap ditukar". --}}
                                                <button
                                                    type="button"
                                                    class="reward-row__claim"
                                                    data-card-flip="{{ $reward['stamps'] }}"
                                                    aria-controls="{{ $voucherId($reward) }}"
                                                    aria-expanded="false"
                                                >
                                                    <span class="reward-row__state">Tukar</span>
                                                    <span class="sr-only">{{ $reward['label'] }}</span>
                                                </button>
                                            @elseif ($reward['state'] === 'redeemed' && $redeemedAt->has($reward['stamps']))
                                                {{-- Noted in pen by the cashier who handed it over. --}}
                                                <span class="reward-row__state">
                                                    Ditukar {{ $redeemedAt[$reward['stamps']]->translatedFormat('j F Y, H.i') }}
                                                </span>
                                            @else
                                                <span class="reward-row__state">{{ $reward['stateLabel'] }}</span>
                                            @endif
                                        </span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- "Pemilik kartu:" and the cream box it is written in. --}}
                <footer class="stamp-card__foot">
                    <p class="stamp-card__owner-title">Pemilik kartu:</p>
                    <div class="stamp-card__owner-box">
                        <p class="stamp-card__owner">{{ $customerName ?: 'Pelanggan Niskala' }}</p>
                        <p class="stamp-card__count">{{ $collected }}<span>/{{ $slots }}</span></p>
                        <p class="stamp-card__meta">{{ $identity }}</p>
                        <p class="stamp-card__status">{{ $statusMessage }}</p>
                    </div>
                    <div class="stamp-card__bottom">
                        @if ($validUntil)
                            <p class="stamp-card__valid">
                                {{ $status === LoyaltyCardStatus::Expired ? 'Berakhir' : 'Berlaku s.d.' }}
                                <span class="stamp-card__valid-date">{{ $validUntil->translatedFormat('j F Y') }}</span>
                            </p>
                        @elseif ($validityRule)
                            <p class="stamp-card__valid">{{ $validityRule }}</p>
                        @endif
                        <button
                            type="button"
                            class="stamp-card__qr"
                            aria-label="Tunjukkan QR"
                            title="Tunjukkan QR"
                            data-card-flip="{{ \App\Services\Loyalty\RewardVoucher::MEMBER }}"
                            aria-controls="{{ $memberVoucherId }}"
                            aria-expanded="false"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" fill-rule="evenodd" d="M3 3h8v8H3zm2 2v4h4V5zm8-2h8v8h-8zm2 2v4h4V5zM3 13h8v8H3zm2 2v4h4v-4zm13-2h3v3h-3zm-5 0h3v3h-3zm3 3h2v2h-2zm2 2h3v3h-3zm-5 0h3v3h-3z"/></svg>
                        </button>
                    </div>
                </footer>
            </div>

            @if ($overlay)
                <div class="stamp-card__overlay" aria-hidden="true">
                    <span class="stamp-card__overlay-label">{{ $overlay }}</span>
                </div>
            @endif
        </div>

        {{-- The back of the card: what the customer shows at the till. First
             the customer QR (Tunjukkan QR, and a turn by hand), then one voucher
             per ready reward (TUKAR shows its own). Inert until the card is
             turned over. --}}
        <div class="paper-card stamp-card__face stamp-card__back" data-card-back inert>
            <div class="paper-card__frame">
                {{-- The card's back is printed with the Niskala flower. --}}
                <x-brand.logo class="reward-voucher__logo" />

                <section
                    id="{{ $memberVoucherId }}"
                    class="reward-voucher"
                    aria-labelledby="{{ $memberVoucherId }}-title"
                    data-voucher="{{ \App\Services\Loyalty\RewardVoucher::MEMBER }}"
                >
                    <p class="reward-voucher__from">Kartu stamp No. {{ $cardNumber }}</p>
                    <h2 id="{{ $memberVoucherId }}-title" class="reward-voucher__title" tabindex="-1" data-voucher-title>
                        {{ $customerName ?: 'Pelanggan Niskala' }}
                    </h2>
                    <p class="reward-voucher__meta">{{ $identity }}</p>

                    <div class="reward-voucher__qr-slot">
                        <figure
                            class="reward-voucher__qr"
                            role="img"
                            aria-label="Kode QR pelanggan untuk kasir"
                            data-voucher-qr="{{ $memberCode }}"
                            data-voucher-card="{{ $cardNumber }}"
                            data-voucher-stamps="{{ \App\Services\Loyalty\RewardVoucher::MEMBER }}"
                        >
                            <noscript>
                                <span class="reward-voucher__code">{{ $memberCode }}</span>
                            </noscript>
                        </figure>
                    </div>

                    <p class="reward-voucher__note">
                        Tunjukkan QR ini saat membayar. Kasir tidak perlu mencatat nomor HP Anda, stamp langsung masuk ke kartu ini.
                    </p>

                    <button type="button" class="reward-voucher__close">
                        Balik ke depan kartu
                    </button>
                </section>

                    @foreach ($readyRewards as $reward)
                        <section
                            id="{{ $voucherId($reward) }}"
                            class="reward-voucher"
                            aria-labelledby="{{ $voucherId($reward) }}-title"
                            data-voucher="{{ $reward['stamps'] }}" hidden
                        >
                            <p class="reward-voucher__from">Kartu stamp No. {{ $cardNumber }}</p>
                            <h2 id="{{ $voucherId($reward) }}-title" class="reward-voucher__title" tabindex="-1" data-voucher-title>
                                {{ $reward['label'] }}
                            </h2>
                            <p class="reward-voucher__meta">
                                Hadiah stamp ke-{{ $reward['stamps'] }} · {{ $customerName ?: 'Pelanggan Niskala' }}
                            </p>

                            {{-- The reward's own QR (RewardVoucher), drawn by
                                 resources/js/voucher-qr.js. It stays the same.
                                 The cashier scans it in the POS; it stops working once
                                 the reward is handed over. --}}
                            <div class="reward-voucher__qr-slot">
                                <figure
                                    class="reward-voucher__qr"
                                    role="img"
                                    aria-label="Kode QR untuk menukar {{ $reward['label'] }}"
                                    data-voucher-qr="{{ $voucherCode($reward) }}"
                                    data-voucher-card="{{ $cardNumber }}"
                                    data-voucher-stamps="{{ $reward['stamps'] }}"
                                >
                                    <noscript>
                                        <span class="reward-voucher__code">{{ $voucherCode($reward) }}</span>
                                    </noscript>
                                </figure>
                            </div>

                            <p class="reward-voucher__note">
                                Tunjukkan QR ini ke kasir saat memesan. Hadiahnya disajikan bersama pesanan Anda.
                            </p>

                            {{-- A tap anywhere on the back turns the card back. This
                                 button is for keyboards and screen readers: hidden
                                 until it gets keyboard focus. --}}
                            <button type="button" class="reward-voucher__close">
                                Balik ke depan kartu
                            </button>
                        </section>
                    @endforeach
            </div>
        </div>
    </div>
</article>
