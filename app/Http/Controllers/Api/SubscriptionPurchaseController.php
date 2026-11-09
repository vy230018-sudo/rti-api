<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EcomSubscription;
use App\Models\QuizSubscriptionPlan;
use App\Models\RoleSubscriptionPlan;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserPlanSubscription;
use App\Models\UserQuizSubscription;
use App\Models\UserSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Razorpay\Api\Api;
use Throwable;

class SubscriptionPurchaseController extends Controller
{
    /**
     * Current user - all purchased Ads, Ecom and Quiz subscriptions.
     */
    public function mySubscriptions(Request $request): JsonResponse
    {
        $userId = (string) $request->user()->id;

        $adsPlans = SubscriptionPlan::query()->get()->keyBy('id');
        $ecomPlans = EcomSubscription::query()->get()->keyBy('id');
        $quizPlans = QuizSubscriptionPlan::query()->get()->keyBy('id');
        $rolePlans = RoleSubscriptionPlan::query()->get()->keyBy('id');

        $subscriptions = UserPlanSubscription::query()
            ->where('user_id', $userId)
            ->whereIn('subscription_type', ['ads', 'ecom'])
            ->latest('id')
            ->get()
            ->map(function (UserPlanSubscription $subscription) use ($adsPlans, $ecomPlans): array {
                $plan = $subscription->subscription_type === 'ads'
                    ? $adsPlans->get($subscription->plan_id)
                    : $ecomPlans->get($subscription->plan_id);

                return $this->formatMySubscription($subscription, $subscription->subscription_type, $plan);
            })
            ->concat(
                UserSubscription::query()
                    ->where('user_id', $userId)
                    ->latest('id')
                    ->get()
                    ->map(fn (UserSubscription $subscription): array => $this->formatMySubscription($subscription, 'role', $rolePlans->get($subscription->plan_id)))
            )
            ->concat(
                UserQuizSubscription::query()
                    ->where('user_id', $userId)
                    ->latest('id')
                    ->get()
                    ->map(fn (UserQuizSubscription $subscription): array => $this->formatMySubscription($subscription, 'quiz', $quizPlans->get($subscription->quiz_subscription_plan_id)))
            )
            ->sortByDesc('created_at')
            ->values();

        return response()->json([
            'active_subscriptions' => $subscriptions->where('subscription_status', 'active')->values(),
            'expired_subscriptions' => $subscriptions->where('subscription_status', 'expired')->values(),
        ]);
    }

    public function purchase(Request $request, string $type): JsonResponse
    {
        $request->validate(['plan_id' => ['required', 'integer']]);
        $plan = $this->plan($type, (int) $request->input('plan_id'));
        $amount = (float) ($plan->offer_price ?? $plan->price);
        if ($amount <= 0) return response()->json(['message' => 'The selected plan must have a price greater than zero.'], 422);

        try {
            $order = $this->razorpay()->order->create([
                'receipt' => $type.'_'. $request->user()->id.'_'.uniqid(), 'amount' => (int) round($amount * 100), 'currency' => 'INR', 'payment_capture' => 1,
                'notes' => ['user_id' => (string) $request->user()->id, 'plan_id' => (string) $plan->id, 'subscription_type' => $type],
            ]);
        } catch (Throwable $exception) {
            report($exception); return response()->json(['message' => 'Unable to create Razorpay order.'], 502);
        }

        $payment = SubscriptionPayment::query()->create([
            'user_id' => (string) $request->user()->id, 'plan_id' => (string) $plan->id, 'subscription_type' => $type,
            'razorpay_order_id' => $order['id'], 'amount' => $amount, 'currency' => 'INR', 'status' => 'created', 'gateway_response' => $order->toArray(),
        ]);

        return response()->json(['message' => 'Razorpay order created.', 'payment_id' => $payment->id, 'razorpay' => ['key' => config('services.razorpay.key'), 'order_id' => $order['id'], 'amount' => (int) $order['amount'], 'currency' => $order['currency']]], 201);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['razorpay_order_id' => ['required', 'string'], 'razorpay_payment_id' => ['required', 'string'], 'razorpay_signature' => ['required', 'string']]);
        // Quiz payment ka verify QuizSubscriptionController me hota hai (alag subscription table).
        // Isse frontend generic /subscriptions/payment/verify se Ads, Ecom aur Quiz teeno verify kar sakta hai.
        $paymentType = SubscriptionPayment::query()
            ->where('razorpay_order_id', $data['razorpay_order_id'])
            ->where('user_id', (string) $request->user()->id)
            ->value('subscription_type');

        if ($paymentType === 'quiz') {
            return app(QuizSubscriptionController::class)->verifyPayment($request);
        }

        try { $this->razorpay()->utility->verifyPaymentSignature($data); } catch (Throwable) {
            SubscriptionPayment::query()->where('razorpay_order_id', $data['razorpay_order_id'])->where('user_id', (string) $request->user()->id)->update([
                'status' => 'failed', 'failure_code' => 'signature_verification_failed', 'failure_description' => 'Razorpay signature could not be verified.',
            ]);
            return response()->json(['message' => 'Payment verification failed.'], 422);
        }

