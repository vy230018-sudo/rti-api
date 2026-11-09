<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Count;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CountController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'news_id' => ['required', 'integer'],
        ]);

        $count = Count::query()->create([
            'user_id' => (string) $request->user()->id,
            'news_id' => $validated['news_id'],
            'count' => 1,
            'is_deleted' => 1,
        ]);

        return response()->json([
            'message' => 'View count added successfully.',
            'count' => $count,
        ], 201);
    }

    public function getTotalsByNewsIds(array $newsIds): Collection
    {
        return Count::query()
            ->selectRaw('news_id, COUNT(*) as total_views')
            ->whereIn('news_id', $newsIds)
            ->where('is_deleted', 1)
            ->groupBy('news_id')
            ->pluck('total_views', 'news_id');
    }
}
