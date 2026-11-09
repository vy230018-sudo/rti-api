<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\AppNotification;
use App\Notifications\NewCommentNotification;
use App\Models\Comment;
use App\Models\UserBlock;
use App\Services\NewsStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CommentController extends Controller
{
    /**
     * Public (bina login ke bhi chalta hai). 20-20 comments, cursor pagination.
     * Agla page: GET /comments/{newsId}?cursor=<next_cursor>
     */
    public function index(Request $request, int $newsId): JsonResponse
    {
        $query = Comment::query()
            ->with('user:id,firstname,lastname,profile_image')
            ->where('news_id', $newsId)
            ->where('is_deleted', 1)
            ->orderByDesc('id');

        if ($viewer = $request->user()) {
            $query->whereNotIn('user_id', UserBlock::hiddenUserIdsFor((int) $viewer->id));
        }

        $perPage = min(max((int) $request->get('per_page', 20), 1), 50);
        $comments = $query->cursorPaginate($perPage);

        return response()->json([
            'success' => true,
            'comments' => $comments->getCollection()
                ->map(fn (Comment $c) => NewsStatsService::formatComment($c))
                ->values(),
            'has_more' => $comments->hasMorePages(),
            'next_cursor' => $comments->nextCursor()?->encode(),
            'next_page_url' => $comments->nextPageUrl(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'news_id' => ['required', 'integer', 'exists:news,id'],
            'comment' => ['nullable', 'string', 'max:1000', 'required_without:sub_comment'],
            'sub_comment' => ['nullable', 'string', 'max:1000', 'required_without:comment'],
            'status' => ['nullable', 'integer'],
        ]);

        $comment = DB::transaction(function () use ($request, $validated) {
            $comment = Comment::query()->create([
                'user_id' => (string) $request->user()->id,
                'news_id' => $validated['news_id'],
                'comment' => $validated['comment'] ?? null,
                'sub_comment' => $validated['sub_comment'] ?? null,
                'status' => $validated['status'] ?? 1,
                'is_deleted' => 1,
            ]);

            DB::table('news')->where('id', $validated['news_id'])->increment('comments_count');

            return $comment;
        });

        $comment->load('user:id,firstname,lastname,profile_image');

        $news = \App\Models\News::query()->find($comment->news_id);
        if ($news) {
            AppNotification::dispatchTo($news->user_id, $request->user()->id, new NewCommentNotification(
                AppNotification::nameOf($request->user()),
                (int) $news->id,
                (int) $comment->id,
                $comment->comment ?? $comment->sub_comment,
            ));
        }

        return response()->json([
            'message' => 'Comment added successfully.',
            'comment' => $comment,
            'comment_detail' => NewsStatsService::formatComment($comment),
            'comments_count' => (int) DB::table('news')->where('id', $comment->news_id)->value('comments_count'),
        ], 201);
    }

    public function destroy(Request $request, int $commentId): JsonResponse
    {
        $comment = Comment::query()
            ->where('id', $commentId)
            ->where('user_id', $request->user()->id)
            ->where('is_deleted', 1)
            ->first();

        if (! $comment) {
            return response()->json([
                'message' => 'Comment not found or already deleted.',
            ], 404);
        }

        DB::transaction(function () use ($comment) {
            $comment->update(['is_deleted' => 0]);

            DB::table('news')
                ->where('id', $comment->news_id)
                ->where('comments_count', '>', 0)
                ->decrement('comments_count');
        });

        return response()->json([
            'message' => 'Comment removed successfully.',
            'comments_count' => (int) DB::table('news')->where('id', $comment->news_id)->value('comments_count'),
        ]);
    }

    /** Purana helper (agar kahin aur use ho raha ho to chalta rahega). */
    public function getTotalsByNewsIds(array $newsIds): Collection
    {
        return Comment::query()
            ->selectRaw('news_id, COUNT(*) as total_comments')
            ->whereIn('news_id', $newsIds)
            ->where('is_deleted', 1)
            ->groupBy('news_id')
            ->pluck('total_comments', 'news_id');
    }
}