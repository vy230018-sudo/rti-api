<?php

namespace App\Services;

use App\Models\EcomSubscription;
use App\Models\QuizSubscriptionPlan;
use App\Models\RoleSubscriptionPlan;
use App\Models\Seat;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserPlanSubscription;
use App\Models\UserQuizSubscription;
use App\Models\UserSubscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Roles, Ads, Ecom aur Quiz ki payment + subscription history ek hi shape me.
 * Har module ki payment `subscription_payments` me hoti hai (subscription_type = role|ads|ecom|quiz),
 * isliye wahi single source hai; plan, user aur validity batch me load hote hain (N+1 nahi).
 */
class PaymentHistoryService
{
    public const MODULES = ['role', 'ads', 'ecom', 'quiz'];

    public const LABELS = ['role' => 'Role', 'ads' => 'Ads', 'ecom' => 'Ecom', 'quiz' => 'Quiz'];

    /**
     * Filters: user_id, module, plan_id (module ke saath), status (created|paid|failed|all),
     * from, to (created_at date), search (user name/email/phone).
     */
    public function query(array $filters): Builder
    {
        $query = SubscriptionPayment::query()->whereIn('subscription_type', self::MODULES);

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (string) $filters['user_id']);
        }

        if (! empty($filters['module'])) {
            $query->where('subscription_type', $filters['module']);

            if (! empty($filters['plan_id'])) {
                $query->where('plan_id', (string) $filters['plan_id']);
            }
        }

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        if (! empty($filters['search'])) {
            $like = '%'.addcslashes((string) $filters['search'], '\\%_').'%';
            $userIds = User::query()
                ->where(function (Builder $user) use ($like): void {
                    $user->where('firstname', 'like', $like)
                        ->orWhere('lastname', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like);
                })
                ->pluck('id')
                ->map(fn ($id) => (string) $id);

            $query->whereIn('user_id', $userIds);
        }

        return $query->latest('id');
    }

    /** @param Collection<int, SubscriptionPayment> $payments */
    public function rows(Collection $payments): array
    {
        if ($payments->isEmpty()) {
            return [];
        }

        $users = User::query()->whereIn('id', $payments->pluck('user_id')->unique())->get()->keyBy('id');
        $plans = $this->loadPlans($payments);
        $subscriptions = $this->loadSubscriptions($payments);
        $seats = $this->loadSeats($payments);

        return $payments->map(function (SubscriptionPayment $payment) use ($users, $plans, $subscriptions, $seats): array {
            $module = $payment->subscription_type;
            $user = $users->get((int) $payment->user_id);
            $plan = $plans[$module][(int) $payment->plan_id] ?? null;
            $subscription = $module === 'role'
                ? ($subscriptions['role'][(int) $payment->user_subscription_id] ?? null)
                : ($subscriptions[$module][$payment->id] ?? null);

            return [
                'payment_id' => $payment->id,
                'module' => $module,
                'module_label' => self::LABELS[$module] ?? ucfirst((string) $module),
                'user' => [
                    'id' => (int) $payment->user_id,
                    'name' => $user ? trim($user->firstname.' '.$user->lastname) : null,
                    'email' => $user?->email,
                    'phone' => $user?->phone,
                ],
                'plan' => [
                    'id' => (int) $payment->plan_id,
                    'title' => $plan?->title,
                    'days' => $plan?->days,
                    'credits' => $plan?->credits ?? $plan?->quiz_count,
                ],
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'purchase_date' => $subscription?->created_at ?? $payment->created_at,
                'subscription_id' => $subscription?->id,
            ] + $this->validity($subscription) + [
                'razorpay_order_id' => $payment->razorpay_order_id,
                'razorpay_payment_id' => $payment->razorpay_payment_id,
                'failure_description' => $payment->failure_description,
                'seat' => $module === 'role' ? $this->formatSeat($seats[$payment->id] ?? null) : null,
                'created_at' => $payment->created_at,
            ];
        })->all();
    }

    /** @return array{start_date: ?string, end_date: ?string, validity_status: ?string} */
    protected function validity(?Model $subscription): array
    {
        if (! $subscription) {
            return ['start_date' => null, 'end_date' => null, 'validity_status' => null];
        }

        $end = $subscription->end_date;
        $active = (int) $subscription->status === 1
            && (int) $subscription->payment_status === 1
            && (int) ($subscription->is_deleted ?? 1) === 1
            && (! $end || $end->gte(Carbon::today()));

        return [
            'start_date' => $subscription->start_date?->toDateString(),
            'end_date' => $end?->toDateString(),
            'validity_status' => $active ? 'active' : 'expired',
        ];
    }

    /** @return array<string, array<int, Model>> module => [plan_id => plan] */
    protected function loadPlans(Collection $payments): array
    {
        $models = [
            'role' => RoleSubscriptionPlan::class,
            'ads' => SubscriptionPlan::class,
            'ecom' => EcomSubscription::class,
            'quiz' => QuizSubscriptionPlan::class,
        ];
        $plans = [];

        foreach ($payments->groupBy('subscription_type') as $module => $group) {
            if (isset($models[$module])) {
                $plans[$module] = $models[$module]::query()->whereIn('id', $group->pluck('plan_id')->unique())->get()->keyBy('id')->all();
            }
        }

        return $plans;
    }

    /** @return array<string, array<int, Model>> role => [subscription_id => sub]; baaki => [payment_id => sub] */
    protected function loadSubscriptions(Collection $payments): array
    {
        $byModule = $payments->groupBy('subscription_type');
        $result = ['role' => [], 'ads' => [], 'ecom' => [], 'quiz' => []];

        if ($byModule->has('role')) {
            $ids = $byModule['role']->pluck('user_subscription_id')->filter()->unique();
            $result['role'] = UserSubscription::query()->whereIn('id', $ids)->get()->keyBy('id')->all();
        }

        foreach (['ads', 'ecom'] as $module) {
            if ($byModule->has($module)) {
                $result[$module] = UserPlanSubscription::query()
                    ->where('subscription_type', $module)
                    ->whereIn('payment_id', $byModule[$module]->pluck('id'))
                    ->get()->keyBy('payment_id')->all();
            }
        }

        if ($byModule->has('quiz')) {
            $result['quiz'] = UserQuizSubscription::query()
                ->whereIn('payment_id', $byModule['quiz']->pluck('id'))
                ->get()->keyBy('payment_id')->all();
        }

        return $result;
    }

    /** @return array<int, Seat> payment_id => seat (role payments) */
    protected function loadSeats(Collection $payments): array
    {
        $ids = $payments->where('subscription_type', 'role')->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        try {
            return Seat::query()->whereIn('payment_id', $ids)->get()->keyBy('payment_id')->all();
        } catch (Throwable) {
            // seats.payment_id column (seat-lock migration) abhi na chala ho to history phir bhi chale.
            return [];
        }
    }

    protected function formatSeat(?Seat $seat): ?array
    {
        if (! $seat) {
            return null;
        }

        return [
            'level' => $seat->level,
            'state' => $seat->state,
            'district' => $seat->district,
            'taluka' => $seat->taluka,
            'village' => $seat->village,
            'status' => (int) $seat->status,
            'expires_at' => $seat->expires_at,
        ];
    }
}
