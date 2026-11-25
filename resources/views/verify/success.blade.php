<x-layouts.app title="Kartu Stamp Saya" noindex>
    <main class="mx-auto w-full max-w-md px-3 py-10 sm:px-4 sm:py-16">
        <x-brand.header />

        <div class="mt-9">
            <h1 class="font-display text-2xl">Halo, {{ $customerName ?: 'Pelanggan Niskala' }}</h1>
            <p class="mt-1.5 text-sm text-espresso-600 dark:text-cream-200/70">
                {{ $locked ? 'Kartu stamp Anda terbuka setelah PIN diganti.' : 'Ini kartu stamp Anda.' }}
            </p>
            @if (session('status'))
                <p class="page-note mt-4" role="status">{{ session('status') }}</p>
            @endif
            @if ($cardExpired)
                <p class="page-note mt-4">
                    Masa berlaku kartu ini sudah habis. Stamp mulai lagi dari 0 di order berikutnya.
                </p>
            @endif
        </div>

        @if ($locked)
            {{-- Still the first PIN: the print on the card is blurred and a
                 slip on top asks for a new PIN. The controller sends no
                 stamp dates for this view. --}}
            <div class="pin-gate mt-6">
                <div class="pin-gate__behind" aria-hidden="true" inert>
                    @foreach ($cards as $card)
                        <x-loyalty.stamp-card
                            :customer-code="$card['customerCode']"
                            :card-number="$card['cardNumber']"
                            :customer-name="$card['customerName']"
                            :phone="$card['phone']"
                            :stamps="$card['stamps']"
                            :slots="$card['slots']"
                            :status="$card['status']"
                            :redeemed-rewards="$card['redeemedRewards']"
                            :redeemed-at="$card['redeemedAt'] ?? []"
                            :expires-at="$card['expiresAt'] ?? null"
                        />
                    @endforeach
                </div>

                <form
                    method="POST"
                    action="{{ route('verify.pin', $customerCode) }}"
                    novalidate
                    class="paper-card verify-card pin-gate__form"
                    aria-labelledby="pin-title"
                >
                    @csrf

                    <div class="paper-card__frame">
                        <h2 id="pin-title" class="verify-card__title">Ganti PIN dulu</h2>

                        <div class="pin-gate__fields">
                            <div>
                                <label for="new_pin" class="verify-card__label">PIN baru</label>
                                <input
                                    id="new_pin"
                                    name="new_pin"
                                    type="password"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    autocomplete="new-password"
                                    placeholder="4 angka"
                                    maxlength="4"
                                    required
                                    class="verify-card__input verify-card__input--pin"
                                    data-pin-input
                                    @error('new_pin') aria-invalid="true" aria-describedby="new-pin-error" @enderror
                                >
                            </div>
                            <div>
                                <label for="new_pin_confirmation" class="verify-card__label">Ulangi PIN baru</label>
                                <input
                                    id="new_pin_confirmation"
                                    name="new_pin_confirmation"
                                    type="password"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    autocomplete="new-password"
                                    placeholder="4 angka"
                                    maxlength="4"
                                    required
                                    class="verify-card__input verify-card__input--pin"
                                    data-pin-input
                                    @error('new_pin') aria-invalid="true" aria-describedby="new-pin-error" @enderror
                                >
                            </div>
                        </div>
                        @error('new_pin')
                            <p id="new-pin-error" class="verify-card__error" role="alert">{{ $message }}</p>
                        @enderror

                        <button type="submit" class="verify-card__submit mt-6">Simpan PIN dan buka kartu</button>
                    </div>
                </form>
            </div>
        @else
            {{-- Current card first, then full cards with a reward still to claim. --}}
            @foreach ($cards as $card)
                <x-loyalty.stamp-card
                    class="mt-6"
                    :customer-code="$card['customerCode']"
                    :card-number="$card['cardNumber']"
                    :customer-name="$card['customerName']"
                    :phone="$card['phone']"
                    :stamps="$card['stamps']"
                    :slots="$card['slots']"
                    :status="$card['status']"
                    :redeemed-rewards="$card['redeemedRewards']"
                    :redeemed-at="$card['redeemedAt'] ?? []"
                    :expires-at="$card['expiresAt'] ?? null"
                />
            @endforeach
        @endif

        <p class="mt-8 flex flex-wrap justify-center gap-x-6 gap-y-3 text-center text-sm">
            {{-- The first PIN is changed in the slip on the card above. --}}
            @unless ($locked)
                <a
                    href="{{ route('verify.pin.edit', $customerCode) }}"
                    class="font-medium text-gold-500 underline decoration-gold-500/40 underline-offset-4 hover:decoration-gold-500 dark:text-gold-300 dark:decoration-gold-300/40"
                >
                    Ganti PIN
                </a>
            @endunless
            <a
                href="{{ route('verify.create') }}"
                class="font-medium text-gold-500 underline decoration-gold-500/40 underline-offset-4 hover:decoration-gold-500 dark:text-gold-300 dark:decoration-gold-300/40"
            >
                Bukan Anda? Cek nomor lain
            </a>
        </p>
    </main>
</x-layouts.app>