        $active = DB::transaction(function () use ($request, $data) {
            $payment = SubscriptionPayment::query()->where('razorpay_order_id', $data['razorpay_order_id'])->lockForUpdate()->firstOrFail();
            abort_unless($payment->user_id === (string) $request->user()->id, 403);
            if ($payment->status === 'paid' && $payment->user_plan_subscription_id) return UserPlanSubscription::query()->findOrFail($payment->user_plan_subscription_id);
            abort_unless(in_array($payment->subscription_type, ['ads', 'ecom'], true), 422);
            $plan = $this->plan($payment->subscription_type, (int) $payment->plan_id);
            $start = Carbon::today(); $days = (int) ($plan->days ?? 0);
            $active = UserPlanSubscription::query()->create([
                'user_id' => $payment->user_id, 'plan_id' => $payment->plan_id, 'subscription_type' => $payment->subscription_type, 'payment_id' => $payment->id,
                'price' => $payment->amount, 'credits' => (int) ($plan->credits ?? 0), 'start_date' => $start, 'end_date' => $days > 0 ? $start->copy()->addDays($days) : null,
                'payment_status' => 1, 'transaction' => $data['razorpay_payment_id'], 'status' => 1,
            ]);
            $payment->forceFill(['user_plan_subscription_id' => $active->id, 'razorpay_payment_id' => $data['razorpay_payment_id'], 'razorpay_signature' => $data['razorpay_signature'], 'status' => 'paid'])->save();
            return $active;
        });

        return response()->json(['message' => 'Payment verified and subscription activated.', 'subscription' => $active], 201);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:role,ads,ecom,quiz'],
            'status' => ['nullable', 'in:created,paid,failed'],
        ]);

        $query = SubscriptionPayment::query()->latest('id');
        if (isset($data['type'])) $query->where('subscription_type', $data['type']);
        if (isset($data['status'])) $query->where('status', $data['status']);

        return response()->json([
            'subscription_purchases' => $query->get()->map(fn (SubscriptionPayment $payment) => $this->formatAdminPayment($payment)),
        ]);
    }

    protected function plan(string $type, int $id): SubscriptionPlan|EcomSubscription
    {
        return match ($type) { 'ads' => SubscriptionPlan::query()->visible()->findOrFail($id), 'ecom' => EcomSubscription::query()->visible()->findOrFail($id), default => abort(404, 'Invalid subscription type.') };
    }

    protected function formatMySubscription(Model $subscription, string $type, ?Model $plan): array
    {
        $isActive = (int) $subscription->status === 1
            && (int) $subscription->payment_status === 1
            && (! $subscription->end_date || $subscription->end_date->gte(Carbon::today()));

        return [
            'id' => $subscription->id,
            'subscription_type' => $type,
            'subscription_status' => $isActive ? 'active' : 'expired',
            'plan_id' => $subscription->plan_id ?? $subscription->quiz_subscription_plan_id,
            'plan_title' => $plan?->title,
            'is_premium' => $type === 'role' && $plan?->isPremium(),
            'description' => $plan?->description,
            'price' => $subscription->price,
            'credits' => (int) ($subscription->credits ?? $subscription->quiz_count ?? 0),
            'start_date' => $subscription->start_date?->toDateString(),
            'end_date' => $subscription->end_date?->toDateString(),
            'payment_status' => (int) $subscription->payment_status,
            'status' => (int) $subscription->status,
            'created_at' => $subscription->created_at,
        ];
    }

    protected function formatAdminPayment(SubscriptionPayment $payment): array
    {
        $plan = match ($payment->subscription_type) {
            'role' => RoleSubscriptionPlan::query()->find($payment->plan_id),
            'ads' => SubscriptionPlan::query()->find($payment->plan_id),
            'ecom' => EcomSubscription::query()->find($payment->plan_id),
            'quiz' => QuizSubscriptionPlan::query()->find($payment->plan_id),
            default => null,
        };
        $user = User::query()->find($payment->user_id);

        return [
            'payment_id' => $payment->id,
            'subscription_type' => $payment->subscription_type,
            'payment_status' => $payment->status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'razorpay_order_id' => $payment->razorpay_order_id,
            'razorpay_payment_id' => $payment->razorpay_payment_id,
            'failure_code' => $payment->failure_code,
            'failure_description' => $payment->failure_description,
            'plan' => ['id' => $payment->plan_id, 'title' => $plan?->title, 'credits' => $plan?->credits ?? $plan?->quiz_count, 'days' => $plan?->days],
            'user' => ['id' => $payment->user_id, 'firstname' => $user?->firstname, 'lastname' => $user?->lastname, 'email' => $user?->email, 'phone' => $user?->phone],
            'created_at' => $payment->created_at,
            'updated_at' => $payment->updated_at,
        ];
    }

    protected function razorpay(): Api { return new Api(config('services.razorpay.key'), config('services.razorpay.secret')); }
}
