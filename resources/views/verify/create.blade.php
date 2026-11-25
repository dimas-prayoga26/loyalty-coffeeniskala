<x-layouts.app title="Cek Kartu Stamp">
    <main class="mx-auto flex min-h-screen w-full max-w-md flex-col px-3 py-10 sm:px-4 sm:py-16">
        <x-brand.header />

        {{-- The same card stock as the stamp card: the customer writes their number on it. --}}
        <form
            method="POST"
            action="{{ route('verify.store') }}"
            novalidate
            class="paper-card verify-card mt-9"
            aria-labelledby="verify-title"
            data-verify-form
        >
            @csrf

            <div class="paper-card__frame">
                <h1 id="verify-title" class="verify-card__title">Cek kartu stamp</h1>
                <p class="verify-card__intro">
                    Tulis nomor HP yang Anda sebutkan ke kasir saat memesan. Stamp Anda tercatat di nomor itu.
                </p>

                @if (session('status'))
                    <p class="verify-card__note" role="status">{{ session('status') }}</p>
                @endif

                <label for="phone" class="verify-card__label">Nomor HP</label>
                <input
                    id="phone"
                    name="phone"
                    type="tel"
                    inputmode="tel"
                    autocomplete="tel"
                    placeholder="0812 3456 7890"
                    maxlength="30"
                    required
                    value="{{ old('phone') }}"
                    class="verify-card__input"
                    @error('phone') aria-invalid="true" aria-describedby="phone-error" @enderror
                >
                @error('phone')
                    <p id="phone-error" class="verify-card__error" role="alert">{{ $message }}</p>
                @enderror

                <div class="verify-card__check">
                    {{-- Until Cloudflare's script arrives the box would be empty and the
                         button dead with no reason given; say what is happening. --}}
                    <p class="verify-card__check-status" data-turnstile-status role="status">
                        Memuat pengecekan keamanan…
                    </p>
                    <div
                        class="cf-turnstile"
                        data-sitekey="{{ $turnstileSiteKey }}"
                        data-action="{{ \App\Rules\Turnstile::ACTION }}"
                        data-theme="light"
                        data-language="id"
                        data-callback="onTurnstileSuccess"
                        data-expired-callback="onTurnstileReset"
                        data-error-callback="onTurnstileError"
                    ></div>
                </div>
                @error('cf-turnstile-response')
                    <p class="verify-card__error" role="alert">{{ $message }}</p>
                @enderror

                <button type="submit" disabled data-verify-submit class="verify-card__submit">
                    Lanjut
                </button>
            </div>
        </form>

        <p class="mt-6 text-center text-xs leading-relaxed text-espresso-600 dark:text-cream-200/60">
            Belum punya kartu? Sebutkan nomor HP Anda ke kasir saat memesan.
        </p>
    </main>

    <script>
        // The button waits for Turnstile. Tokens are single use, so it is
        // locked again after submitting and when a token expires.
        (() => {
            const form = document.querySelector('[data-verify-form]');
            const button = form.querySelector('[data-verify-submit]');
            const status = form.querySelector('[data-turnstile-status]');
            const label = button.textContent.trim();

            // A problem with the check: explain it and offer a reload.
            const showProblem = (message) => {
                status.hidden = false;
                status.classList.add('is-problem');
                status.innerHTML = '';
                const reload = document.createElement('button');
                reload.type = 'button';
                reload.textContent = 'Muat ulang';
                reload.addEventListener('click', () => location.reload());
                status.append(message + ' ', reload);
            };

            window.onTurnstileLoaded = () => { status.hidden = true; };
            window.onTurnstileFailed = () => showProblem('Pengecekan keamanan tidak bisa dimuat. Periksa koneksi internet Anda.');
            window.onTurnstileSuccess = () => { button.disabled = false; status.hidden = true; };
            window.onTurnstileReset = () => { button.disabled = true; };
            window.onTurnstileError = () => {
                button.disabled = true;
                showProblem('Pengecekan keamanan gagal.');
            };

            // Slow connection or a blocker: don't leave an empty box forever.
            setTimeout(() => {
                if (!window.turnstile) {
                    showProblem('Pengecekan keamanan belum muncul. Koneksi mungkin lambat, atau diblokir ekstensi browser.');
                }
            }, 12000);

            form.addEventListener('submit', () => {
                button.disabled = true;
                button.textContent = 'Mencari kartu…';
            });

            // Back/forward cache restores the "searching" state otherwise.
            window.addEventListener('pageshow', (event) => {
                if (event.persisted) {
                    button.textContent = label;
                    window.turnstile?.reset();
                }
            });
        })();
    </script>
    <script
        src="https://challenges.cloudflare.com/turnstile/v0/api.js"
        async
        defer
        onload="onTurnstileLoaded()"
        onerror="onTurnstileFailed()"
    ></script>
</x-layouts.app>
