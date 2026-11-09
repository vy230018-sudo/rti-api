<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\AppNotification;
use App\Notifications\CommentReplyNotification;
use App\Models\Comment;
use App\Models\CommentLike;
use App\Models\CommentReply;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Comment ke upar: reply, like, edit. Reply: edit, delete, like.
 * (Comment add/list/delete purane CommentController mein hi hain - woh waise hi chalte hain.)
 */
class CommentInteractionController extends Controller
{
    /* ------------------------------------------------------------------ */
    /* Comment edit                                                        */
    /* ------------------------------------------------------------------ */

    public function updateComment(Request $request, int $commentId): JsonResponse
    {
        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:1000', 'required_without:sub_comment'],
            'sub_comment' => ['nullable', 'string', 'max:1000', 'required_without:comment'],
        ]);

        $comment = Comment::query()
            ->where('id', $commentId)
            ->where('user_id', (string) $request->user()->id)
            ->where('is_deleted', 1)
            ->first();

        if (! $comment) {
            return response()->json(['message' => 'Comment not found.'], 404);
        }

        if ($request->filled('comment')) {
            $comment->comment = trim($validated['comment']);
        }

        if ($request->filled('sub_comment')) {
            $comment->sub_comment = trim($validated['sub_comment']);
        }

        $comment->save();
        $comment->load('user:id,firstname,lastname,profile_image');

        return response()->json([
            'message' => 'Comment updated successfully.',
            'comment_detail' => $this->formatComment($comment, (int) $request->user()->id),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Comment like (toggle)                                               */
    /* ------------------------------------------------------------------ */

    public function likeComment(Request $request, int $commentId): JsonResponse
    {
        $comment = Comment::query()->where('id', $commentId)->where('is_deleted', 1)->first();

        if (! $comment) {
            return response()->json(['message' => 'Comment not found.'], 404);
        }

        return $this->toggleLike($request, 'comment', $commentId);
    }

    /* ------------------------------------------------------------------ */
    /* Replies                                                             */
    /* ------------------------------------------------------------------ */

    public function storeReply(Request $request, int $commentId): JsonResponse
    {
        $validated = $request->validate([
            'reply' => ['required', 'string', 'max:1000'],
        ]);

        $comment = Comment::query()->where('id', $commentId)->where('is_deleted', 1)->first();

        if (! $comment) {
            return response()->json(['message' => 'Comment not found.'], 404);
        }

        $userId = (int) $request->user()->id;

        // Block hone par reply nahi (existing comments ki tarah hidden users ka logic).
        if (UserBlock::hiddenUserIdsFor($userId)->contains((int) $comment->user_id)) {
            return response()->json(['message' => 'You cannot reply to this comment.'], 403);
        }

        $reply = CommentReply::query()->create([
            'comment_id' => $comment->id,
            'news_id' => $comment->news_id,
            'user_id' => $userId,
            'reply' => trim($validated['reply']),
            'is_deleted' => 1,
        ]);

        $reply->load('user:id,firstname,lastname,profile_image');

        AppNotification::dispatchTo($comment->user_id, $userId, new CommentReplyNotification(
            AppNotification::nameOf($request->user()),
            (int) $comment->news_id,
            (int) $comment->id,
            $reply->reply,
        ));

        return response()->json([
            'message' => 'Reply added successfully.',
            'reply' => $this->formatReply($reply, $userId),
            'replies_count' => $this->activeReplyCount($comment->id),
        ], 201);
    }

    /** Public. Purane reply pehle. GET /comments/{commentId}/replies?cursor=... */
    public function indexReplies(Request $request, int $commentId): JsonResponse
    {
        $comment = Comment::query()->where('id', $commentId)->where('is_deleted', 1)->first();

        if (! $comment) {
            return response()->json(['message' => 'Comment not found.'], 404);
        }

        $viewerId = $request->user()?->id ? (int) $request->user()->id : null;

        $query = CommentReply::query()
            ->with('user:id,firstname,lastname,profile_image')
            ->where('comment_id', $commentId)
            ->where('is_deleted', 1)
            ->orderBy('id');

        if ($viewerId) {
            $query->whereNotIn('user_id', UserBlock::hiddenUserIdsFor($viewerId));
        }

        $perPage = min(max((int) $request->get('per_page', 20), 1), 50);
        $page = $query->cursorPaginate($perPage);

        $items = $page->getCollection();
        [$counts, $liked] = $this->likeStats('reply', $items->pluck('id')->all(), $viewerId);

        return response()->json([
            'success' => true,
            'comment_id' => $commentId,
            'replies' => $items->map(fn (CommentReply $r) => $this->formatReply($r, $viewerId, $counts, $liked))->values(),
            'has_more' => $page->hasMorePages(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'next_page_url' => $page->nextPageUrl(),
        ]);
    }

    public function updateReply(Request $request, int $replyId): JsonResponse
    {
        $validated = $request->validate([
            'reply' => ['required', 'string', 'max:1000'],
        ]);

        $reply = CommentReply::query()
            ->where('id', $replyId)
            ->where('user_id', (int) $request->user()->id)
            ->where('is_deleted', 1)
            ->first();

        if (! $reply) {
            return response()->json(['message' => 'Reply not found.'], 404);
        }

        $reply->update(['reply' => trim($validated['reply'])]);
        $reply->load('user:id,firstname,lastname,profile_image');

        return response()->json([
            'message' => 'Reply updated successfully.',
            'reply' => $this->formatReply($reply, (int) $request->user()->id),
        ]);
    }

    public function destroyReply(Request $request, int $replyId): JsonResponse
    {
        $reply = CommentReply::query()
            ->where('id', $replyId)
            ->where('user_id', (int) $request->user()->id)
            ->where('is_deleted', 1)
            ->first();

        if (! $reply) {
            return response()->json(['message' => 'Reply not found or already deleted.'], 404);
        }

        $reply->update(['is_deleted' => 0]);

        return response()->json([
            'message' => 'Reply removed successfully.',
            'replies_count' => $this->activeReplyCount($reply->comment_id),
        ]);
    }

    public function likeReply(Request $request, int $replyId): JsonResponse
    {
        $exists = CommentReply::query()->where('id', $replyId)->where('is_deleted', 1)->exists();

        if (! $exists) {
            return response()->json(['message' => 'Reply not found.'], 404);
        }

        return $this->toggleLike($request, 'reply', $replyId);
    }

    /* ------------------------------------------------------------------ */
    /* Comments + likes_count / liked_by_me / replies_count (naya list)    */
    /* ------------------------------------------------------------------ */

    /**
     * Public. Purane GET /comments/{newsId} jaisa hi, bas har comment ke saath
     * likes_count, liked_by_me, replies_count, is_edited bhi aate hain.
     */
    public function detailed(Request $request, int $newsId): JsonResponse
    {
        $viewerId = $request->user()?->id ? (int) $request->user()->id : null;

        $query = Comment::query()
            ->with('user:id,firstname,lastname,profile_image')
            ->where('news_id', $newsId)
            ->where('is_deleted', 1)
            ->orderByDesc('id');

        if ($viewerId) {
            $query->whereNotIn('user_id', UserBlock::hiddenUserIdsFor($viewerId));
        }

        $perPage = min(max((int) $request->get('per_page', 20), 1), 50);
        $page = $query->cursorPaginate($perPage);
        $items = $page->getCollection();
        $ids = $items->pluck('id')->all();

        [$counts, $liked] = $this->likeStats('comment', $ids, $viewerId);

        $replyCounts = $ids === [] ? collect() : CommentReply::query()
            ->selectRaw('comment_id, COUNT(*) as c')
            ->whereIn('comment_id', $ids)
            ->where('is_deleted', 1)
            ->groupBy('comment_id')
            ->pluck('c', 'comment_id');

        return response()->json([
            'success' => true,
            'comments' => $items
                ->map(fn (Comment $c) => $this->formatComment($c, $viewerId, $counts, $liked, $replyCounts))
                ->values(),
            'has_more' => $page->hasMorePages(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'next_page_url' => $page->nextPageUrl(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    protected function toggleLike(Request $request, string $type, int $targetId): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $liked = DB::transaction(function () use ($userId, $type, $targetId) {
            $row = CommentLike::query()
                ->where('user_id', $userId)
                ->where('target_type', $type)
                ->where('target_id', $targetId)
                ->lockForUpdate()
                ->first();

            if ($row) {
                $row->delete();

                return false;
            }

            CommentLike::createOrFirst(['user_id' => $userId, 'target_type' => $type, 'target_id' => $targetId]);

            return true;
        });

        return response()->json([
            'message' => $liked ? 'Liked successfully.' : 'Unliked successfully.',
            'liked' => $liked,
            'likes_count' => CommentLike::query()->where('target_type', $type)->where('target_id', $targetId)->count(),
        ]);
    }

    /** @return array{0: Collection, 1: Collection} [likes_count by id, liked-by-viewer set] */
    protected function likeStats(string $type, array $ids, ?int $viewerId): array
    {
        if ($ids === []) {
            return [collect(), collect()];
        }

        $counts = CommentLike::query()
            ->selectRaw('target_id, COUNT(*) as c')
            ->where('target_type', $type)
            ->whereIn('target_id', $ids)
            ->groupBy('target_id')
            ->pluck('c', 'target_id');

        $liked = $viewerId
            ? CommentLike::query()
                ->where('user_id', $viewerId)
                ->where('target_type', $type)
                ->whereIn('target_id', $ids)
                ->pluck('target_id')
                ->flip()
            : collect();

        return [$counts, $liked];
    }

    protected function activeReplyCount(int $commentId): int
    {
        return CommentReply::query()->where('comment_id', $commentId)->where('is_deleted', 1)->count();
    }

    protected function userArray(?User $u): ?array
    {
        if (! $u) {
            return null;
        }

        return [
            'id' => $u->id,
            'name' => trim(implode(' ', array_filter([$u->firstname, $u->lastname]))),
            'profile_image' => $u->profile_image,
            'profile_image_url' => $u->profile_image
                ? asset('storage/' . ltrim($u->profile_image, '/'))
                : null,
        ];
    }

    protected function isEdited($model): bool
    {
        return $model->updated_at && $model->created_at
            && $model->updated_at->gt($model->created_at->copy()->addSecond());
    }

    protected function formatComment(Comment $c, ?int $viewerId, ?Collection $counts = null, ?Collection $liked = null, ?Collection $replyCounts = null): array
    {
        if ($counts === null) {
            [$counts, $liked] = $this->likeStats('comment', [$c->id], $viewerId);
        }

        return [
            'id' => $c->id,
            'news_id' => $c->news_id,
            'user_id' => $c->user_id,
            'comment' => $c->comment,
            'sub_comment' => $c->sub_comment,
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
            'is_edited' => $this->isEdited($c),
            'likes_count' => (int) ($counts[$c->id] ?? 0),
            'liked_by_me' => (bool) ($liked && $liked->has($c->id)),
            'replies_count' => $replyCounts !== null
                ? (int) ($replyCounts[$c->id] ?? 0)
                : $this->activeReplyCount($c->id),
            'is_mine' => $viewerId !== null && (int) $c->user_id === $viewerId,
            'user' => $this->userArray($c->user),
        ];
    }

    protected function formatReply(CommentReply $r, ?int $viewerId, ?Collection $counts = null, ?Collection $liked = null): array
    {
        if ($counts === null) {
            [$counts, $liked] = $this->likeStats('reply', [$r->id], $viewerId);
        }

        return [
            'id' => $r->id,
            'comment_id' => $r->comment_id,
            'news_id' => $r->news_id,
            'user_id' => $r->user_id,
            'reply' => $r->reply,
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
            'is_edited' => $this->isEdited($r),
            'likes_count' => (int) ($counts[$r->id] ?? 0),
            'liked_by_me' => (bool) ($liked && $liked->has($r->id)),
            'is_mine' => $viewerId !== null && (int) $r->user_id === $viewerId,
            'user' => $this->userArray($r->user),
        ];
    }
}
