<x-layouts.app title="Pratinjau Kartu Stamp">
    <main class="mx-auto max-w-5xl px-4 py-10 sm:px-6 sm:py-14">
        <header class="max-w-2xl">
            <p class="text-xs font-medium tracking-[0.25em] text-rust-600 uppercase dark:text-cream-200">Niskala Loyalty</p>
            <h1 class="mt-2 font-serif text-3xl font-bold sm:text-4xl">Pratinjau Kartu Stamp</h1>
            <p class="mt-3 text-sm leading-relaxed text-espresso-600 dark:text-cream-200/70">
                Contoh tampilan kartu di setiap keadaan. Datanya contoh, belum dari database.
                Muat ulang halaman untuk melihat lagi animasi stamp terbaru.
            </p>
        </header>

        <div class="mt-10 grid gap-x-8 gap-y-12 lg:grid-cols-2">
            @foreach ($samples as $sample)
                <section id="contoh-{{ $loop->iteration }}" class="scroll-mt-4">
                    <h2 class="text-sm font-semibold">{{ $sample['title'] }}</h2>
                    <p class="mt-1 mb-4 text-sm text-espresso-600 dark:text-cream-200/70">{{ $sample['description'] }}</p>
                    <x-loyalty.stamp-card
                        class="max-w-md"
                        :customer-code="$sample['customerCode']"
                        :card-number="$sample['cardNumber']"
                        :customer-name="$sample['customerName']"
                        :phone="$sample['phone']"
                        :stamps="$sample['stamps']"
                        :status="$sample['status']"
                        :redeemed-rewards="$sample['redeemedRewards'] ?? []"
                        :redeemed-at="$sample['redeemedAt'] ?? []"
                        {{-- Valid from the sample's first stamp, as a real card. --}}
                        :expires-at="isset($sample['stamps'][0]) ? \App\Models\LoyaltyCard::expiryAfterFirstStamp($sample['stamps'][0]) : null"
                        :fresh-stamp="$sample['freshStamp'] ?? false"
                    />
                </section>
            @endforeach
        </div>
    </main>
</x-layouts.app>
