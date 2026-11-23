<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidWhatsAppNumberException;
use App\Http\Requests\VerifyCustomerRequest;
use App\Models\LoyaltyCustomer;
use App\Services\Loyalty\LoyaltyCustomerSummary;
use App\Support\WhatsAppNumber;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Public flow for customers: /verify (phone number + Turnstile), then
 * /verify/pin (the PIN of that number), then /verify-success/{customer_code}
 * shows their stamp cards.
 *
 * Looks only: a number without a card is turned away (cards are made by
 * the cashier's order). The card page only opens in the browser session
 * that verified the number. While the customer still has the first PIN
 * (the last 4 digits of their number), the card stays blurred until they
 * set their own.
 */
class CustomerVerificationController extends Controller
{
    private const SESSION_KEY = 'loyalty.verified_customer_code';

    /** The number found in step 1, waiting for its PIN. */
    private const PENDING_KEY = 'loyalty.pending_customer_code';

    /** Wrong PINs per number before it is locked for PIN_LOCK_SECONDS. */
    private const PIN_ATTEMPTS = 5;

    private const PIN_LOCK_SECONDS = 15 * 60;

    public function create(): View
    {
        return view('verify.create', [
            'turnstileSiteKey' => config('services.turnstile.site_key'),
        ]);
    }

    public function store(VerifyCustomerRequest $request): RedirectResponse
    {
        try {
            $phone = WhatsAppNumber::normalize($request->validated('phone'));
        } catch (InvalidWhatsAppNumberException) {
            throw ValidationException::withMessages([
                'phone' => 'Nomor HP ini sepertinya kurang tepat. Contoh: 0812 3456 7890.',
            ]);
        }

        $customer = LoyaltyCustomer::query()->where('phone_number', $phone)->first();

        if ($customer === null) {
            throw ValidationException::withMessages([
                'phone' => 'Belum ada kartu stamp untuk nomor ini. Cek lagi nomornya, atau sebutkan nomor HP Anda ke kasir saat memesan.',
            ]);
        }

        $request->session()->put(self::PENDING_KEY, $customer->customer_code);

        return redirect()->route('verify.pin.create');
    }

    /** Step 2: the PIN, for the number found in step 1. */
    public function pinForm(Request $request): View|RedirectResponse
    {
        $customer = $this->pendingCustomer($request);

        if ($customer === null) {
            return $this->backToForm();
        }

        return view('verify.pin', [
            'maskedPhone' => WhatsAppNumber::masked($customer->phone_number),
        ]);
    }

    public function confirmPin(Request $request): RedirectResponse
    {
        $customer = $this->pendingCustomer($request);

        if ($customer === null) {
            return $this->backToForm();
        }

        $data = $request->validate([
            'pin' => ['bail', 'required', 'digits:4'],
        ], [
            'pin.required' => 'Tulis PIN Anda.',
            'pin.digits' => 'PIN berupa 4 angka.',
        ]);

        $this->checkPin($customer, $data['pin']);

        $request->session()->forget(self::PENDING_KEY);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $customer->customer_code);

        return redirect()->route('verify.success', $customer->customer_code);
    }

    public function success(Request $request, string $customerCode): Response|RedirectResponse
    {
        $customer = $this->verifiedCustomer($request, $customerCode);

        if ($customer === null) {
            return $this->backToForm();
        }

        $locked = $customer->hasDefaultPin();
        $cards = LoyaltyCustomerSummary::cardsForDisplay($customer);

        if ($locked) {
            // Behind the blur: the current card's stickers only. Anyone who
            // knows the number knows the first PIN, so the visit dates (and
            // when rewards were handed over) wait for the customer's own PIN.
            $cards = $cards->take(1)->map(fn (array $card) => [
                ...$card,
                'stamps' => array_fill(0, count($card['stamps']), null),
                'redeemedAt' => [],
            ]);
        }

        return response()->view('verify.success', [
            'customerCode' => $customer->customer_code,
            'customerName' => $customer->customer_name,
            'locked' => $locked,
            'cards' => $cards,
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * The first PIN must be replaced before the card is shown. Only that
     * first change happens here; a PIN that was already changed is reset by
     * setting loyalty_customers.pin back to NULL.
     */
    public function updatePin(Request $request, string $customerCode): RedirectResponse
    {
        $customer = $this->verifiedCustomer($request, $customerCode);

        if ($customer === null) {
            return $this->backToForm();
        }

        if (! $customer->hasDefaultPin()) {
            return redirect()->route('verify.success', $customerCode);
        }

        $data = $request->validate([
            'new_pin' => [
                'bail', 'required', 'digits:4', 'confirmed',
                function (string $attribute, mixed $value, Closure $fail) use ($customer): void {
                    if ($value === $customer->defaultPin()) {
                        $fail('PIN baru tidak boleh sama dengan 4 angka terakhir nomor HP.');
                    }
                },
            ],
        ], [
            'new_pin.required' => 'Tulis PIN baru Anda.',
            'new_pin.digits' => 'PIN baru berupa 4 angka.',
            'new_pin.confirmed' => 'Kedua PIN tidak sama. Tulis ulang keduanya.',
        ]);

        $customer->changePin($data['new_pin']);

        return redirect()->route('verify.success', $customerCode)
            ->with('status', 'PIN baru tersimpan. Pakai PIN ini setiap kali membuka kartu.');
    }

    /**
     * A 4-digit PIN is guessed in at most 10,000 tries, so each number gets
     * PIN_ATTEMPTS wrong tries per PIN_LOCK_SECONDS, whatever the IP.
     *
     * @throws ValidationException
     */
    private function checkPin(LoyaltyCustomer $customer, string $pin): void
    {
        $key = 'verify-pin:'.$customer->getKey();

        if (RateLimiter::tooManyAttempts($key, self::PIN_ATTEMPTS)) {
            throw $this->pinLocked($key);
        }

        if ($customer->checkPin($pin)) {
            RateLimiter::clear($key);

            return;
        }

        RateLimiter::hit($key, self::PIN_LOCK_SECONDS);

        $left = RateLimiter::remaining($key, self::PIN_ATTEMPTS);

        throw $left > 0
            ? ValidationException::withMessages(['pin' => "PIN salah. Sisa {$left} kali percobaan."])
            : $this->pinLocked($key);
    }

    private function pinLocked(string $key): ValidationException
    {
        $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

        return ValidationException::withMessages([
            'pin' => "PIN salah terlalu sering. Coba lagi dalam {$minutes} menit.",
        ]);
    }

    private function verifiedCustomer(Request $request, string $customerCode): ?LoyaltyCustomer
    {
        return $request->session()->get(self::SESSION_KEY) === $customerCode
            ? LoyaltyCustomer::query()->where('customer_code', $customerCode)->first()
            : null;
    }

    private function pendingCustomer(Request $request): ?LoyaltyCustomer
    {
        $code = $request->session()->get(self::PENDING_KEY);

        return $code === null
            ? null
            : LoyaltyCustomer::query()->where('customer_code', $code)->first();
    }

    private function backToForm(): RedirectResponse
    {
        return redirect()->route('verify.create')
            ->with('status', 'Tulis nomor HP Anda dulu untuk melihat kartu stamp.');
    }
}
