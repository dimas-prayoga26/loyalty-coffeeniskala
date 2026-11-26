<x-layouts.app title="Ganti PIN" noindex>
    <main class="mx-auto w-full max-w-md px-3 py-10 sm:px-4 sm:py-16">
        <x-brand.header />

        {{-- A PIN the customer set: the old one first, then the new one twice. --}}
        <form
            method="POST"
            action="{{ route('verify.pin.update', $customerCode) }}"
            novalidate
            class="paper-card verify-card mt-9"
            aria-labelledby="change-pin-title"
        >
            @csrf
            @method('PUT')

            <div class="paper-card__frame">
                <h1 id="change-pin-title" class="verify-card__title">Ganti PIN</h1>
                <p class="verify-card__intro">
                    Tulis PIN yang Anda pakai sekarang, lalu PIN baru.
                </p>

                <label for="current_pin" class="verify-card__label">PIN lama</label>
                <input
                    id="current_pin"
                    name="current_pin"
                    type="password"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    autocomplete="current-password"
                    placeholder="4 angka"
                    maxlength="4"
                    required
                    autofocus
                    class="verify-card__input verify-card__input--pin"
                    data-pin-input
                    aria-describedby="current-pin-hint @error('current_pin') current-pin-error @enderror"
                    @error('current_pin') aria-invalid="true" @enderror
                >
                <p id="current-pin-hint" class="verify-card__hint">
                    Lupa PIN lama? Minta bantuan kasir Niskala.
                </p>
                @error('current_pin')
                    <p id="current-pin-error" class="verify-card__error" role="alert">{{ $message }}</p>
                @enderror

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

                <button type="submit" class="verify-card__submit mt-6">Simpan PIN baru</button>
            </div>
        </form>

        <p class="mt-8 text-center text-sm">
            <a
                href="{{ route('verify.success', $customerCode) }}"
                class="font-medium text-rust-600 underline decoration-rust-600/40 underline-offset-4 hover:decoration-rust-600 dark:text-cream-200 dark:decoration-cream-200/40 dark:hover:decoration-cream-200"
            >
                Kembali ke kartu
            </a>
        </p>
    </main>
</x-layouts.app>
