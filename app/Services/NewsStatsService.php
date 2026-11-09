<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Count;
use App\Models\Like;
use App\Models\Seat;
use App\Models\UserSubscription;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Feed ke liye extra data ek saath (batch) nikalta hai, taaki N+1 queries na bane.
 * Total likes/comments/shares news table ke counter columns se aate hain.
 */
class NewsStatsService
{
    /**
     * @param  array<int>  $ids
     * @return array<int, array{liked_by_me: bool, latest_comments: array}>
     */
    public function forNewsIds(array $ids, ?int $viewerId = null, int $previewLimit = 2): array
    {
        if (! $ids) {
            return [];
        }

        $liked = $this->likedSet($ids, $viewerId);
        // previewLimit 0 = comments preview skip (feed jaldi load karne ke liye 2 queries bachti hain)
        $comments = $previewLimit > 0 ? $this->latestComments($ids, $previewLimit) : collect();

        $result = [];
        foreach ($ids as $id) {
            $result[$id] = [
                'liked_by_me' => $liked->has($id),
                'latest_comments' => $comments->get($id, collect())
                    ->map(fn (Comment $c) => self::formatComment($c))
                    ->values()
                    ->all(),
            ];
        }

        return $result;
    }

    /** Viewer ne in news mein se kaun si like ki hain (1 query). Key = news_id. */
    public function likedSet(array $ids, ?int $viewerId): Collection
    {
        if (! $viewerId || ! $ids) {
            return collect();
        }

        return Like::query()
            ->where('user_id', $viewerId)
            ->whereIn('news_id', $ids)
            ->where('like', 1)
            ->where('is_deleted', 1)
            ->pluck('news_id')
            ->flip();
    }

    /** News IDs ke total recorded views, ek batch query mein. */
    public function viewTotals(array $ids): Collection
    {
        if (! $ids) {
            return collect();
        }

        return Count::query()
            ->whereIn('news_id', $ids)
            ->where('is_deleted', 1)
            ->selectRaw('news_id, COALESCE(SUM(`count`), 0) as total_views')
            ->groupBy('news_id')
            ->pluck('total_views', 'news_id');
    }

    /**
     * Har news ke latest N comments ek hi query mein (UNION ALL, har part index seek).
     * Viral news par bhi fast, kyunki saare comments scan nahi hote.
     *
     * @return Collection<int, Collection<int, Comment>> key = news_id
     */
    protected function latestComments(array $ids, int $limit): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (! $ids) {
            return collect();
        }

        $limit = max(1, $limit);
        $table = (new Comment)->getTable();

        $sql = implode(' UNION ALL ', array_fill(0, count($ids),
            "(SELECT * FROM `{$table}` WHERE news_id = ? AND is_deleted = 1 ORDER BY id DESC LIMIT {$limit})"
        ));

        $rows = Comment::hydrate(array_map(fn ($row) => (array) $row, DB::select($sql, $ids)));

        // Saare comments ke users ek hi query mein
        $rows->load('user:id,firstname,lastname,profile_image');

        return $rows
            ->groupBy('news_id')
            ->map(fn ($group) => $group->sortByDesc('id')->values());
    }

    /**
     * News ke authors ki active subscription + seat (batch).
     *
     * @return array{subscriptions: Collection, seats: Collection}
     */
    public function authorMeta(Collection $newsItems): array
    {
        $authorIds = $newsItems->pluck('user_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();

        if (! $authorIds) {
            return ['subscriptions' => collect(), 'seats' => collect()];
        }

        $subscriptions = UserSubscription::query()
            ->with('plan')
            ->active()
            ->whereIn('user_id', $authorIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn ($subscription) => (string) $subscription->user_id)
            ->map(fn (Collection $items) => $items->first(fn ($subscription) => (bool) $subscription->plan?->isPremium()) ?? $items->first())
            ->keyBy(fn ($s) => (string) $s->user_id);

        $seats = Seat::query()
            ->whereIn('user_id', $authorIds)
            ->where('status', 1)
            ->where('is_deleted', 1)
            ->orderByDesc('id')
            ->get(['id', 'user_id', 'state_seat'])
            ->unique('user_id')
            ->keyBy(fn ($s) => (string) $s->user_id);

        return ['subscriptions' => $subscriptions, 'seats' => $seats];
    }

    public static function formatComment(Comment $c): array
    {
        $u = $c->user;

        return [
            'id' => $c->id,
            'news_id' => $c->news_id,
            'user_id' => $c->user_id,
            'comment' => $c->comment,
            'sub_comment' => $c->sub_comment,
            'created_at' => $c->created_at,
            'user' => $u ? [
                'id' => $u->id,
                'name' => trim(implode(' ', array_filter([$u->firstname, $u->lastname]))),
                'profile_image' => $u->profile_image,
                'profile_image_url' => $u->profile_image
                    ? asset('storage/' . ltrim($u->profile_image, '/'))
                    : null,
            ] : null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Feed cache helpers
    | Cache fail ho jaye (table/redis down) to feed band nahi hoti, seedha DB se banti hai.
    |--------------------------------------------------------------------------
    */

    public static function remember(string $key, int $seconds, Closure $callback): mixed
    {
        try {
            $hit = Cache::get($key);

            if ($hit !== null) {
                return $hit;
            }
        } catch (\Throwable $e) {
            return $callback();
        }

        $value = $callback();

        try {
            Cache::put($key, $value, $seconds);
        } catch (\Throwable $e) {
            // ignore
        }

        return $value;
    }

    /** Feed cache ka version. Badalte hi saara purana feed cache khud invalid ho jaata hai. */
    public static function feedVersion(): string
    {
        try {
            return (string) Cache::rememberForever('news_feed_ver', fn () => (string) microtime(true));
        } catch (\Throwable $e) {
            return '0';
        }
    }

    /** Nayi news aane / edit / delete / status change par call karo. */
    public static function bumpFeedVersion(): void
    {
        try {
            Cache::forever('news_feed_ver', (string) microtime(true));
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
