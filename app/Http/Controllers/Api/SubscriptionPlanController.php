<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionPlanController extends Controller
{
    public function index(): JsonResponse
    {
        $subscriptionPlans = SubscriptionPlan::query()
            ->visible()
            ->withCount([
                'adsDetails as active_ads_count' => function ($query): void {
                    $query->where('status', 1)->where('is_deleted', 1);
                },
            ])
            ->latest('id')
            ->get();

        return response()->json([
            'subscription_plans' => $subscriptionPlans
                ->map(fn (SubscriptionPlan $plan) => $this->syncAutoInactivePlan($plan))
                ->filter(fn (SubscriptionPlan $plan) => (int) $plan->status === 1 && (int) $plan->is_deleted === 1)
                ->values()
                ->map(fn (SubscriptionPlan $plan) => $this->formatSubscriptionPlan($plan)),
        ]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $query = SubscriptionPlan::query()
            ->withCount([
                'adsDetails as active_ads_count' => function ($query): void {
                    $query->where('status', 1)->where('is_deleted', 1);
                },
            ])
            ->latest('id');

        if (! $request->boolean('include_deleted')) {
            $query->notDeleted();
        }

        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }

        $subscriptionPlans = $query->get();

        return response()->json([
            'subscription_plans' => $subscriptionPlans->map(fn (SubscriptionPlan $plan) => $this->formatSubscriptionPlan($this->syncAutoInactivePlan($plan))),
        ]);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, false);

        $subscriptionPlan = SubscriptionPlan::query()->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'credits' => $validated['credits'],
            'days' => $validated['days'] ?? null,
            'price' => $validated['price'],
            'offer_price' => $validated['offer_price'],
            'status' => $validated['status'] ?? 1,
            'is_deleted' => 1,
        ]);

        return response()->json([
            'message' => 'Subscription ad created successfully.',
            'subscription_plan' => $this->formatSubscriptionPlan($this->syncAutoInactivePlan($subscriptionPlan->fresh())),
        ], 201);
    }

    public function adminShow(int $id): JsonResponse
    {
        $subscriptionPlan = SubscriptionPlan::query()
            ->withCount([
                'adsDetails as active_ads_count' => function ($query): void {
                    $query->where('status', 1)->where('is_deleted', 1);
                },
            ])
            ->findOrFail($id);

        return response()->json([
            'subscription_plan' => $this->formatSubscriptionPlan($this->syncAutoInactivePlan($subscriptionPlan)),
        ]);
    }

    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $subscriptionPlan = SubscriptionPlan::query()->findOrFail($id);
        $validated = $this->validatePayload($request, true);

        $subscriptionPlan->fill($validated);
        $subscriptionPlan->save();

        return response()->json([
            'message' => 'Subscription ad updated successfully.',
            'subscription_plan' => $this->formatSubscriptionPlan($this->syncAutoInactivePlan($subscriptionPlan->fresh())),
        ]);
    }

    public function adminUpdateStatus(Request $request, int $id): JsonResponse
    {
        $subscriptionPlan = SubscriptionPlan::query()->findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', Rule::in([0, 1, '0', '1'])],
        ]);

        $subscriptionPlan->forceFill([
            'status' => (int) $validated['status'],
        ])->save();

        return response()->json([
            'message' => 'Subscription ad status updated successfully.',
            'subscription_plan' => $this->formatSubscriptionPlan($this->syncAutoInactivePlan($subscriptionPlan->fresh())),
        ]);
    }

    public function adminDestroy(int $id): JsonResponse
    {
        $subscriptionPlan = SubscriptionPlan::query()->findOrFail($id);

        $subscriptionPlan->forceFill(['is_deleted' => 0])->save();

        return response()->json([
            'message' => 'Subscription ad soft deleted successfully.',
        ]);
    }

    public function adminRestore(int $id): JsonResponse
    {
        $subscriptionPlan = SubscriptionPlan::query()->findOrFail($id);

        $subscriptionPlan->forceFill(['is_deleted' => 1])->save();

        return response()->json([
            'message' => 'Subscription ad restored successfully.',
            'subscription_plan' => $this->formatSubscriptionPlan($this->syncAutoInactivePlan($subscriptionPlan->fresh())),
        ]);
    }

    protected function validatePayload(Request $request, bool $isUpdate): array
    {
        $required = $isUpdate ? 'nullable' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'description' => [$required, 'string', 'max:500'],
            'credits' => [$required, 'integer', 'min:0'],
            'days' => ['nullable', 'integer', 'min:0'],
            'price' => [$required, 'numeric', 'min:0'],
            'offer_price' => [$required, 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in([0, 1, '0', '1'])],
            'is_deleted' => ['nullable', Rule::in([0, 1, '0', '1'])],
        ]);
    }

    protected function formatSubscriptionPlan(SubscriptionPlan $subscriptionPlan): array
    {
        return [
            'id' => $subscriptionPlan->id,
            'title' => $subscriptionPlan->title,
            'description' => $subscriptionPlan->description,
            'credits' => (int) $subscriptionPlan->credits,
            'days' => $subscriptionPlan->days !== null ? (int) $subscriptionPlan->days : null,
            'price' => $subscriptionPlan->price,
            'offer_price' => $subscriptionPlan->offer_price,
            'status' => (int) $subscriptionPlan->status,
            'is_deleted' => (int) $subscriptionPlan->is_deleted,
            'created_at' => $subscriptionPlan->created_at,
            'updated_at' => $subscriptionPlan->updated_at,
        ];
    }

    protected function syncAutoInactivePlan(SubscriptionPlan $subscriptionPlan): SubscriptionPlan
    {
        $activeAdsCount = $subscriptionPlan->active_ads_count ?? null;

        if ($activeAdsCount === null) {
            $activeAdsCount = $subscriptionPlan->adsDetails()
                ->where('status', 1)
                ->where('is_deleted', 1)
                ->count();
        }

        if ((int) $activeAdsCount >= 2 && (int) $subscriptionPlan->status !== 0) {
            $subscriptionPlan->forceFill(['status' => 0])->save();

            return $subscriptionPlan->fresh();
        }

        return $subscriptionPlan;
    }
}
