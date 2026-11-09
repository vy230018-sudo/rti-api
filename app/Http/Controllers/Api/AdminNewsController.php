<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesNewsMedia;
use App\Http\Controllers\Controller;
use App\Models\News;
use App\Services\NewsStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminNewsController extends Controller
{
    use HandlesNewsMedia;

    public function index(Request $request): JsonResponse
    {
        $query = News::query()
            ->with('user')
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Optional: ?type=1 (news) / ?type=2 (feeds)
        if ($request->filled('type')) {
            $query->where('type', (int) $request->type);
        }

        // Active news
        if (!$request->boolean('include_deleted')) {
            $query->where('is_deleted', 1);
        }

        return response()->json([
            'news' => $query->get()->map(fn (News $item) => $this->formatNews($item)),
        ]);
    }

    public function show($id): JsonResponse
    {
        $news = News::with('user')->findOrFail($id);

        return response()->json([
            'news' => $this->formatNews($news),
        ]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $news = News::findOrFail($id);

        $validated = $request->validate([
            'type' => 'nullable|integer|in:1,2', // 1 = news, 2 = feeds (reels)
            'tittle' => 'nullable|string|max:255',
            'sub_tittle' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'report_type' => 'nullable|string|max:255',
            'media_type' => 'nullable|integer|in:1,2,3,4',
            'media' => 'nullable',
            'status' => 'nullable',
        ]);

        $mediaPath = $news->media;
        $images = $news->images;
        $newType = (int) ($validated['media_type'] ?? $news->media_type);

        // Naya media aaya tabhi purani file(s) delete hoti hain
        if ($request->has('media_type') || $request->hasFile('images')) {
            if ($newType === 1) {
                $newImages = $this->saveNewsImages($request);

                if ($newImages !== []) {
                    foreach ($this->newsImagePaths($news) as $old) {
                        $this->deleteNewsMediaFile($old);
                    }
                    $mediaPath = $newImages[0];
                    $images = $newImages;
                }
            } else {
                $newMedia = $this->saveNewsMedia($request);

                if ($newMedia !== null) {
                    foreach ($this->newsImagePaths($news) as $old) {
                        $this->deleteNewsMediaFile($old);
                    }
                    if (empty($news->images)) {
                        $this->deleteNewsMediaFile($news->media);
                    }
                    $mediaPath = $newMedia;
                    $images = null;
                }
            }
        }

        $news->update([
            'type' => $validated['type'] ?? $news->type,
            'tittle' => $validated['tittle'] ?? $news->tittle,
            'sub_tittle' => $validated['sub_tittle'] ?? $news->sub_tittle,
            'description' => $validated['description'] ?? $news->description,
            'report_type' => $validated['report_type'] ?? $news->report_type,
            'media_type' => $validated['media_type'] ?? $news->media_type,
            'media' => $mediaPath,
            'images' => $images,
            'status' => $validated['status'] ?? $news->status,
        ]);

        NewsStatsService::bumpFeedVersion();

        return response()->json([
            'message' => 'News updated successfully.',
            'news' => $this->formatNews($news->fresh('user')),
        ]);
    }

    public function updateStatus(Request $request, $id): JsonResponse
    {
        $news = News::findOrFail($id);

        $request->validate([
            'status' => 'required',
        ]);

        $news->update([
            'status' => $request->status,
        ]);

        NewsStatsService::bumpFeedVersion();

        return response()->json([
            'message' => 'Status updated successfully.',
            'news' => $this->formatNews($news->fresh('user')),
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $news = News::findOrFail($id);

        $news->update([
            'is_deleted' => 0,
        ]);

        NewsStatsService::bumpFeedVersion();

        return response()->json([
            'message' => 'News deleted successfully.',
        ]);
    }

    protected function getMediaUrl(News $news)
    {
        if (!$news->media) {
            return null;
        }

        if ($news->media_type == 4) {
            return $news->media;
        }

        return asset('storage/' . $news->media);
    }

    protected function formatNews(News $news): array
    {
        return [
            'id' => $news->id,
            'user_id' => $news->user_id,
            'type' => (int) ($news->type ?? News::TYPE_NEWS), // 1 = news, 2 = feeds (reels)
            'tittle' => $news->tittle,
            'sub_tittle' => $news->sub_tittle,
            'description' => $news->description,
            'report_type' => $news->report_type,
            'media_type' => $news->media_type,
            'media' => $this->getMediaUrl($news),
            'images' => $this->newsImageUrls($this->newsImagePaths($news)),
            'use_frame' => (bool) $news->use_frame, 'frame_template' => $news->frame_template,
            'music_id' => $news->music_id, 'music_url' => $news->music_url, 'music_volume' => $news->music_volume, 'mute_original' => (bool) $news->mute_original,
            'status' => $news->status,
            'is_deleted' => (int) $news->is_deleted,
            'created_at' => $news->created_at,
            'updated_at' => $news->updated_at,
            'user' => $news->user ? [
                'id' => $news->user->id,
                'firstname' => $news->user->firstname,
                'lastname' => $news->user->lastname,
                'email' => $news->user->email,
            ] : null,
        ];
    }
}
