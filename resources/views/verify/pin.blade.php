<x-layouts.app title="Cek Kartu Stamp">
    <main class="mx-auto flex min-h-screen w-full max-w-md flex-col px-3 py-10 sm:px-4 sm:py-16">
        <x-brand.header />

        {{-- Step 2: the number from step 1 is known, only its PIN is asked. --}}
        <form
            method="POST"
            action="{{ route('verify.pin.store') }}"
            novalidate
            class="paper-card verify-card mt-9"
            aria-labelledby="verify-title"
            data-pin-form
        >
            @csrf

            <div class="paper-card__frame">
                <h1 id="verify-title" class="verify-card__title">Masukkan PIN</h1>
                <p class="verify-card__intro">
                    Nomor HP <strong class="verify-card__phone">{{ $maskedPhone }}</strong>
                    <a href="{{ route('verify.create') }}" class="verify-card__link">Ganti nomor</a>
                </p>

                <label for="pin" class="verify-card__label">PIN</label>
                <input
                    id="pin"
                    name="pin"
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
                    aria-describedby="pin-hint @error('pin') pin-error @enderror"
                    @error('pin') aria-invalid="true" @enderror
                >
                <p id="pin-hint" class="verify-card__hint">
                    Belum pernah ganti PIN? Pakai 4 angka terakhir nomor HP Anda.
                </p>
                @error('pin')
                    <p id="pin-error" class="verify-card__error" role="alert">{{ $message }}</p>
                @enderror

                <button type="submit" data-pin-submit class="verify-card__submit mt-6">
                    Lihat kartu saya
                </button>
            </div>
        </form>
    </main>

    <script>
        (() => {
            const form = document.querySelector('[data-pin-form]');
            const button = form.querySelector('[data-pin-submit]');
            const label = button.textContent.trim();

            form.addEventListener('submit', () => {
                button.disabled = true;
                button.textContent = 'Membuka kartu…';
            });

            // Back/forward cache restores the "opening" state otherwise.
            window.addEventListener('pageshow', (event) => {
                if (event.persisted) {
                    button.disabled = false;
                    button.textContent = label;
                }
            });
        })();
    </script>
</x-layouts.app>
