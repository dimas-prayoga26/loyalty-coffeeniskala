<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidWhatsAppNumberException;
use App\Http\Requests\VerifyCustomerRequest;
use App\Models\LoyaltyCustomer;
use App\Services\Loyalty\LoyaltyCustomerSummary;
use App\Support\WhatsAppNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Public flow for customers: /verify (phone number + Turnstile), then
 * /verify-success/{customer_code} shows their stamp cards.
 *
 * Looks only: a number without a card is turned away (cards are made by
 * the cashier's order). The card page only opens in the browser session
 * that verified the number.
 */
class CustomerVerificationController extends Controller
{
    private const SESSION_KEY = 'loyalty.verified_customer_code';

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

        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $customer->customer_code);

        return redirect()->route('verify.success', $customer->customer_code);
    }

    public function success(Request $request, string $customerCode): Response|RedirectResponse
    {
        $customer = $request->session()->get(self::SESSION_KEY) === $customerCode
            ? LoyaltyCustomer::query()->where('customer_code', $customerCode)->first()
            : null;

        if ($customer === null) {
            return redirect()->route('verify.create')
                ->with('status', 'Tulis nomor HP Anda dulu untuk melihat kartu stamp.');
        }

        return response()->view('verify.success', [
            'customerName' => $customer->customer_name,
            'cards' => LoyaltyCustomerSummary::cardsForDisplay($customer),
        ])->header('Cache-Control', 'no-store, private');
    }
}
