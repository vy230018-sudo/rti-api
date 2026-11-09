<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\AppNotification;
use App\Notifications\NewsLikedNotification;
use App\Models\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LikeController extends Controller
{
    /**
     * Like / unlike toggle.
     * user_id ab body se nahi, login token se aata hai (koi aur ke naam se like nahi ho sakta).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'news_id' => ['required', 'integer', 'exists:news,id'],
        ]);

        $userId = (int) $request->user()->id;
        $newsId = (int) $validated['news_id'];

        [$liked, $like] = DB::transaction(function () use ($userId, $newsId) {
            $like = Like::query()
                ->where('user_id', $userId)
                ->where('news_id', $newsId)
                ->lockForUpdate()
                ->first();

            // Pehli baar like. Unique index (user_id, news_id) race condition se bachata hai.
            if (! $like) {
                $like = Like::createOrFirst(
                    ['user_id' => $userId, 'news_id' => $newsId],
                    ['like' => 1, 'is_deleted' => 1],
                );

                if ($like->wasRecentlyCreated) {
                    $this->bump($newsId, 1);
                }

                return [true, $like];
            }

            $isActive = (int) $like->like === 1 && (int) $like->is_deleted === 1;

            if ($isActive) {
                $like->update(['like' => 0]);
                $this->bump($newsId, -1);

                return [false, $like];
            }

            // Pehle unlike / delete hua tha, ab dobara like
            $like->update(['like' => 1, 'is_deleted' => 1]);
            $this->bump($newsId, 1);

            return [true, $like];
        });

        if ($liked && $like->wasRecentlyCreated) {
            $ownerId = DB::table('news')->where('id', $newsId)->value('user_id');
            AppNotification::dispatchTo($ownerId, $userId, new NewsLikedNotification(
                AppNotification::nameOf($request->user()),
                $newsId,
            ));
        }

        return response()->json([
            'message' => $liked ? 'News liked successfully.' : 'News unliked successfully.',
            'liked' => $liked,
            'likes_count' => $this->currentCount($newsId),
            'like' => $like,
        ]);
    }

    public function destroy(Request $request, int $newsId): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $removed = DB::transaction(function () use ($userId, $newsId) {
            $like = Like::query()
                ->where('user_id', $userId)
                ->where('news_id', $newsId)
                ->where('like', 1)
                ->where('is_deleted', 1)
                ->lockForUpdate()
                ->first();

            if (! $like) {
                return false;
            }

            $like->update(['like' => 0, 'is_deleted' => 0]);
            $this->bump($newsId, -1);

            return true;
        });

        if (! $removed) {
            return response()->json(['message' => 'Like not found.'], 404);
        }

        return response()->json([
            'message' => 'Like removed successfully.',
            'likes_count' => $this->currentCount($newsId),
        ]);
    }

    /** Purana helper (agar kahin aur use ho raha ho to chalta rahega). */
    public function getTotalsByNewsIds(array $newsIds): Collection
    {
        return Like::query()
            ->selectRaw('news_id, COUNT(*) as total_likes')
            ->whereIn('news_id', $newsIds)
            ->where('is_deleted', 1)
            ->where('like', 1)
            ->groupBy('news_id')
            ->pluck('total_likes', 'news_id');
    }

    /**
     * Atomic counter update. DB::table use kiya hai taaki news.updated_at na badle.
     */
    protected function bump(int $newsId, int $by): void
    {
        $query = DB::table('news')->where('id', $newsId);

        if ($by > 0) {
            $query->increment('likes_count', $by);
        } else {
            $query->where('likes_count', '>', 0)->decrement('likes_count', abs($by));
        }
    }

    protected function currentCount(int $newsId): int
    {
        return (int) DB::table('news')->where('id', $newsId)->value('likes_count');
    }
}