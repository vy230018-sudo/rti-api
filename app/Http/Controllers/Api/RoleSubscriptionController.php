<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SeatUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\News;
use App\Models\RoleSubscriptionPlan;
use App\Models\Seat;
use App\Models\SubscriptionPayment;
use App\Models\UserSubscription;
use App\Services\ReferralCommissionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Razorpay\Api\Api;
use Throwable;

class RoleSubscriptionController extends Controller
{
    public function __construct(protected ReferralCommissionService $referralCommissionService)
    {
    }
    public function index(Request $request): JsonResponse
    {
        $plans = RoleSubscriptionPlan::query()
            ->visible()
            ->latest('id')
            ->get();

        $sold = $this->soldPlans($plans, $this->locationFilter($request));

        return response()->json([
            'role_subscription_plans' => $plans->map(
                fn (RoleSubscriptionPlan $plan) => $this->formatPlan($plan, $sold[$plan->id] ?? false)
            ),
        ]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $query = RoleSubscriptionPlan::query()->latest('id');

        if (! $request->boolean('include_deleted')) {
            $query->notDeleted();
        }

        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }

        if ($request->filled('role_id')) {
            $query->where('role_id', $request->input('role_id'));
        }

        $plans = $query->get();
        $sold = $this->soldPlans($plans, $this->locationFilter($request));

        return response()->json([
            'role_subscription_plans' => $plans->map(
                fn (RoleSubscriptionPlan $plan) => $this->formatPlan($plan, $sold[$plan->id] ?? false)
            ),
        ]);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validated = $this->validatePlanPayload($request, false);

        // Premium plan: role kabhi save nahi hota, aur plan_type hamesha set rahe.
        $isPremium = RoleSubscriptionPlan::isPremiumPayload($validated['plan_type'] ?? null, $validated['title'] ?? null);
        $validated['plan_type'] = $isPremium ? RoleSubscriptionPlan::TYPE_PREMIUM : RoleSubscriptionPlan::TYPE_ROLE;
        if ($isPremium) {
            $validated['role_id'] = null;
        }

        $plan = RoleSubscriptionPlan::query()->create(array_merge($validated, [
            'status' => $validated['status'] ?? 1,
            'id_deleted' => 1,
        ]));

        return response()->json([
            'message' => 'Role subscription plan created successfully.',
            'role_subscription_plan' => $this->formatPlan($plan->fresh()),
        ], 201);
    }

    public function adminShow(int $id): JsonResponse
    {
        $plan = RoleSubscriptionPlan::query()->findOrFail($id);

        return response()->json(['role_subscription_plan' => $this->formatPlan($plan)]);
    }

    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $plan = RoleSubscriptionPlan::query()->findOrFail($id);
        $validated = $this->validatePlanPayload($request, true, $plan);

        $plan->fill($validated);

        if ($plan->isPremium()) {
            $plan->plan_type = RoleSubscriptionPlan::TYPE_PREMIUM;
            $plan->role_id = null;
        } elseif (blank($plan->role_id)) {
            // Role-based plan ka role empty nahi ho sakta (existing validation barkarar).
            throw ValidationException::withMessages(['role_id' => 'The role id field is required.']);
        }

        $plan->save();

