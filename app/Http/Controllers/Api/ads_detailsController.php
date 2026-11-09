<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ads_details;
use App\Models\UserBlock;
use App\Models\UserSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ads_detailsController extends Controller
{
    public function adminIndex(Request $request): JsonResponse
    {
        $query = ads_details::query()->with(['user', 'subscription'])->latest('created_at');

        if (! $request->boolean('include_deleted')) {
            $query->where('is_deleted', 1);
        }

        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }

        if ($request->filled('ads_sub_id')) {
            $query->where('ads_sub_id', (string) $request->input('ads_sub_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        return response()->json([
            'ads_details' => $query->get()->map(
                fn (ads_details $adsDetails) => $this->formatAdminAdsDetails($adsDetails)
            ),
        ]);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, false);

        $mediaPath = $this->storeMedia($request);

        $adsDetails = ads_details::query()->create([
            'user_id' => $validated['user_id'] ?? (int) $request->user()->id,
            'ads_sub_id' => $validated['ads_sub_id'],
            'image' => $mediaPath,
            'media_type' => $validated['media_type'] ?? 1,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'redirection' => $validated['redirection'],
            'redirection_url' => $validated['redirection_url'] ?? null,
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'status' => $validated['status'] ?? 1,
            'is_deleted' => $validated['is_deleted'] ?? 1,
        ]);

        return response()->json([
            'message' => 'Ads details created successfully.',
            'ads_details' => $this->formatAdminAdsDetails($adsDetails->fresh()),
        ], 201);
    }

    public function adminShow(int $id): JsonResponse
    {
        $adsDetails = ads_details::query()->findOrFail($id);

        return response()->json([
            'ads_details' => $this->formatAdminAdsDetails($adsDetails),
        ]);
    }

    public function adminUpdate(Request $request, $id): JsonResponse
    {
        $adsDetails = ads_details::query()->findOrFail($id);

        $validated = $this->validatePayload($request, true);

        $mediaPath = $this->storeMedia(
            $request,
            $adsDetails->image,
            (int) ($adsDetails->media_type ?? 1)
        );

        unset($validated['user_id']);

        $adsDetails->fill($validated);
        $adsDetails->image = $mediaPath;
        $adsDetails->save();

        return response()->json([
            'message' => 'Ads details updated successfully.',
            'ads_details' => $this->formatAdminAdsDetails($adsDetails->fresh()),
        ]);
    }

    public function adminUpdateStatus(Request $request, int $id): JsonResponse
    {
        $adsDetails = ads_details::query()->findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', Rule::in([0, 1, '0', '1'])],
        ]);

        $adsDetails->forceFill([
            'status' => (int) $validated['status'],
        ])->save();

        return response()->json([
            'message' => 'Ads details status updated successfully.',
            'ads_details' => $this->formatAdminAdsDetails($adsDetails->fresh()),
        ]);
    }

    public function adminDestroy(int $id): JsonResponse
    {
        $adsDetails = ads_details::query()->findOrFail($id);

        $adsDetails->forceFill([
            'is_deleted' => 0,
        ])->save();

        return response()->json([
            'message' => 'Ads details soft deleted successfully.',
        ]);
    }

    public function adminRestore(int $id): JsonResponse
    {
        $adsDetails = ads_details::query()->findOrFail($id);

        $adsDetails->forceFill([
            'is_deleted' => 1,
        ])->save();

        return response()->json([
            'message' => 'Ads details restored successfully.',
            'ads_details' => $this->formatAdminAdsDetails($adsDetails->fresh()),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        // The setting belongs to the role plan, so the administrator can turn
        // it on/off once and it takes effect for every active subscriber.
        $adsHidden = $request->user() && UserSubscription::query()
            ->active()
            ->where('user_id', (string) $request->user()->id)
            ->whereHas('plan', fn ($query) => $query->where('hide_ads', true))
            ->exists();

        if ($adsHidden) {
            return response()->json([
                'ads_hidden' => true,
                'ads_details' => [],
            ]);
        }

        $query = ads_details::query()
            ->active()
            ->latest('created_at');

        if ($viewer = $request->user()) {
            $query->whereNotIn('user_id', UserBlock::hiddenUserIdsFor((int) $viewer->id));
        }

        if ($request->filled('ads_sub_id')) {
            $query->where(
                'ads_sub_id',
                (string) $request->input('ads_sub_id')
            );
        }

        $today = now()->toDateString();

        $query
            ->where(function ($query) use ($today) {
                $query
                    ->whereNull('start_date')
                    ->orWhereDate('start_date', '<=', $today);
            })
            ->where(function ($query) use ($today) {
                $query
                    ->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $today);
            });

        return response()->json([
            'ads_hidden' => false,
            'ads_details' => $query->get()->map(
                fn (ads_details $adsDetails) =>
                    $this->formatAdsDetails($adsDetails)
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, false);

        $mediaPath = $this->storeMedia($request);

        $adsDetails = ads_details::query()->create([
            'user_id' => (int) $request->user()->id,
            'ads_sub_id' => $validated['ads_sub_id'],
            'image' => $mediaPath,
            'media_type' => $validated['media_type'] ?? 1,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'redirection' => $validated['redirection'],
            'redirection_url' => $validated['redirection_url'] ?? null,
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'status' => $validated['status'] ?? 1,
            'is_deleted' => $validated['is_deleted'] ?? 1,
        ]);

        return response()->json([
            'message' => 'Ads details created successfully.',
            'ads_details' => $this->formatAdsDetails($adsDetails->fresh()),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $adsDetails = ads_details::query()
            ->active()
            ->findOrFail($id);

        return response()->json([
            'ads_details' => $this->formatAdsDetails($adsDetails),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $adsDetails = ads_details::query()
            ->where('user_id', (int) $request->user()->id)
            ->where('is_deleted', 1)
            ->findOrFail($id);

        $validated = $this->validatePayload($request, true);

        $mediaPath = $this->storeMedia(
            $request,
            $adsDetails->image,
            (int) ($adsDetails->media_type ?? 1)
        );

        $adsDetails->fill($validated);
        $adsDetails->image = $mediaPath;
        $adsDetails->save();

        return response()->json([
            'message' => 'Ads details updated successfully.',
            'ads_details' => $this->formatAdsDetails($adsDetails->fresh()),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $adsDetails = ads_details::query()
            ->where('user_id', (int) $request->user()->id)
            ->where('is_deleted', 1)
            ->findOrFail($id);

        $adsDetails->forceFill([
            'is_deleted' => 0,
        ])->save();

        return response()->json([
            'message' => 'Ads details soft deleted successfully.',
        ]);
    }

    protected function validatePayload(Request $request, bool $isUpdate): array
    {
        $required = $isUpdate ? 'nullable' : 'required';

        return $request->validate([
            'user_id' => ['nullable', 'integer'],
            'ads_sub_id' => [$required, 'string', 'max:255'],
            'image' => ['nullable'],
            'video' => ['nullable'],
            'media' => ['nullable'],
            'media_type' => ['nullable', Rule::in([1, 2, '1', '2'])],
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'redirection' => [
                $required,
                Rule::in([1, 2, 3, 4, 5, '1', '2', '3', '4', '5'])
            ],
            'redirection_url' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', Rule::in([0, 1, '0', '1'])],
            'is_deleted' => ['nullable', Rule::in([0, 1, '0', '1'])],
        ]);
    }

    /**
     * Save an image or video directly in public/storage/ads/.
     * The existing `image` database column stores the relative media path,
     * keeping existing ads compatible while `media_type` tells clients how to render it.
     */
    protected function storeMedia(
        Request $request,
        ?string $currentMedia = null,
        int $currentMediaType = 1
    ): ?string {
        $mediaType = (int) $request->input('media_type', $currentMediaType);
        $field = $mediaType === 2 && $request->hasFile('video') ? 'video' : ($mediaType === 2 ? 'media' : 'image');


        /*
        |--------------------------------------------------------------------------
        | New media uploaded
        |--------------------------------------------------------------------------
        */
        if ($request->hasFile($field)) {

            $request->validate($mediaType === 2
                ? [$field => ['file', 'mimes:mp4,mov,avi,webm', 'max:10240']]
                : ['image' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]
            );

            /*
            |--------------------------------------------------------------------------
            | Delete old media first
            |--------------------------------------------------------------------------
            */
            if ($currentMedia) {
                $this->deleteImageFile($currentMedia);
            }

            /*
            |--------------------------------------------------------------------------
            | Make sure folder exists
            |--------------------------------------------------------------------------
            */
            $relativeFolder = $mediaType === 2 ? 'ads/videos' : 'ads/images';
            $folder = public_path('storage/' . $relativeFolder);

            if (! is_dir($folder)) {
                mkdir($folder, 0755, true);
            }

            /*
            |--------------------------------------------------------------------------
            | Generate unique filename
            |--------------------------------------------------------------------------
            */
            $file = $request->file($field);

            $filename = uniqid('ads_', true)
                . '_' . time()
                . '.' . ($file->extension() ?: 'bin');

            /*
            |--------------------------------------------------------------------------
            | Move directly to:
            | public/storage/ads/images/
            |--------------------------------------------------------------------------
            */
            $file->move($folder, $filename);

            /*
            |--------------------------------------------------------------------------
            | Only relative path goes into database
            |--------------------------------------------------------------------------
            */
            return $relativeFolder . '/' . $filename;
        }

        /*
        |--------------------------------------------------------------------------
        | If image URL/path was manually provided
        |--------------------------------------------------------------------------
        */
        if ($mediaType === 1 && $request->filled('image')) {
            return (string) $request->input('image');
        }

        /*
        |--------------------------------------------------------------------------
        | No new image -> keep old image
        |--------------------------------------------------------------------------
        */
        return $currentMedia;
    }

    /**
     * Delete image from:
     * public/storage/ads/images/
     *
     * Also supports old files from:
     * storage/app/public/ads/images/
     */
    protected function deleteImageFile(?string $image): void
    {
        if (! $image) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Do not delete external URLs
        |--------------------------------------------------------------------------
        */
        if (filter_var($image, FILTER_VALIDATE_URL)) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Remove leading slash
        |--------------------------------------------------------------------------
        */
        $image = ltrim($image, '/');

        /*
        |--------------------------------------------------------------------------
        | New location:
        | public/storage/ads/images/
        |--------------------------------------------------------------------------
        */
        $newPath = public_path('storage/' . $image);

        if (is_file($newPath)) {
            @unlink($newPath);
        }

        /*
        |--------------------------------------------------------------------------
        | Old Laravel storage location:
        | storage/app/public/ads/images/
        |
        | This is only for cleaning old images that were uploaded
        | before this controller was changed.
        |--------------------------------------------------------------------------
        */
        $oldPath = storage_path('app/public/' . $image);

        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    /**
     * Admin response: normal ads fields + username + subscription_name.
     * User ya subscription relation na mile to dono field null aati hain.
     */
    protected function formatAdminAdsDetails(ads_details $adsDetails): array
    {
        $adsDetails->loadMissing(['user', 'subscription']);

        $user = $adsDetails->user;
        // users table me `username` column ho to wahi, warna user ka naam.
        $username = $user?->username ?: trim(($user?->firstname ?? '').' '.($user?->lastname ?? ''));

        return $this->formatAdsDetails($adsDetails) + [
            'username' => $username !== '' ? $username : null,
            'subscription_name' => $adsDetails->subscription?->title,
        ];
    }

    protected function formatAdsDetails(ads_details $adsDetails): array
    {
        $path = $adsDetails->image;
        $mediaType = (int) ($adsDetails->media_type ?? 1);
        $mediaUrl = $path && filter_var($path, FILTER_VALIDATE_URL)
            ? $path
            : ($path ? asset('storage/' . ltrim($path, '/')) : null);

        return [
            'id' => $adsDetails->id,
            'user_id' => $adsDetails->user_id,
            'ads_sub_id' => $adsDetails->ads_sub_id,

            'media_type' => $mediaType,
            'media' => $mediaUrl,
            'image' => $mediaType === 1 ? $mediaUrl : null,
            'video' => $mediaType === 2 ? $mediaUrl : null,

            'title' => $adsDetails->title,
            'description' => $adsDetails->description,
            'redirection' => $adsDetails->redirection,
            'redirection_url' => $adsDetails->redirection_url,
            'start_date' => $adsDetails->start_date?->toDateString(),
            'end_date' => $adsDetails->end_date?->toDateString(),
            'status' => $adsDetails->status,
            'is_deleted' => $adsDetails->is_deleted,
            'created_at' => $adsDetails->created_at,
            'updated_at' => $adsDetails->updated_at,
        ];
    }
}
