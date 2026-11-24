<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyCustomer;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyStamp;
use App\Services\Loyalty\LoyaltyCustomerSummary;
use App\Services\Loyalty\StampCardProgram;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/pos/program and /api/pos/rewards: Pengaturan → Stamp Card in the
 * POS (pos-backend lets admins only). Every answer is the whole program,
 * except the histories (GET /api/pos/stamps and /api/pos/redemptions).
 */
class PosProgramController extends Controller
{
    /**
     * GET /api/pos/program: the stamp card, its rules and its rewards.
     */
    public function show(): JsonResponse
    {
        return response()->json(StampCardProgram::summary());
    }

    /**
     * PUT /api/pos/program { active }: switches the stamp card on or off.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);

        StampCardProgram::setActive((bool) $data['active']);

        return $this->show();
    }

    /**
     * POST /api/pos/rewards { stamps, label, short? }: a new reward.
     */
    public function storeReward(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stamps' => [
                'required', 'integer', 'min:1', 'max:'.(int) config('loyalty.stamps_per_card'),
                Rule::unique('loyalty_rewards', 'stamps_required'),
            ],
            'label' => ['required', 'string', 'max:100'],
            'short' => ['nullable', 'string', 'max:30'],
        ], [
            'stamps.unique' => 'Sudah ada hadiah untuk stamp ke-:input.',
            'stamps.max' => 'Stamp hadiah maksimal :max (isi satu kartu).',
        ]);

        LoyaltyReward::create([
            'stamps_required' => $data['stamps'],
            'reward_name' => trim($data['label']),
            'short_name' => isset($data['short']) ? trim($data['short']) : null,
            'is_active' => true,
        ]);

        return response()->json(StampCardProgram::summary(), 201);
    }

    /**
     * PUT /api/pos/rewards/{reward} { active?, label?, short?,
     * promo_menu_item_id? }: switches a reward on or off, renames it, or sets
     * the POS Promo loyalty menu Tukar gives for it (pos-backend checks it is
     * one; null: none). Its stamp stays.
     */
    public function updateReward(Request $request, LoyaltyReward $reward): JsonResponse
    {
        $data = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'label' => ['sometimes', 'string', 'max:100'],
            'short' => ['sometimes', 'nullable', 'string', 'max:30'],
            'promo_menu_item_id' => ['sometimes', 'nullable', 'integer', Rule::exists('menu_items', 'id')],
        ]);
        if (array_key_exists('promo_menu_item_id', $data)) {
            $reward->promo_menu_item_id = $data['promo_menu_item_id'];
        }

        $reward->fill(array_filter([
            'is_active' => array_key_exists('active', $data) ? (bool) $data['active'] : null,
            'reward_name' => isset($data['label']) ? trim($data['label']) : null,
        ], fn ($value) => $value !== null));
        if (array_key_exists('short', $data)) {
            $reward->short_name = $data['short'] !== null ? trim($data['short']) : null;
        }
        $reward->save();

        return $this->show();
    }

    /**
     * GET /api/pos/redemptions?page=&per_page=&q=: the rewards handed over,
     * newest first (Program Promo → Riwayat). q: the customer's phone, code
     * or name.
     */
    public function redemptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);
        $search = trim((string) ($data['q'] ?? ''));

        $page = LoyaltyRedemption::query()
            ->with([
                'customer:id,customer_code,customer_name,phone_number',
                'reward:id,stamps_required,reward_name',
                'order:id,order_code',
                'redeemedBy:id,name',
            ])
            ->when($search !== '', fn ($query) => $query->whereHas(
                'customer',
                fn ($customer) => $this->matchCustomer($customer, $search),
            ))
            ->orderByDesc('redeemed_at')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20, ['*'], 'page', $data['page'] ?? 1);

        return response()->json([
            'data' => $page->getCollection()->map(fn (LoyaltyRedemption $redemption) => [
                'id' => $redemption->id,
                'redeemed_at' => $redemption->redeemed_at?->toIso8601String(),
                'customer' => [
                    'code' => $redemption->customer?->customer_code,
                    'name' => $redemption->customer?->customer_name,
                    'phone' => $redemption->customer?->phone_number,
                ],
                'reward' => [
                    'stamps' => (int) $redemption->reward?->stamps_required,
                    'label' => $redemption->reward?->reward_name,
                ],
                'menu_name' => $redemption->menu_name,
                'order' => $redemption->order_id
                    ? ['id' => (int) $redemption->order_id, 'code' => $redemption->order?->order_code]
                    : null,
                'cashier' => $redemption->redeemedBy?->name,
            ])->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/pos/members?page=&per_page=&q=: the stamp card members, most
     * stamps first (then the latest stamp): all the stamps each has earned,
     * their card now and the rewards handed over or ready to hand over.
     */
    public function members(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);
        $search = trim((string) ($data['q'] ?? ''));

        $page = LoyaltyCustomer::query()
            ->withCount(['stamps', 'redemptions'])
            ->withMax('stamps', 'stamped_at')
            ->with(['cards' => fn ($query) => $query->orderBy('id'), 'cards.redemptions.reward'])
            ->when($search !== '', fn ($query) => $this->matchCustomer($query, $search))
            ->orderByDesc('stamps_count')
            ->orderByDesc('stamps_max_stamped_at')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20, ['*'], 'page', $data['page'] ?? 1);

        $rewards = StampCardProgram::rewards();

        return response()->json([
            'data' => $page->getCollection()
                ->map(fn (LoyaltyCustomer $customer) => LoyaltyCustomerSummary::memberRow($customer, $rewards))
                ->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/pos/stamps?page=&per_page=&q=: the stamps given, newest
     * first, with the order that earned each and its cashier. A stamp whose
     * order the POS deleted keeps the order code; one given without an order
     * (loyalty:give-stamps) has none.
     */
    public function stamps(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);
        $search = trim((string) ($data['q'] ?? ''));

        $page = LoyaltyStamp::query()
            ->with([
                'card:id,loyalty_customer_id,card_number',
                'card.customer:id,customer_code,customer_name,phone_number',
                'order:id,order_code',
                'createdBy:id,name',
            ])
            ->when($search !== '', fn ($query) => $query->whereHas(
                'card.customer',
                fn ($customer) => $this->matchCustomer($customer, $search),
            ))
            ->orderByDesc('stamped_at')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20, ['*'], 'page', $data['page'] ?? 1);

        return response()->json([
            'data' => $page->getCollection()->map(fn (LoyaltyStamp $stamp) => [
                'id' => $stamp->id,
                'stamped_at' => $stamp->stamped_at?->toIso8601String(),
                'stamp_number' => $stamp->stamp_number,
                'card_number' => $stamp->card?->card_number,
                'customer' => [
                    'code' => $stamp->card?->customer?->customer_code,
                    'name' => $stamp->card?->customer?->customer_name,
                    'phone' => $stamp->card?->customer?->phone_number,
                ],
                'order' => $stamp->order_id || $stamp->order_code
                    ? [
                        'id' => $stamp->order_id ? (int) $stamp->order_id : null,
                        'code' => $stamp->order?->order_code ?? $stamp->order_code,
                    ]
                    : null,
                'cashier' => $stamp->createdBy?->name,
            ])->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * Code or name containing $search, or a phone containing its digits
     * (typed 08..., stored 628...).
     */
    private function matchCustomer(Builder $query, string $search): void
    {
        $like = '%'.addcslashes($search, '%_\\').'%';
        $digits = preg_replace('/\D/', '', $search);
        $phone = str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;

        $query->where(fn ($where) => $where
            ->where('customer_code', 'like', $like)
            ->orWhere('customer_name', 'like', $like)
            ->when($digits !== '', fn ($or) => $or->orWhere('phone_number', 'like', '%'.$phone.'%')));
    }
}
