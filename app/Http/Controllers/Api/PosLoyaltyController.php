<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidWhatsAppNumberException;
use App\Exceptions\LoyaltyRuleException;
use App\Http\Controllers\Controller;
use App\Models\LoyaltyCustomer;
use App\Services\Loyalty\LoyaltyCustomerSummary;
use App\Services\Loyalty\LoyaltyRedemptionService;
use App\Services\Loyalty\LoyaltyStampService;
use App\Support\WhatsAppNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/pos/*: called by pos-backend (AuthenticatePosApi). Rule refusals
 * answer { message, reason } with 4xx; the POS shows the message.
 */
class PosLoyaltyController extends Controller
{
    /**
     * GET /api/pos/customers/{phone}: the cashier typed a phone number.
     * Looks only; a new number is registered by its first stamp.
     */
    public function customer(string $phone): JsonResponse
    {
        try {
            $normalized = WhatsAppNumber::normalize($phone);
        } catch (InvalidWhatsAppNumberException) {
            return $this->refused(LoyaltyRuleException::invalidPhone());
        }

        $customer = LoyaltyCustomerSummary::findByPhone($normalized);

        return response()->json([
            ...($customer
                ? LoyaltyCustomerSummary::toArray($customer)
                : ['registered' => false, 'phone' => $normalized]),
            // Shown in the cart: the order total that earns a stamp.
            'stamp_min_order_total' => (int) config('loyalty.stamp_min_order_total'),
        ]);
    }

    /**
     * POST /api/pos/stamps { order_id, cashier_id? }: after the POS saved
     * an order. 201 with a new stamp, 200 when the order already had one.
     */
    public function stamp(Request $request, LoyaltyStampService $stamps): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer', 'min:1'],
            'cashier_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        try {
            $result = $stamps->stampOrder((int) $data['order_id'], $data['cashier_id'] ?? null);
        } catch (LoyaltyRuleException $e) {
            return $this->refused($e);
        }

        $customer = LoyaltyCustomer::query()->findOrFail($result->stamp->card->loyalty_customer_id);

        return response()->json([
            'stamped' => $result->created,
            'stamp_number' => $result->stamp->stamp_number,
            'card_number' => $result->stamp->card->card_number,
            'customer_created' => $result->customerCreated,
            ...LoyaltyCustomerSummary::toArray($customer),
        ], $result->created ? 201 : 200);
    }

    /**
     * POST /api/pos/redemptions { phone, reward_stamps, cashier_id? }.
     */
    public function redeem(Request $request, LoyaltyRedemptionService $redemptions): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'reward_stamps' => ['required', 'integer', 'min:1'],
            'cashier_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        try {
            $redemption = $redemptions->redeem($data['phone'], (int) $data['reward_stamps'], $data['cashier_id'] ?? null);
        } catch (LoyaltyRuleException $e) {
            return $this->refused($e);
        }

        $customer = LoyaltyCustomer::query()->findOrFail($redemption->loyalty_customer_id);

        return response()->json([
            'redeemed' => [
                'reward' => $redemption->reward->reward_name,
                'reward_stamps' => $redemption->reward->stamps_required,
                'card_number' => $redemption->card->card_number,
                'redeemed_at' => $redemption->redeemed_at->toIso8601String(),
            ],
            ...LoyaltyCustomerSummary::toArray($customer),
        ], 201);
    }

    private function refused(LoyaltyRuleException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->status);
    }
}
