<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EcomDetail;
use App\Models\EcomSubscription;
use App\Models\UserBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EcomDetailController extends Controller
{
    public function adminIndex(Request $request): JsonResponse
    {
        $query = EcomDetail::query()->latest('created_at');

        if (! $request->boolean('include_deleted')) {
            $query->where('is_deleted', 1);
        }

        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }

        if ($request->filled('ecom_sub_id')) {
            $query->where('ecom_sub_id', (int) $request->input('ecom_sub_id'));
        }

        return response()->json([
            'ecom_details' => $query->get()->map(fn (EcomDetail $detail) => $this->formatDetail($this->syncAutoInactiveDetail($detail))),
        ]);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, false);
        $images = $this->storeImages($request);

        $detail = EcomDetail::query()->create([
            'user_id' => $validated['user_id'] ?? (int) $request->user()->id,
            'ecom_sub_id' => $validated['ecom_sub_id'],
            'type' => $validated['type'] ?? null,
            'category' => $validated['category'],
            'product_name' => $validated['product_name'],
            'quantity' => $validated['quantity'] ?? null,
            'price' => $validated['price'],
            'image' => json_encode($images),
            'description' => $validated['description'] ?? null,
            'city' => $validated['city'],
            'conatct' => $validated['conatct'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'status' => $validated['status'] ?? 1,
            'in_stock' => $validated['in_stock'] ?? true,
            'is_deleted' => 1,
        ]);

        return response()->json([
            'message' => 'Ecom detail created successfully.',
            'ecom_detail' => $this->formatDetail($this->syncAutoInactiveDetail($detail->fresh())),
        ], 201);
    }

    public function adminShow(int $id): JsonResponse
    {
        $detail = EcomDetail::query()->findOrFail($id);

        return response()->json([
            'ecom_detail' => $this->formatDetail($this->syncAutoInactiveDetail($detail)),
        ]);
    }

    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $detail = EcomDetail::query()->findOrFail($id);
        $validated = $this->validatePayload($request, true);
        $images = $this->storeImages($request, $detail->image);

        $detail->fill($validated);
        $detail->image = json_encode($images);
        $detail->save();

        return response()->json([
            'message' => 'Ecom detail updated successfully.',
            'ecom_detail' => $this->formatDetail($this->syncAutoInactiveDetail($detail->fresh())),
        ]);
    }

    public function adminUpdateStatus(Request $request, int $id): JsonResponse
    {
        $detail = EcomDetail::query()->findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', Rule::in([1, 2, '1', '2'])],
        ]);

        $detail->forceFill([
            'status' => (int) $validated['status'],
        ])->save();

        return response()->json([
            'message' => 'Ecom detail status updated successfully.',
            'ecom_detail' => $this->formatDetail($this->syncAutoInactiveDetail($detail->fresh())),
        ]);
    }

    public function adminDestroy(int $id): JsonResponse
    {
        $detail = EcomDetail::query()->findOrFail($id);

        $detail->forceFill(['is_deleted' => 0])->save();

        return response()->json([
            'message' => 'Ecom detail soft deleted successfully.',
        ]);
    }

    public function publicIndex(Request $request): JsonResponse
    {
        $query = EcomDetail::query()
            ->active()
            ->latest('created_at');

        if ($viewer = $request->user()) {
            $query->whereNotIn('user_id', UserBlock::hiddenUserIdsFor((int) $viewer->id));
        }

        if ($request->filled('ecom_sub_id')) {
            $query->where('ecom_sub_id', (int) $request->input('ecom_sub_id'));
        }

        $today = now()->toDateString();

        $query->where(function ($query) use ($today) {
            $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today);
        })->where(function ($query) use ($today) {
            $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
        });

        return response()->json([
            'ecom_details' => $query->get()
                ->map(fn (EcomDetail $detail) => $this->formatDetail($this->syncAutoInactiveDetail($detail)))
                ->filter(fn (array $detail) => (int) $detail['status'] === 1)
                ->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = EcomDetail::query()
            ->where('user_id', (int) $request->user()->id)
            ->where('is_deleted', 1)
            ->latest('created_at');

        if ($request->filled('ecom_sub_id')) {
            $query->where('ecom_sub_id', (int) $request->input('ecom_sub_id'));
        }

        return response()->json([
            'ecom_details' => $query->get()->map(fn (EcomDetail $detail) => $this->formatDetail($this->syncAutoInactiveDetail($detail)))
                ->filter(fn (array $detail) => (int) $detail['is_deleted'] === 1)
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, false);
        $images = $this->storeImages($request);

        $detail = EcomDetail::query()->create([
            'user_id' => (int) $request->user()->id,
            'ecom_sub_id' => $validated['ecom_sub_id'],
            'type' => $validated['type'] ?? null,
            'category' => $validated['category'],
            'product_name' => $validated['product_name'],
            'quantity' => $validated['quantity'] ?? null,
            'price' => $validated['price'],
            'image' => json_encode($images),
            'description' => $validated['description'] ?? null,
            'city' => $validated['city'],
            'conatct' => $validated['conatct'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'status' => $validated['status'] ?? 1,
            'in_stock' => $validated['in_stock'] ?? true,
            'is_deleted' => 1,
        ]);

        return response()->json([
            'message' => 'Ecom detail created successfully.',
            'ecom_detail' => $this->formatDetail($this->syncAutoInactiveDetail($detail->fresh())),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $detail = EcomDetail::query()
            ->where('user_id', (int) $request->user()->id)
            ->where('is_deleted', 1)
            ->findOrFail($id);

        return response()->json([
            'ecom_detail' => $this->formatDetail($this->syncAutoInactiveDetail($detail)),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $detail = EcomDetail::query()
            ->where('user_id', (int) $request->user()->id)
            ->where('is_deleted', 1)
            ->findOrFail($id);

        $validated = $this->validatePayload($request, true);
        $images = $this->storeImages($request, $detail->image);

        $detail->fill($validated);
        $detail->image = json_encode($images);
        $detail->save();

        return response()->json([
            'message' => 'Ecom detail updated successfully.',
            'ecom_detail' => $this->formatDetail($this->syncAutoInactiveDetail($detail->fresh())),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $detail = EcomDetail::query()
            ->where('user_id', (int) $request->user()->id)
            ->findOrFail($id);

        $detail->forceFill(['is_deleted' => 0])->save();

        return response()->json([
            'message' => 'Ecom detail soft deleted successfully.',
        ]);
    }

    protected function validatePayload(Request $request, bool $isUpdate): array
    {
        $required = $isUpdate ? 'nullable' : 'required';
        $imageRequirement = $isUpdate ? 'nullable' : 'required';

        return $request->validate([
            'user_id' => ['nullable', 'integer'],
            'ecom_sub_id' => [$required, 'integer'],
            'type' => ['nullable', Rule::in(['ads_id', 'ecom'])],
            'category' => [$required, 'string', 'max:255'],
            'product_name' => [$required, 'string', 'max:255'],
            'quantity' => ['nullable', 'string', 'max:255'],
            'price' => [$required, 'numeric', 'min:0'],
            'images' => [$imageRequirement, 'array', 'min:1'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'description' => ['nullable', 'string', 'max:1000'],
            'city' => [$required, 'string', 'max:255'],
            'conatct' => [$required, 'string', 'max:255'],
            'start_date' => [$required, 'date'],
            'end_date' => [$required, 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::in([1, 2, '1', '2'])],
            'in_stock' => ['nullable', 'boolean'],
            'is_deleted' => ['nullable', Rule::in([0, 1, '0', '1'])],
        ]);
    }

protected function storeImages(Request $request, ?string $currentImages = null): array
{
    if ($request->hasFile('images')) {

        return collect($request->file('images'))
            ->map(function ($image) {

                // Direct save inside:
                // public/storage/ecom/details/

                $filename = uniqid() . '_' . time() . '.' . $image->getClientOriginalExtension();

                $image->move(
                    public_path('storage/ecom/details'),
                    $filename
                );

                // DB me sirf relative path save hoga
                return 'ecom/details/' . $filename;
            })
            ->values()
            ->all();
    }

    if ($request->filled('images')) {

        $images = $request->input('images');

        if (is_string($images)) {

            $decoded = json_decode($images, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return array_values($decoded);
            }

            return array_values(
                array_filter(
                    array_map('trim', explode(',', $images))
                )
            );
        }

        if (is_array($images)) {
            return array_values($images);
        }
    }

    if ($currentImages) {

        $decoded = json_decode($currentImages, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return array_values($decoded);
        }

        return array_values(
            array_filter(
                array_map('trim', explode(',', $currentImages))
            )
        );
    }

    return [];
}

    protected function syncAutoInactiveDetail(EcomDetail $detail): EcomDetail
    {
        $expired = false;

        if ($detail->end_date && $detail->end_date->isPast()) {
            $expired = true;
        }

        if (! $expired && $detail->subscription) {
            $days = (int) ($detail->subscription->days ?? 0);

            if ($days > 0 && $detail->created_at && $detail->created_at->copy()->addDays($days)->isPast()) {
                $expired = true;
            }
        }

        if ($expired && (int) $detail->status !== 2) {
            $detail->forceFill(['status' => 2])->save();

            return $detail->fresh();
        }

        return $detail;
    }

    protected function formatDetail(EcomDetail $detail): array
    {
        $images = array_map(
            fn (string $image) => filter_var($image, FILTER_VALIDATE_URL)
                ? $image
                : asset('storage/' . ltrim($image, '/')),
            $this->decodeImages($detail->image)
        );

        return [
            'id' => $detail->id,
            'user_id' => $detail->user_id,
            'ecom_sub_id' => $detail->ecom_sub_id,
            'type' => $detail->type,
            'category' => $detail->category,
            'product_name' => $detail->product_name,
            'quantity' => $detail->quantity,
            'price' => $detail->price,
            'image' => $images[0] ?? null,
            'images' => $images,
            'description' => $detail->description,
            'city' => $detail->city,
            'conatct' => $detail->conatct,
            'start_date' => $detail->start_date?->toDateString(),
            'end_date' => $detail->end_date?->toDateString(),
            'status' => (int) $detail->status,
            'in_stock' => (bool) $detail->in_stock,
            'is_deleted' => (int) $detail->is_deleted,
            'created_at' => $detail->created_at,
            'updated_at' => $detail->updated_at,
        ];
    }

    protected function decodeImages(?string $images): array
    {
        if (! $images) {
            return [];
        }

        $decoded = json_decode($images, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return array_values($decoded);
        }

        return array_values(array_filter(array_map('trim', explode(',', $images))));
    }
}
