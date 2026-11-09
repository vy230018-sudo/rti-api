<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuizSubscriptionPlan;
use App\Models\SubscriptionPayment;
use App\Models\UserQuizSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Razorpay\Api\Api;
use Throwable;

class QuizSubscriptionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['quiz_subscription_plans' => QuizSubscriptionPlan::query()->visible()->latest('id')->get()->map(fn (QuizSubscriptionPlan $plan) => $this->formatPlan($plan))]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $query = QuizSubscriptionPlan::query()->latest('id');
        if (! $request->boolean('include_deleted')) $query->notDeleted();
        if ($request->filled('status')) $query->where('status', (int) $request->input('status'));

        return response()->json(['quiz_subscription_plans' => $query->get()->map(fn (QuizSubscriptionPlan $plan) => $this->formatPlan($plan))]);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $plan = QuizSubscriptionPlan::query()->create(array_merge($this->validatePlan($request, false), ['status' => $request->input('status', 1), 'is_deleted' => 1]));
        return response()->json(['message' => 'Quiz subscription plan created successfully.', 'quiz_subscription_plan' => $this->formatPlan($plan)], 201);
    }

    public function adminShow(int $id): JsonResponse
    {
        return response()->json(['quiz_subscription_plan' => $this->formatPlan(QuizSubscriptionPlan::query()->findOrFail($id))]);
    }

    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $plan = QuizSubscriptionPlan::query()->findOrFail($id);
        $plan->fill($this->validatePlan($request, true))->save();
        return response()->json(['message' => 'Quiz subscription plan updated successfully.', 'quiz_subscription_plan' => $this->formatPlan($plan->fresh())]);
    }

    public function adminUpdateStatus(Request $request, int $id): JsonResponse
    {
        $plan = QuizSubscriptionPlan::query()->findOrFail($id);
        $data = $request->validate(['status' => ['required', Rule::in([0, 1, '0', '1'])]]);
        $plan->forceFill(['status' => (int) $data['status']])->save();
        return response()->json(['message' => 'Quiz subscription plan status updated successfully.', 'quiz_subscription_plan' => $this->formatPlan($plan->fresh())]);
    }

    public function adminDestroy(int $id): JsonResponse
    {
        QuizSubscriptionPlan::query()->findOrFail($id)->forceFill(['is_deleted' => 0])->save();
        return response()->json(['message' => 'Quiz subscription plan soft deleted successfully.']);
    }

    public function adminRestore(int $id): JsonResponse
    {
        $plan = QuizSubscriptionPlan::query()->findOrFail($id);
        $plan->forceFill(['is_deleted' => 1])->save();
        return response()->json(['message' => 'Quiz subscription plan restored successfully.', 'quiz_subscription_plan' => $this->formatPlan($plan->fresh())]);
    }

    public function purchase(Request $request): JsonResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'integer']]);
        $plan = QuizSubscriptionPlan::query()->visible()->findOrFail($data['plan_id']);
        $amount = (float) ($plan->offer_price ?? $plan->price);
        if ($amount <= 0) return response()->json(['message' => 'The selected plan must have a price greater than zero.'], 422);

        try {
            $order = $this->razorpay()->order->create(['receipt' => 'quiz_'.$request->user()->id.'_'.uniqid(), 'amount' => (int) round($amount * 100), 'currency' => 'INR', 'payment_capture' => 1, 'notes' => ['user_id' => (string) $request->user()->id, 'plan_id' => (string) $plan->id, 'subscription_type' => 'quiz']]);
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'Unable to create Razorpay order.'], 502);
        }

        $payment = SubscriptionPayment::query()->create(['user_id' => (string) $request->user()->id, 'plan_id' => (string) $plan->id, 'subscription_type' => 'quiz', 'razorpay_order_id' => $order['id'], 'amount' => $amount, 'currency' => 'INR', 'status' => 'created', 'gateway_response' => $order->toArray()]);
        return response()->json(['message' => 'Razorpay order created. Complete payment and call payment/verify.', 'payment_id' => $payment->id, 'razorpay' => ['key' => config('services.razorpay.key'), 'order_id' => $order['id'], 'amount' => (int) $order['amount'], 'currency' => $order['currency']]], 201);
    }

    public function verifyPayment(Request $request): JsonResponse
    {
        $data = $request->validate(['razorpay_order_id' => ['required', 'string'], 'razorpay_payment_id' => ['required', 'string'], 'razorpay_signature' => ['required', 'string']]);
        try { $this->razorpay()->utility->verifyPaymentSignature($data); } catch (Throwable) {
            SubscriptionPayment::query()->where('razorpay_order_id', $data['razorpay_order_id'])->where('user_id', (string) $request->user()->id)->where('subscription_type', 'quiz')->update(['status' => 'failed', 'failure_code' => 'signature_verification_failed', 'failure_description' => 'Razorpay signature could not be verified.']);
            return response()->json(['message' => 'Payment verification failed.'], 422);
        }

        $subscription = DB::transaction(function () use ($request, $data) {
            $payment = SubscriptionPayment::query()->where('razorpay_order_id', $data['razorpay_order_id'])->where('subscription_type', 'quiz')->lockForUpdate()->firstOrFail();
            abort_unless($payment->user_id === (string) $request->user()->id, 403);
            // Quiz subscriptions must not use user_plan_subscription_id: that column
            // belongs to ads/ecom subscriptions and is constrained to their table.
            if ($payment->status === 'paid') {
                $existing = UserQuizSubscription::query()
                    ->with('plan')
                    ->where('payment_id', $payment->id)
                    ->first();

                if ($existing) return $existing;
            }
            $plan = QuizSubscriptionPlan::query()->visible()->findOrFail($payment->plan_id);
            $startDate = Carbon::today();
            $subscription = UserQuizSubscription::query()->create(['user_id' => $payment->user_id, 'quiz_subscription_plan_id' => $plan->id, 'payment_id' => $payment->id, 'price' => $payment->amount, 'quiz_count' => $plan->quiz_count, 'start_date' => $startDate, 'end_date' => $plan->days ? $startDate->copy()->addDays($plan->days) : null, 'payment_status' => 1, 'transaction' => $data['razorpay_payment_id'], 'status' => 1]);
            $payment->forceFill(['user_quiz_subscription_id' => $subscription->id, 'razorpay_payment_id' => $data['razorpay_payment_id'], 'razorpay_signature' => $data['razorpay_signature'], 'status' => 'paid', 'failure_code' => null, 'failure_description' => null])->save();
            return $subscription->load('plan');
        });

        return response()->json(['message' => 'Payment verified and quiz subscription activated.', 'quiz_subscription' => $this->formatSubscription($subscription)], 201);
    }

    public function mySubscriptions(Request $request): JsonResponse
    {
        $subscriptions = UserQuizSubscription::query()->with('plan')->where('user_id', (string) $request->user()->id)->latest('id')->get()->map(fn (UserQuizSubscription $subscription) => $this->formatSubscription($subscription));
        return response()->json(['quiz_subscriptions' => $subscriptions]);
    }

    public function adminSubscriptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in([0, 1, '0', '1'])],
        ]);

        $query = UserQuizSubscription::query()->with('plan')->latest('id');
        if (isset($data['user_id'])) $query->where('user_id', (string) $data['user_id']);
        if (isset($data['status'])) $query->where('status', (int) $data['status']);

        return response()->json(['quiz_subscriptions' => $query->get()->map(fn (UserQuizSubscription $subscription) => $this->formatSubscription($subscription))]);
    }

    protected function validatePlan(Request $request, bool $isUpdate): array
    {
        $required = $isUpdate ? 'nullable' : 'required';
        return $request->validate(['title' => [$required, 'string', 'max:255'], 'description' => ['nullable', 'string'], 'quiz_count' => [$required, 'integer', 'min:0'], 'days' => ['nullable', 'integer', 'min:1'], 'price' => [$required, 'numeric', 'min:0'], 'offer_price' => ['nullable', 'numeric', 'min:0'], 'status' => ['nullable', Rule::in([0, 1, '0', '1'])]]);
    }

    protected function formatPlan(QuizSubscriptionPlan $plan): array
    {
        return ['id' => $plan->id, 'title' => $plan->title, 'description' => $plan->description, 'quiz_count' => (int) $plan->quiz_count, 'days' => $plan->days === null ? null : (int) $plan->days, 'price' => $plan->price, 'offer_price' => $plan->offer_price, 'status' => (int) $plan->status, 'is_deleted' => (int) $plan->is_deleted, 'created_at' => $plan->created_at, 'updated_at' => $plan->updated_at];
    }

    protected function formatSubscription(UserQuizSubscription $subscription): array
    {
        return ['id' => $subscription->id, 'user_id' => $subscription->user_id, 'quiz_subscription_plan_id' => $subscription->quiz_subscription_plan_id, 'price' => $subscription->price, 'quiz_count' => (int) $subscription->quiz_count, 'start_date' => $subscription->start_date?->toDateString(), 'end_date' => $subscription->end_date?->toDateString(), 'payment_status' => (int) $subscription->payment_status, 'transaction' => $subscription->transaction, 'status' => (int) $subscription->status, 'plan' => $subscription->plan ? $this->formatPlan($subscription->plan) : null, 'created_at' => $subscription->created_at, 'updated_at' => $subscription->updated_at];
    }

    protected function razorpay(): Api
    {
        $key = config('services.razorpay.key');
        $secret = config('services.razorpay.secret');
        abort_unless($key && $secret, 503, 'Razorpay credentials are not configured.');
        return new Api($key, $secret);
    }
}