        return response()->json([
            'message' => 'Role subscription plan updated successfully.',
            'role_subscription_plan' => $this->formatPlan($plan->fresh()),
        ]);
    }

    public function adminUpdateStatus(Request $request, int $id): JsonResponse
    {
        $plan = RoleSubscriptionPlan::query()->findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', Rule::in([0, 1, '0', '1'])],
        ]);

        $plan->forceFill(['status' => (int) $validated['status']])->save();

        return response()->json([
            'message' => 'Role subscription plan status updated successfully.',
            'role_subscription_plan' => $this->formatPlan($plan->fresh()),
        ]);
    }

    public function adminDestroy(int $id): JsonResponse
    {
        $plan = RoleSubscriptionPlan::query()->findOrFail($id);
        $plan->forceFill(['id_deleted' => 0])->save();

        return response()->json(['message' => 'Role subscription plan soft deleted successfully.']);
    }

    public function adminRestore(int $id): JsonResponse
    {
        $plan = RoleSubscriptionPlan::query()->findOrFail($id);
        $plan->forceFill(['id_deleted' => 1])->save();

        return response()->json([
            'message' => 'Role subscription plan restored successfully.',
            'role_subscription_plan' => $this->formatPlan($plan->fresh()),
        ]);
    }

    public function purchase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer'],
        ]);

        $plan = RoleSubscriptionPlan::query()->visible()->findOrFail($validated['plan_id']);
        $isPremium = $plan->isPremium();
        $amount = (float) ($plan->offer_price ?? $plan->price);

        if ($amount <= 0) {
            return response()->json(['message' => 'The selected plan must have a price greater than zero.'], 422);
        }

        // Premium par seat lock nahi lagta. Role-based plan me Role + Location seat chahiye.
        $seatData = $isPremium ? null : $this->resolveSeat($request, $plan);

        // 1) Payment se pehle seat reserve karo. Seat kisi aur ke paas ho to yahin reject.
        $seat = null;

        if ($seatData) {
            $seat = $this->reserveSeat((string) $request->user()->id, $plan, $seatData);

            if (! $seat) {
                return response()->json([
                    'success' => false,
                    'code' => 'seat_unavailable',
                    'message' => 'This seat is already taken. Please choose another location.',
                ], 409);
            }
        }

        // 2) Razorpay order
        try {
            $order = $this->razorpay()->order->create([
                'receipt' => 'sub_'.$request->user()->id.'_'.uniqid(),
                'amount' => (int) round($amount * 100),
                'currency' => 'INR',
                'payment_capture' => 1,
                'notes' => [
                    'user_id' => (string) $request->user()->id,
                    'plan_id' => (string) $plan->id,
                ],
            ]);
        } catch (Throwable $exception) {
            $seat?->release();

            \Log::error('Razorpay Order Error', [
                'message' => $exception->getMessage(),
                'file'    => $exception->getFile(),
                'line'    => $exception->getLine(),
                'trace'   => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to create Razorpay order.',
                'error'   => $exception->getMessage(),
                'file'    => $exception->getFile(),
                'line'    => $exception->getLine(),
            ], 500);
        }

        // 3) Payment row banao aur seat ko usse link karo (verify/failed isi link se seat dhoondhte hain).
        try {
            $payment = DB::transaction(function () use ($request, $plan, $amount, $order, $seat) {
                $payment = SubscriptionPayment::query()->create([
                    'user_id' => (string) $request->user()->id,
                    'plan_id' => (string) $plan->id,
                    'subscription_type' => 'role',
                    'razorpay_order_id' => $order['id'],
                    'amount' => $amount,
                    'currency' => 'INR',
                    'status' => 'created',
                    'gateway_response' => $order->toArray(),
                ]);

                $seat?->forceFill(['payment_id' => $payment->id])->save();

                return $payment;
            });
        } catch (Throwable $exception) {
            $seat?->release();

            throw $exception;
        }

        return response()->json([
            'message' => 'Razorpay order created. Complete payment and call payment/verify.',
            'payment' => $this->formatPayment($payment),
            'seat' => $seat ? $this->formatSeat($seat) : null,
            'razorpay' => ['key' => config('services.razorpay.key'), 'order_id' => $order['id'], 'amount' => (int) $order['amount'], 'currency' => $order['currency']],
        ], 201);
    }

    public function verifyPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'razorpay_order_id' => ['required', 'string', 'max:255'],
            'razorpay_payment_id' => ['required', 'string', 'max:255'],
            'razorpay_signature' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->razorpay()->utility->verifyPaymentSignature($validated);
        } catch (Throwable $exception) {
            SubscriptionPayment::query()->where('razorpay_order_id', $validated['razorpay_order_id'])->where('user_id', (string) $request->user()->id)->update([
                'status' => 'failed', 'failure_code' => 'signature_verification_failed', 'failure_description' => 'Razorpay signature could not be verified.',
            ]);

            return response()->json(['message' => 'Payment verification failed.'], 422);
        }

        try {
            $subscription = DB::transaction(function () use ($request, $validated) {
                $payment = SubscriptionPayment::query()->where('razorpay_order_id', $validated['razorpay_order_id'])->lockForUpdate()->firstOrFail();

                abort_unless($payment->user_id === (string) $request->user()->id, 403);

                if ($payment->status === 'paid' && $payment->user_subscription_id) {
                    return UserSubscription::query()->with('plan')->findOrFail($payment->user_subscription_id);
                }

                $plan = RoleSubscriptionPlan::query()->visible()->findOrFail($payment->plan_id);
                $startDate = Carbon::today();
                $days = (int) ($plan->days ?? 0);
                $endDate = $days > 0 ? $startDate->copy()->addDays($days) : $plan->end_date;

                // Seat pehle confirm karo - seat na mile to subscription banni hi nahi chahiye.
                if (! $plan->isPremium()) {
                    $this->activateSeat($payment, $plan, $startDate, $endDate);
                }

                $subscription = UserSubscription::query()->create([
                    'user_id' => (string) $request->user()->id, 'plan_id' => (string) $plan->id, 'price' => $payment->amount,
                    'start_date' => $startDate->toDateString(), 'end_date' => $endDate?->toDateString(),
                    'payment_status' => 1, 'transaction' => $validated['razorpay_payment_id'], 'status' => 1, 'is_deleted' => 1,
                ]);
                $payment->forceFill([
                    'user_subscription_id' => $subscription->id, 'razorpay_payment_id' => $validated['razorpay_payment_id'],
                    'razorpay_signature' => $validated['razorpay_signature'], 'status' => 'paid', 'failure_code' => null, 'failure_description' => null,
                ])->save();

                if (! $plan->isPremium()) {
                    Seat::query()->where('payment_id', $payment->id)->update(['user_subscription_id' => $subscription->id]);
                }

                return $subscription->load('plan');
            });
        } catch (SeatUnavailableException $exception) {
            return $this->handleSeatConflict($request, $validated);
        } catch (QueryException $exception) {
            return response()->json(['message' => 'This Razorpay payment has already been used.'], 422);
        }

        // Payment + subscription commit ho chuke hain. Ab upar ke 5 levels ko commission.
        // Alag try/catch: commission mein koi dikkat aaye to bhi user ka paid subscription na ruke,
        // aur verify dobara aane par (idempotent) commission phir try ho jaata hai.
        try {
            $paid = SubscriptionPayment::query()->where('razorpay_order_id', $validated['razorpay_order_id'])->first();

            if ($paid) {
                $this->referralCommissionService->createForPaidRoleSubscription($paid);
            }
        } catch (Throwable $exception) {
            report($exception);
            \Log::error('Referral commission failed', [
                'razorpay_order_id' => $validated['razorpay_order_id'],
                'message' => $exception->getMessage(),
            ]);
        }

        return response()->json(['message' => 'Payment verified and subscription activated.', 'user_subscription' => $this->formatUserSubscription($subscription)], 201);
    }

    public function paymentFailed(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'razorpay_order_id' => ['required', 'string', 'max:255'],
            'error_code' => ['nullable', 'string', 'max:255'],
            'error_description' => ['nullable', 'string', 'max:2000'],
        ]);

        $payment = SubscriptionPayment::query()->where('razorpay_order_id', $validated['razorpay_order_id'])->where('user_id', (string) $request->user()->id)->firstOrFail();
        if ($payment->status !== 'paid') {
            $payment->forceFill(['status' => 'failed', 'failure_code' => $validated['error_code'] ?? null, 'failure_description' => $validated['error_description'] ?? 'Payment failed or was cancelled.'])->save();

            // Sirf is payment ki reserved seat chhodo - ads/ecom/quiz ya kisi aur payment ki nahi.
            if ($payment->subscription_type === 'role') {
                Seat::query()
                    ->where('payment_id', $payment->id)
                    ->where('status', Seat::STATUS_RESERVED)
                    ->get()
                    ->each(fn (Seat $seat) => $seat->release());
            }
        }

        return response()->json(['message' => 'Payment failure recorded.', 'payment' => $this->formatPayment($payment->fresh())]);
    }

    public function mySubscriptions(Request $request): JsonResponse
    {
        $subscriptions = UserSubscription::query()
            ->with('plan')
            ->where('user_id', (string) $request->user()->id)
            ->where('is_deleted', 1)
            ->latest('id')
            ->get()
            ->map(fn (UserSubscription $subscription) => $this->formatUserSubscription($subscription));

        return response()->json(['user_subscriptions' => $subscriptions]);
    }

    /** Optional query filter: ?state=&district=&taluka=&village= (plan par location fixed na ho tab kaam aata hai). */
    protected function locationFilter(Request $request): array
    {
        $filter = [];

        foreach (Seat::LEVELS as $level) {
            $filter[$level] = trim((string) $request->query($level, ''));
        }

        return $filter;
    }

    /**
     * Har plan ki seat availability: [plan_id => is_sold]. Ek hi query me.
     * Plan ka location fixed ho to wahi, warna filter ki location. Premium ya bina location wale plan par
     * seat tay nahi hoti, isliye false. Expired ya release ho chuki seat sold nahi maani jaati.
     *
     * @param  \Illuminate\Support\Collection<int, RoleSubscriptionPlan>  $plans
     * @return array<int, bool>
     */
    protected function soldPlans($plans, array $filter = []): array
    {
        $keys = [];

        foreach ($plans as $plan) {
            $keys[$plan->id] = $this->planLockKey($plan, $filter);
        }

        $lookup = array_values(array_unique(array_filter($keys)));
        $taken = [];

        if ($lookup !== []) {
            try {
                $taken = Seat::query()
                    ->whereIn('lock_key', $lookup)
                    ->when(request()->user(), fn ($query, $user) => $query->where('user_id', '!=', (string) $user->id))
                    ->where(function ($query) {
                        $query->where(function ($active) {
                            $active->where('status', Seat::STATUS_ACTIVE)
                                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()));
                        })->orWhere(function ($reserved) {
                            $reserved->where('status', Seat::STATUS_RESERVED)
                                ->where(fn ($q) => $q->whereNull('reserved_until')->orWhere('reserved_until', '>=', now()));
                        });
                    })
                    ->pluck('lock_key')
                    ->all();
            } catch (QueryException $exception) {
                // Seat-lock migration abhi na chali ho to plan list phir bhi chale (sab available dikhenge).
                report($exception);
            }
        }

        $sold = [];

        foreach ($keys as $planId => $key) {
            $sold[$planId] = $key !== null && in_array($key, $taken, true);
        }

        return $sold;
    }

    protected function planLockKey(RoleSubscriptionPlan $plan, array $filter = []): ?string
    {
        if ($plan->isPremium() || blank($plan->role_id)) {
            return null;
        }

        if ($plan->location_level) {
            $location = [];
            foreach (Seat::LEVELS as $name) {
                $fixed = trim((string) ($plan->{$name} ?? ''));
                $location[$name] = $fixed !== '' ? $fixed : ($filter[$name] ?? '');
            }
            $location = $this->trimmedLocation($location, $plan->location_level);

            return $location === null ? null : Seat::makeLockKey((string) $plan->role_id, $plan->location_level, $location);
        }

        $location = [];
        $level = null;
        $gap = false;

        foreach (Seat::LEVELS as $name) {
            $value = trim((string) ($plan->{$name} ?? ''));
            $value = $value !== '' ? $value : trim((string) ($filter[$name] ?? ''));
            $location[$name] = $value;

            if ($value === '') {
                $gap = true;
                continue;
            }

            if ($gap) {
                return null; // beech me gap: seat tay nahi
            }

            $level = $name;
        }

        return $level === null ? null : Seat::makeLockKey((string) $plan->role_id, $level, $location);
    }

    /**
     * Request + plan se seat (role + location + level) nikalo.
     * Plan par jo location fixed hai wo use hoti hai; user ki di hui value plan se match karni chahiye.
     * Purana `state_seat` param state maana jaata hai (backward compatible).
     */
    protected function resolveSeat(Request $request, RoleSubscriptionPlan $plan): array
    {
        if (blank($plan->role_id)) {
            throw ValidationException::withMessages(['plan_id' => 'This plan has no role configured.']);
        }

        $input = $request->validate([
            'state' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'taluka' => ['nullable', 'string', 'max:255'],
            'village' => ['nullable', 'string', 'max:255'],
            'state_seat' => ['nullable', 'string', 'max:255'],
        ]);
        $input['state'] = $input['state'] ?? $input['state_seat'] ?? null;

        $location = [];

        foreach (Seat::LEVELS as $level) {
            $planValue = trim((string) ($plan->{$level} ?? ''));
            $requested = trim((string) ($input[$level] ?? ''));

            if ($planValue !== '' && $requested !== '' && Seat::normalize($planValue) !== Seat::normalize($requested)) {
                throw ValidationException::withMessages([$level => "This plan is only available for {$planValue}."]);
            }

            $location[$level] = $requested !== '' ? $requested : $planValue;
        }

        if ($plan->location_level) {
            $location = $this->trimmedLocation($location, $plan->location_level);

            if ($location === null) {
                throw ValidationException::withMessages([$plan->location_level => "Location is required up to the {$plan->location_level} level."]);
            }

            return $location + [
                'level' => $plan->location_level,
                'label' => $location[$plan->location_level],
                'lock_key' => Seat::makeLockKey((string) $plan->role_id, $plan->location_level, $location),
            ];
        }

        // Level = sabse gehri bhari hui location; beech me gap nahi hona chahiye.
        $seatLevel = null;
        $gap = false;

        foreach (Seat::LEVELS as $level) {
            if ($location[$level] === '') {
                $gap = true;
                continue;
            }

            if ($gap) {
                throw ValidationException::withMessages([$level => 'Select the higher-level location first.']);
            }

            $seatLevel = $level;
        }

        if ($seatLevel === null) {
            throw ValidationException::withMessages(['state' => 'Location is required for a role subscription.']);
        }

        return $location + [
            'level' => $seatLevel,
            'label' => $location[$seatLevel],
            'lock_key' => Seat::makeLockKey((string) $plan->role_id, $seatLevel, $location),
        ];
    }

    /**
     * Seat ko payment se pehle reserve karo. Pehle us seat ki expired/stale hold hatati hai,
     * phir insert karti hai. lock_key UNIQUE hai, isliye race condition me bhi sirf ek jeetega.
     * Seat mil gayi to Seat, kisi aur ke paas hai to null.
     */
    protected function reserveSeat(string $userId, RoleSubscriptionPlan $plan, array $seat): ?Seat
    {
        try {
            return DB::transaction(function () use ($userId, $plan, $seat) {
                Seat::releaseExpired($seat['lock_key']);
                Seat::releaseStaleReservations($seat['lock_key']);

                return Seat::query()->create([
                    'user_id' => $userId,
                    'plan_id' => $plan->id,
                    'role_id' => $plan->role_id,
                    'level' => $seat['level'],
                    'state' => $seat['state'] ?: null,
                    'district' => $seat['district'] ?: null,
                    'taluka' => $seat['taluka'] ?: null,
                    'village' => $seat['village'] ?: null,
                    'state_seat' => $seat['label'], // profile/news me seat ka naam isi se dikhta hai
                    'lock_key' => $seat['lock_key'],
                    'status' => Seat::STATUS_RESERVED,
                    'is_deleted' => 2,
                    'reserved_until' => now()->addMinutes(Seat::RESERVE_MINUTES),
                ]);
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicateLock($exception)) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Payment verify hone par seat ko active karo aur validity (expires_at) set karo.
     * Reservation beech me expire ho gayi ho to seat dobara lock karne ki koshish hoti hai;
     * tab tak kisi aur ne le li ho to SeatUnavailableException.
     */
    protected function activateSeat(SubscriptionPayment $payment, RoleSubscriptionPlan $plan, Carbon $startDate, ?Carbon $endDate): void
    {
        $seat = Seat::query()->where('payment_id', $payment->id)->lockForUpdate()->first();

        if (! $seat) {
            // Deploy se pehle bane orders: purana behaviour - user ki latest pending seat.
            $seat = Seat::query()
                ->where('user_id', $payment->user_id)
                ->where('is_deleted', 2)
                ->whereNull('payment_id')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $seat) {
                return;
            }
        }

        $lockKey = $seat->lock_key ?: $seat->computeLockKey();

        if ($lockKey !== null && $seat->lock_key === null) {
            Seat::releaseExpired($lockKey);
            Seat::releaseStaleReservations($lockKey);
        }

        try {
            $seat->forceFill([
                'lock_key' => $lockKey,
                'status' => Seat::STATUS_ACTIVE,
                'is_deleted' => 1,
                'starts_at' => $startDate->toDateString(),
                'expires_at' => $endDate?->copy()->endOfDay(),
                'reserved_until' => null,
                'released_at' => null,
            ])->save();
        } catch (QueryException $exception) {
            if ($this->isDuplicateLock($exception)) {
                throw new SeatUnavailableException('Seat was taken before payment completed.');
            }

            throw $exception;
        }
    }

    /** Payment ho gaya par seat kisi aur ke paas chali gayi: payment fail mark karo aur refund try karo. */
    protected function handleSeatConflict(Request $request, array $validated): JsonResponse
    {
        $description = 'Seat was taken before payment completed.';

        try {
            $this->razorpay()->payment->fetch($validated['razorpay_payment_id'])->refund(['speed' => 'normal']);
            $description .= ' Refund initiated.';
        } catch (Throwable $exception) {
            report($exception);
            $description .= ' Refund pending - please contact support.';
        }

        SubscriptionPayment::query()
            ->where('razorpay_order_id', $validated['razorpay_order_id'])
            ->where('user_id', (string) $request->user()->id)
            ->update([
                'status' => 'failed',
                'razorpay_payment_id' => $validated['razorpay_payment_id'],
                'failure_code' => 'seat_unavailable',
                'failure_description' => $description,
            ]);

        return response()->json([
            'success' => false,
            'code' => 'seat_unavailable',
            'message' => 'This seat was taken by another user before your payment completed. '.$description,
        ], 409);
    }

    protected function isDuplicateLock(QueryException $exception): bool
    {
        return (string) $exception->getCode() === '23000'
            && (str_contains($exception->getMessage(), 'lock_key') || str_contains($exception->getMessage(), 'Duplicate'));
    }

    protected function formatSeat(Seat $seat): array
    {
        return [
            'id' => $seat->id,
            'role_id' => $seat->role_id,
            'level' => $seat->level,
            'state' => $seat->state,
            'district' => $seat->district,
            'taluka' => $seat->taluka,
            'village' => $seat->village,
            'status' => (int) $seat->status,
            'reserved_until' => $seat->reserved_until,
            'expires_at' => $seat->expires_at,
        ];
    }

    /** Returns a location normalized to a plan level, or null when a required part is missing. */
    protected function trimmedLocation(array $location, string $level): ?array
    {
        $trimmed = [];
        $required = true;

        foreach (Seat::LEVELS as $name) {
            $value = trim((string) ($location[$name] ?? ''));
            if ($required && $value === '') {
                return null;
            }

            $trimmed[$name] = $required ? $value : null;
            if ($name === $level) {
                $required = false;
            }
        }

        return $trimmed;
    }

    /** Roles 1-5 are national; every following block of five maps to one location level. */
    protected function roleAllowsLevel(mixed $roleId, ?string $level): bool
    {
        if (! is_numeric($roleId)) {
            return true; // Existing installations may use textual role identifiers.
        }

        $role = (int) $roleId;
        $expected = match (true) {
            $role >= 1 && $role <= 5 => null,
            $role >= 6 && $role <= 10 => 'state',
            $role >= 11 && $role <= 15 => 'district',
            $role >= 16 && $role <= 20 => 'taluka',
            $role >= 21 && $role <= 25 => 'village',
            default => null,
        };

        return $expected === $level;
    }

    protected function subscriptionSeat(UserSubscription $subscription): ?array
    {
        $seat = Seat::query()->where('user_subscription_id', $subscription->id)->first();

        return $seat ? $this->formatSeat($seat) : null;
    }

    protected function validatePlanPayload(Request $request, bool $isUpdate, ?RoleSubscriptionPlan $existing = null): array
    {
        $required = $isUpdate ? 'nullable' : 'required';

        // Premium plan (plan_type/title se) me role mandatory nahi; baaki plans me pehle jaisa.
        $isPremium = RoleSubscriptionPlan::isPremiumPayload(
            $request->input('plan_type', $existing?->plan_type),
            $request->input('title', $existing?->title),
        );
        $roleRule = $isPremium ? 'nullable' : $required;

        $validated = $request->validate([
            'plan_type' => ['nullable', Rule::in([RoleSubscriptionPlan::TYPE_ROLE, RoleSubscriptionPlan::TYPE_PREMIUM])],
            'role_id' => [$roleRule, 'string', 'max:255'],
            'location_level' => ['nullable', Rule::in(Seat::LEVELS)],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'taluka' => ['nullable', 'string', 'max:255'],
            'village' => ['nullable', 'string', 'max:255'],
            'price' => [$required, 'numeric', 'min:0'],
            'offer_price' => ['nullable', 'numeric', 'min:0'],
            'credits' => [$required, 'integer', 'min:0'],
            'hide_ads' => ['nullable', 'boolean'],
            'days' => ['nullable', 'integer', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::in([0, 1, '0', '1'])],
            'id_deleted' => ['nullable', Rule::in([0, 1, '0', '1'])],
        ]);

        // Admin UI sends empty strings for location fields; persist them as NULL.
        foreach (Seat::LEVELS as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = ($value = trim((string) $validated[$field])) === '' ? null : $value;
            }
        }

        $roleId = $validated['role_id'] ?? $existing?->role_id;
        $level = $validated['location_level'] ?? $existing?->location_level;
        if (! $isPremium && array_key_exists('location_level', $validated) && ! $this->roleAllowsLevel($roleId, $level)) {
            throw ValidationException::withMessages(['location_level' => 'The location level does not match the selected role.']);
        }

        if ($isPremium) {
            $validated['location_level'] = null;
        }

        return $validated;
    }

    protected function formatPlan(RoleSubscriptionPlan $plan, ?bool $isSold = null): array
    {
        return [
            'id' => $plan->id,
            'plan_type' => $plan->plan_type ?? ($plan->isPremium() ? RoleSubscriptionPlan::TYPE_PREMIUM : RoleSubscriptionPlan::TYPE_ROLE),
            'is_premium' => $plan->isPremium(),
            'role_id' => $plan->role_id,
            'location_level' => $plan->location_level,
            'title' => $plan->title,
            'description' => $plan->description,
            'state' => $plan->state,
            'district' => $plan->district,
            'taluka' => $plan->taluka,
            'village' => $plan->village,
            // true = is role + location ki seat kisi ke paas hai (active/unexpired ya payment hold). false = available.
            'is_sold' => $isSold ?? ($this->soldPlans(collect([$plan]))[$plan->id] ?? false),
            'price' => $plan->price,
            'offer_price' => $plan->offer_price,
            'credits' => $plan->credits !== null ? (int) $plan->credits : null,
            'hide_ads' => (bool) $plan->hide_ads,
            'days' => $plan->days !== null ? (int) $plan->days : null,
            'start_date' => $plan->start_date?->toDateString(),
            'end_date' => $plan->end_date?->toDateString(),
            'status' => (int) $plan->status,
            'id_deleted' => (int) $plan->id_deleted,
            'created_at' => $plan->created_at,
            'updated_at' => $plan->updated_at,
        ];
    }

    protected function formatUserSubscription(UserSubscription $subscription): array
    {
        $usedCredits = $this->usedCredits($subscription);
        $totalCredits = (int) ($subscription->plan?->credits ?? 0);

        return [
            'id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'plan_id' => $subscription->plan_id,
            'price' => $subscription->price,
            'start_date' => $subscription->start_date?->toDateString(),
            'end_date' => $subscription->end_date?->toDateString(),
            'payment_status' => (int) $subscription->payment_status,
            'transaction' => $subscription->transaction,
            'status' => (int) $subscription->status,
            'is_deleted' => (int) $subscription->is_deleted,
            'total_credits' => $totalCredits,
            'used_credits' => $usedCredits,
            'remaining_credits' => max(0, $totalCredits - $usedCredits),
            'seat' => $this->subscriptionSeat($subscription),
            'plan' => $subscription->plan ? $this->formatPlan($subscription->plan) : null,
            'created_at' => $subscription->created_at,
            'updated_at' => $subscription->updated_at,
        ];
    }

    protected function formatPayment(SubscriptionPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'razorpay_order_id' => $payment->razorpay_order_id,
            'razorpay_payment_id' => $payment->razorpay_payment_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'failure_code' => $payment->failure_code,
            'failure_description' => $payment->failure_description,
            'created_at' => $payment->created_at,
            'updated_at' => $payment->updated_at,
        ];
    }

    protected function razorpay(): Api
    {
        $key = config('services.razorpay.key');
        $secret = config('services.razorpay.secret');

        if (! $key || ! $secret) {
            abort(503, 'Razorpay credentials are not configured.');
        }

        return new Api($key, $secret);
    }

    protected function usedCredits(UserSubscription $subscription): int
    {
        $query = News::query()
            ->where('user_id', (string) $subscription->user_id)
            ->where('is_deleted', 1);

        if ($subscription->start_date) {
            $query->whereDate('created_at', '>=', $subscription->start_date->toDateString());
        }

        if ($subscription->end_date) {
            $query->whereDate('created_at', '<=', $subscription->end_date->toDateString());
        }

        return $query->count();
    }
}
