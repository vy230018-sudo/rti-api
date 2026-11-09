<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EcomSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EcomSubscriptionController extends Controller
{
    public function index(): JsonResponse
    {
        $subscriptions = EcomSubscription::query()
            ->visible()
            ->latest('id')
            ->get()
            ->map(fn (EcomSubscription $subscription) => $this->syncAutoInactiveSubscription($subscription))
            ->filter(fn (EcomSubscription $subscription) => (int) $subscription->status === 1 && (int) $subscription->is_deleted === 1)
            ->values()
            ->map(fn (EcomSubscription $subscription) => $this->formatSubscription($subscription));

        return response()->json([
            'ecom_subscriptions' => $subscriptions,
        ]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $query = EcomSubscription::query()->latest('id');

        if (! $request->boolean('include_deleted')) {
            $query->notDeleted();
        }

        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }

        return response()->json([
            'ecom_subscriptions' => $query->get()->map(fn (EcomSubscription $subscription) => $this->formatSubscription($this->syncAutoInactiveSubscription($subscription))),
        ]);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, false);

        $subscription = EcomSubscription::query()->create([
            'user_id' => $validated['user_id'] ?? (int) $request->user()->id,
            //'sub_id' => $validated['sub_id'],
            'title' => $validated['title'],
            'description' => $validated['description'],
            'credits' => $validated['credits'] ?? null,
            'days' => $validated['days'] ?? null,
            'price' => $validated['price'],
            'offer_price' => $validated['offer_price'],
            'status' => $validated['status'] ?? 1,
            'is_deleted' => 1,
        ]);

        return response()->json([
            'message' => 'Ecom subscription created successfully.',
            'ecom_subscription' => $this->formatSubscription($this->syncAutoInactiveSubscription($subscription->fresh())),
        ], 201);
    }

    public function adminShow(int $id): JsonResponse
    {
        $subscription = EcomSubscription::query()->findOrFail($id);

        return response()->json([
            'ecom_subscription' => $this->formatSubscription($this->syncAutoInactiveSubscription($subscription)),
        ]);
    }

    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $subscription = EcomSubscription::query()->findOrFail($id);
        $validated = $this->validatePayload($request, true);

        $subscription->fill($validated);
        $subscription->save();

        return response()->json([
            'message' => 'Ecom subscription updated successfully.',
            'ecom_subscription' => $this->formatSubscription($this->syncAutoInactiveSubscription($subscription->fresh())),
        ]);
    }

    public function adminUpdateStatus(Request $request, int $id): JsonResponse
    {
        $subscription = EcomSubscription::query()->findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', Rule::in([1, 2, '1', '2'])],
        ]);

        $subscription->forceFill([
            'status' => (int) $validated['status'],
        ])->save();

        return response()->json([
            'message' => 'Ecom subscription status updated successfully.',
            'ecom_subscription' => $this->formatSubscription($this->syncAutoInactiveSubscription($subscription->fresh())),
        ]);
    }

    public function adminDestroy(int $id): JsonResponse
    {
        $subscription = EcomSubscription::query()->findOrFail($id);

        $subscription->forceFill(['is_deleted' => 0])->save();

        return response()->json([
            'message' => 'Ecom subscription soft deleted successfully.',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, false);

        $subscription = EcomSubscription::query()->create([
            'user_id' => (int) $request->user()->id,
            //'sub_id' => $validated['sub_id'],
            'title' => $validated['title'],
            'description' => $validated['description'],
            'credits' => $validated['credits'] ?? null,
            'days' => $validated['days'] ?? null,
            'price' => $validated['price'],
            'offer_price' => $validated['offer_price'],
            'status' => $validated['status'] ?? 1,
            'is_deleted' => 1,
        ]);

        return response()->json([
            'message' => 'Ecom subscription created successfully.',
            'ecom_subscription' => $this->formatSubscription($this->syncAutoInactiveSubscription($subscription->fresh())),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $subscription = EcomSubscription::query()
            ->visible()
            ->findOrFail($id);

        return response()->json([
            'ecom_subscription' => $this->formatSubscription($this->syncAutoInactiveSubscription($subscription)),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $subscription = EcomSubscription::query()
            ->where('user_id', (int) $request->user()->id)
            ->where('is_deleted', 1)
            ->findOrFail($id);

        $validated = $this->validatePayload($request, true);

        $subscription->fill($validated);
        $subscription->save();

        return response()->json([
            'message' => 'Ecom subscription updated successfully.',
            'ecom_subscription' => $this->formatSubscription($this->syncAutoInactiveSubscription($subscription->fresh())),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $subscription = EcomSubscription::query()
            ->where('user_id', (int) $request->user()->id)
            ->findOrFail($id);

        $subscription->forceFill(['is_deleted' => 0])->save();

        return response()->json([
            'message' => 'Ecom subscription soft deleted successfully.',
        ]);
    }

    protected function validatePayload(Request $request, bool $isUpdate): array
    {
        $required = $isUpdate ? 'nullable' : 'required';

        return $request->validate([
            'user_id' => ['nullable', 'integer'],
            //'sub_id' => [$required, 'integer'],
            'title' => [$required, 'string', 'max:255'],
            'description' => [$required, 'string', 'max:255'],
            'credits' => ['nullable', 'integer', 'min:0'],
            'days' => ['nullable', 'integer', 'min:0'],
            'price' => [$required, 'numeric', 'min:0'],
            'offer_price' => [$required, 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in([1, 2, '1', '2'])],
            'is_deleted' => ['nullable', Rule::in([0, 1, '0', '1'])],
        ]);
    }

    protected function formatSubscription(EcomSubscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'sub_id' => $subscription->sub_id,
            'title' => $subscription->title,
            'description' => $subscription->description,
            'credits' => $subscription->credits,
            'days' => $subscription->days,
            'price' => $subscription->price,
            'offer_price' => $subscription->offer_price,
            'status' => (int) $subscription->status,
            'is_deleted' => (int) $subscription->is_deleted,
            'created_at' => $subscription->created_at,
            'updated_at' => $subscription->updated_at,
        ];
    }

    protected function syncAutoInactiveSubscription(EcomSubscription $subscription): EcomSubscription
    {
        $days = (int) ($subscription->days ?? 0);

        if ($days > 0 && $subscription->created_at && $subscription->created_at->copy()->addDays($days)->isPast() && (int) $subscription->status !== 2) {
            $subscription->forceFill(['status' => 2])->save();

            return $subscription->fresh();
        }

        return $subscription;
    }
}
