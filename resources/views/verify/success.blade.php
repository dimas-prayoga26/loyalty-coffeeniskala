<x-layouts.app title="Kartu Stamp Saya" noindex>
    <main class="mx-auto w-full max-w-md px-3 py-10 sm:px-4 sm:py-16">
        <x-brand.header />

        <div class="mt-9">
            <h1 class="font-display text-2xl">Halo, {{ $customerName ?: 'Pelanggan Niskala' }}</h1>
            <p class="mt-1.5 text-sm text-espresso-600 dark:text-cream-200/70">Ini kartu stamp Anda.</p>
        </div>

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
            />
        @endforeach

        <p class="mt-8 text-center text-sm">
            <a
                href="{{ route('verify.create') }}"
                class="font-medium text-gold-500 underline decoration-gold-500/40 underline-offset-4 hover:decoration-gold-500 dark:text-gold-300 dark:decoration-gold-300/40"
            >
                Bukan Anda? Cek nomor lain
            </a>
        </p>
    </main>
</x-layouts.app>
