<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require_once app_path('Models/ads-view.php');

use App\Models\AdsView;

class ads_viewController extends Controller
{
    public function adminIndex(Request $request): JsonResponse
    {
        $query = AdsView::query()->latest('updated_at');

        if (! $request->boolean('include_deleted')) {
            $query->where('is_deleted', 1);
        }

        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }

        if ($request->filled('ads_id')) {
            $query->where('ads_id', (int) $request->input('ads_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        $adsViews = $query->get();

        return response()->json([
            'ads_views' => $adsViews->map(fn (AdsView $adsView) => $this->formatAdsView($adsView)),
            'total_views' => (int) $adsViews->sum('count'),
        ]);
    }

    public function adminShow(int $id): JsonResponse
    {
        $adsView = AdsView::query()->findOrFail($id);

        return response()->json([
            'ads_view' => $this->formatAdsView($adsView),
        ]);
    }

    public function adminTotals(Request $request): JsonResponse
    {
        $query = AdsView::query()
            ->selectRaw('ads_id, SUM(count) as total_views')
            ->where('is_deleted', 1)
            ->where('status', 1)
            ->groupBy('ads_id')
            ->orderBy('ads_id');

        if ($request->filled('ads_id')) {
            $query->where('ads_id', (int) $request->input('ads_id'));
        }

        return response()->json([
            'ads_view_totals' => $query->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ads_id' => ['required', 'integer'],
        ]);

        $adsView = DB::transaction(function () use ($request, $validated): AdsView {
            $adsView = AdsView::query()
                ->where('user_id', (int) $request->user()->id)
                ->where('ads_id', $validated['ads_id'])
                ->lockForUpdate()
                ->first();

            if ($adsView) {
                $adsView->increment('count');
                $adsView->forceFill([
                    'status' => 1,
                    'is_deleted' => 1,
                ])->save();

                return $adsView->fresh();
            }

            return AdsView::query()->create([
                'user_id' => (int) $request->user()->id,
                'ads_id' => $validated['ads_id'],
                'count' => 1,
                'status' => 1,
                'is_deleted' => 1,
            ]);
        });

        return response()->json([
            'message' => 'Ads view count updated successfully.',
            'ads_view' => $this->formatAdsView($adsView),
        ], 201);
    }

    public function show(Request $request, int $adsId): JsonResponse
    {
        $adsView = AdsView::query()
            ->where('user_id', (int) $request->user()->id)
            ->where('ads_id', $adsId)
            ->first();

        return response()->json([
            'ads_view' => $adsView ? $this->formatAdsView($adsView) : null,
            'total_views' => (int) AdsView::query()->active()->where('ads_id', $adsId)->sum('count'),
        ]);
    }

    protected function formatAdsView(AdsView $adsView): array
    {
        return [
            'id' => $adsView->id,
            'user_id' => $adsView->user_id,
            'ads_id' => $adsView->ads_id,
            'count' => $adsView->count,
            'status' => $adsView->status,
            'is_deleted' => $adsView->is_deleted,
            'created_at' => $adsView->created_at,
            'updated_at' => $adsView->updated_at,
        ];
    }
}
