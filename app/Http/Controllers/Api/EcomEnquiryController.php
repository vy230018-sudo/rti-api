<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\AppNotification;
use App\Notifications\EcomEnquiryNotification;
use App\Models\EcomDetail;
use App\Models\EcomEnquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EcomEnquiryController extends Controller
{
    public function adminIndex(Request $request): JsonResponse
    {
        $query = EcomEnquiry::query()->with('detail')->latest('created_at');

        if (! $request->boolean('include_deleted')) {
            $query->where('is_deleted', 1);
        }

        if ($request->filled('enq_status') || $request->filled('status')) {
            $query->where('enq_status', (int) $request->input('enq_status', $request->input('status')));
        }

        if ($request->filled('ecom_id')) {
            $query->where('ecom_id', (int) $request->input('ecom_id'));
        }

        return response()->json([
            'ecom_enquiries' => $query->get()->map(fn (EcomEnquiry $enquiry) => $this->formatEnquiry($enquiry)),
        ]);
    }

    public function adminShow(int $id): JsonResponse
    {
        $enquiry = EcomEnquiry::query()->findOrFail($id);

        return response()->json([
            'ecom_enquiry' => $this->formatEnquiry($enquiry),
        ]);
    }

    public function adminUpdateStatus(Request $request, int $id): JsonResponse
    {
        $enquiry = EcomEnquiry::query()->findOrFail($id);

        // Old clients used `status`; retain it as an input alias while the DB
        // keeps enquiry workflow state in the new `enq_status` column.
        if (! $request->has('enq_status') && $request->has('status')) {
            $request->merge(['enq_status' => $request->input('status')]);
        }

        $validated = $request->validate([
            'enq_status' => ['required', Rule::in([1, 2, 3, '1', '2', '3'])],
        ]);

        $enquiry->forceFill([
            'enq_status' => (int) $validated['enq_status'],
        ])->save();

        return response()->json([
            'message' => 'Ecom enquiry status updated successfully.',
            'ecom_enquiry' => $this->formatEnquiry($enquiry->fresh()),
        ]);
    }

    public function adminDestroy(int $id): JsonResponse
    {
        $enquiry = EcomEnquiry::query()->findOrFail($id);

        $enquiry->forceFill(['is_deleted' => 0])->save();

        return response()->json([
            'message' => 'Ecom enquiry soft deleted successfully.',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = EcomEnquiry::query()
            ->with('detail')
            ->where('user_id', (int) $request->user()->id)
            ->where('is_deleted', 1)
            ->latest('created_at');

        return response()->json([
            'ecom_enquiries' => $query->get()->map(fn (EcomEnquiry $enquiry) => $this->formatEnquiry($enquiry)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, false);

        $enquiry = EcomEnquiry::query()->create([
            'user_id' => (int) $request->user()->id,
            'ecom_id' => $validated['ecom_id'],
            'type' => $validated['type'] ?? 'ecom',
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
            'email' => $validated['email'] ?? null,
            'contact' => $validated['contact'] ?? null,
            'qty' => $validated['qty'] ?? null,
            'message' => $validated['message'] ?? null,
            'status' => 1,
            'enq_status' => 1,
            'is_deleted' => 1,
        ]);

        $ecom = EcomDetail::query()->find($validated['ecom_id']);
        if ($ecom) {
            AppNotification::dispatchTo($ecom->user_id, $request->user()->id, new EcomEnquiryNotification(
                AppNotification::nameOf($request->user()),
                (int) $ecom->id,
                (int) $enquiry->id,
                $ecom->product_name,
            ));
        }

        return response()->json([
            'message' => 'Ecom enquiry created successfully.',
            'ecom_enquiry' => $this->formatEnquiry($enquiry->fresh()),
        ], 201);
    }

    /**
     * Mere ecom/products par aaye enquiries (ecom owner ke liye).
     * GET /ecom-enquiries/received?ecom_id=&status=&per_page=20&cursor=
     */
    public function received(Request $request): JsonResponse
    {
        $ownerId = (int) $request->user()->id;

        $query = EcomEnquiry::query()
            ->with(['detail', 'user:id,firstname,lastname,profile_image'])
            ->where('is_deleted', 1)
            ->whereHas('detail', fn ($q) => $q->where('user_id', $ownerId))
            ->orderByDesc('id');

        if ($request->filled('ecom_id')) {
            $query->where('ecom_id', (int) $request->input('ecom_id'));
        }

        if ($request->filled('enq_status') || $request->filled('status')) {
            $query->where('enq_status', (int) $request->input('enq_status', $request->input('status')));
        }

        $perPage = min(max((int) $request->get('per_page', 20), 1), 50);
        $page = $query->cursorPaginate($perPage);

        return response()->json([
            'success' => true,
            'ecom_enquiries' => $page->getCollection()
                ->map(fn (EcomEnquiry $enquiry) => $this->formatEnquiry($enquiry, true))
                ->values(),
            'has_more' => $page->hasMorePages(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'next_page_url' => $page->nextPageUrl(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $userId = (int) $request->user()->id;

        // Enquiry dekh sakta hai: jisne bheji (enquirer) ya jiske ecom par aayi (owner).
        $enquiry = EcomEnquiry::query()
            ->with(['detail', 'user:id,firstname,lastname,profile_image'])
            ->where('is_deleted', 1)
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->orWhereHas('detail', fn ($d) => $d->where('user_id', $userId));
            })
            ->findOrFail($id);

        return response()->json([
            'ecom_enquiry' => $this->formatEnquiry($enquiry, true),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $enquiry = EcomEnquiry::query()
            ->where('user_id', (int) $request->user()->id)
            ->where('is_deleted', 1)
            ->findOrFail($id);

        $validated = $this->validatePayload($request, true);

        $enquiry->fill($validated);
        $enquiry->save();

        return response()->json([
            'message' => 'Ecom enquiry updated successfully.',
            'ecom_enquiry' => $this->formatEnquiry($enquiry->fresh()),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $enquiry = EcomEnquiry::query()
            ->where('user_id', (int) $request->user()->id)
            ->findOrFail($id);

        $enquiry->forceFill(['is_deleted' => 0])->save();

        return response()->json([
            'message' => 'Ecom enquiry soft deleted successfully.',
        ]);
    }

    protected function validatePayload(Request $request, bool $isUpdate): array
    {
        $required = $isUpdate ? 'nullable' : 'required';

        // Client `product_id` bheje to wahi ecom_id (product) maana jayega.
        if (! $request->filled('ecom_id') && $request->filled('product_id')) {
            $request->merge(['ecom_id' => $request->input('product_id')]);
        }
        if (! $request->has('enq_status') && $request->has('status')) {
            $request->merge(['enq_status' => $request->input('status')]);
        }

        return $request->validate([
            'ecom_id' => [$required, 'integer'],
            'type' => ['nullable', Rule::in(['ecom', 'ads'])],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'contact' => ['nullable', 'string', 'max:255'],
            'qty' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:255'],
            'enq_status' => ['nullable', Rule::in([1, 2, 3, '1', '2', '3'])],
            'is_deleted' => ['nullable', Rule::in([0, 1, '0', '1'])],
        ]);
    }

    protected function formatEnquiry(EcomEnquiry $enquiry, bool $withEnquirer = false): array
    {
        // Enquiry kis product ke liye hai: ecom_id -> ecom_deatils (product name + images + details).
        $enquiry->loadMissing('detail');
        $product = $enquiry->detail;

        $data = [
            'id' => $enquiry->id,
            'user_id' => $enquiry->user_id,
            'ecom_id' => $enquiry->ecom_id,
            'product_id' => $enquiry->ecom_id,
            // Purane clients ke liye (backward compatible):
            'product_name' => $product?->product_name,
            'product_images' => $product ? $product->imageUrls() : [],
            // Naya: ecom ki poori details.
            'ecom' => $this->formatEcom($product),
            'type' => $enquiry->type,
            'first_name' => $enquiry->first_name,
            'last_name' => $enquiry->last_name,
            'email' => $enquiry->email,
            'contact' => $enquiry->contact,
            'qty' => $enquiry->qty,
            'message' => $enquiry->message,
            'status' => (int) $enquiry->status,
            'enq_status' => (int) ($enquiry->enq_status ?? 1),
            'enq_status_label' => [1 => 'new', 2 => 'review', 3 => 'closed'][(int) ($enquiry->enq_status ?? 1)],
            'is_deleted' => (int) $enquiry->is_deleted,
            'created_at' => $enquiry->created_at,
            'update_at' => $enquiry->update_at,
        ];

        if ($withEnquirer) {
            $enquiry->loadMissing('user:id,firstname,lastname,profile_image');
            $u = $enquiry->user;

            // Kisne enquiry daali (owner ko dikhane ke liye).
            $data['enquirer'] = $u ? [
                'id' => $u->id,
                'name' => trim(implode(' ', array_filter([$u->firstname, $u->lastname]))),
                'profile_image' => $u->profile_image,
                'profile_image_url' => $u->profile_image
                    ? asset('storage/'.ltrim($u->profile_image, '/'))
                    : null,
            ] : null;
        }

        return $data;
    }

    protected function formatEcom(?EcomDetail $product): ?array
    {
        if (! $product) {
            return null;
        }

        $images = $product->imageUrls();

        return [
            'id' => $product->id,
            'user_id' => $product->user_id,
            'type' => $product->type,
            'category' => $product->category,
            'product_name' => $product->product_name,
            'quantity' => $product->quantity,
            'price' => $product->price,
            'image' => $images[0] ?? null,
            'images' => $images,
            'description' => $product->description,
            'city' => $product->city,
            'conatct' => $product->conatct,
            'start_date' => $product->start_date?->toDateString(),
            'end_date' => $product->end_date?->toDateString(),
            'status' => (int) $product->status,
            'is_deleted' => (int) $product->is_deleted,
        ];
    }
}
