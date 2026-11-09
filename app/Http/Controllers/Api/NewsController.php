<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\HandlesNewsMedia;
use App\Http\Controllers\Controller;
use App\Models\News;
use App\Models\UserBlock;
use App\Models\UserSubscription;
use App\Services\NewsStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class NewsController extends Controller
{
    use HandlesNewsMedia;

    /** Feed page kitni der (seconds) cache rahega. */
    protected const FEED_CACHE_SECONDS = 20;

    /** Bina active role subscription ke kitni news post ho sakti hain. */
    protected const FREE_NEWS_LIMIT = 2;

    public function __construct(protected NewsStatsService $stats)
    {
    }

    /**
     * Public feed (bina login ke bhi chalta hai), cursor pagination.
     *
     * Speed ka logic:
     *  1. Poora feed page (news + counts + comment preview + author info) sabke liye ek hi baar
     *     banta hai aur FEED_CACHE_SECONDS tak cache hota hai.
     *  2. Har viewer ke liye sirf do cheezein live hoti hain: blocked users hide + liked_by_me.
     */
    public function index(Request $request): JsonResponse
    {
        // Optional filter: ?type=1 (news) ya ?type=2 (feeds/reels). Na bheja to dono aate hain.
        $type = in_array((int) $request->query('type'), [News::TYPE_NEWS, News::TYPE_FEED], true)
            ? (int) $request->query('type')
            : null;

        return $this->servePublicFeed($request, $type);
    }

    protected function servePublicFeed(Request $request, ?int $type): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 10), 1), 30);
        $viewerId = $request->user() ? (int) $request->user()->id : null;

        // ?comments=0 -> latest_comments preview nahi aata (faster first load). Default: pehle jaisa (2 comments).
        $commentPreview = $request->query('comments') === '0' ? 0 : 2;

        $key = 'news_feed:' . NewsStatsService::feedVersion()
            . ':' . ($type ?? 'all')
            . ':c' . $commentPreview
            . ':' . $perPage
            . ':' . md5((string) $request->query('cursor', ''));

        $page = NewsStatsService::remember(
            $key,
            self::FEED_CACHE_SECONDS,
            fn () => $this->buildFeedPage($perPage, $type, $commentPreview)
        );

        $items = collect($page['news']);

        if ($viewerId) {
            // Blocked users ki news hide
            $hidden = UserBlock::hiddenUserIdsFor($viewerId)
                ->map(fn ($id) => (string) $id)
                ->all();

            if ($hidden) {
                $items = $items->reject(fn (array $n) => in_array((string) $n['user_id'], $hidden, true));
            }

            // liked_by_me (live, 1 query)
            $liked = $this->stats->likedSet($items->pluck('id')->all(), $viewerId);

            $items = $items->map(function (array $n) use ($liked) {
                $n['liked_by_me'] = $liked->has($n['id']);

                return $n;
            });
        }

        // Feed page cached ho sakta hai, par views hamesha live dikhne chahiye.
        // Isliye total cached payload mein nahi, har response par batch query se joda jata hai.
        $viewTotals = $this->stats->viewTotals($items->pluck('id')->all());
        $items = $items->map(function (array $n) use ($viewTotals) {
            $n['view_count'] = (int) ($viewTotals[$n['id']] ?? 0);

            return $n;
        });

        return response()->json([
            'success' => true,
            'news' => $items->values(),
            'pagination' => $page['pagination'],
        ]);
    }

    /** Sabke liye same feed page (viewer-specific cheezein baad mein judti hain). */
    protected function buildFeedPage(int $perPage, ?int $type = null, int $commentPreview = 2): array
    {
        $news = News::query()
            ->with('user:id,firstname,lastname,profile_image')
            ->where('status', 1)
            ->where('is_deleted', 1)
            ->when($type !== null, fn ($q) => $q->where('type', $type))
            ->orderByDesc('created_at')
            ->orderByDesc('id') // tie-breaker, cursor ke liye zaroori
            ->cursorPaginate($perPage);

        return [
            'news' => $this->formatMany($news->getCollection(), null, $commentPreview)->all(),
            'pagination' => [
                'per_page' => $news->perPage(),
                'has_more' => $news->hasMorePages(),
                'next_cursor' => $news->nextCursor()?->encode(),
                'next_page_url' => $news->nextPageUrl(),
            ],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        // Active ROLE subscription ho to koi limit nahi. Nahi ho to sirf FREE_NEWS_LIMIT posts.
        // (Quiz / ecom / ad subscriptions alag tables mein hain, yahan nahi ginti.)
        $activeSubscription = $this->activeRoleSubscription($request);
        $totalPosts = 0;

        if (! $activeSubscription) {
            // Soft-deleted posts bhi ginti mein: delete karke free quota dobara nahi milta.
            $totalPosts = News::query()
                ->where('user_id', (string) $request->user()->id)
                ->count();

            if ($totalPosts >= self::FREE_NEWS_LIMIT) {
                return response()->json([
                    'code' => 'NEWS_LIMIT_REACHED',
                    'message' => '2 free news used. Purchase a role subscription.',
                ], 403);
            }
        }

        $validated = $request->validate([
            'type' => ['nullable', 'integer', 'in:1,2'], // 1 = news, 2 = feeds (reels)
            'tittle' => ['required', 'string'],
            'sub_tittle' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'report_type' => ['nullable', 'string', 'max:255'],
            'media_type' => ['nullable', 'integer', 'in:1,2,3,4'],
            'media' => ['nullable'],
            'images' => ['nullable', 'array', 'max:10'],
            'use_frame' => ['nullable', 'boolean'],
            'frame_template' => ['nullable', 'string', 'max:255'],
            'music_id' => ['nullable', 'string', 'max:255'],
            'music_url' => ['nullable', 'string', 'max:2048'],
            'music_volume' => ['nullable', 'numeric', 'between:0,2'], 'voice' => ['nullable', 'file', 'mimes:mp3,wav,m4a,aac,mp4,webm,ogg', 'max:10240'], 'mute_original' => ['nullable', 'boolean'],
        ]);

        // if ($request->boolean('use_frame') && empty($validated['frame_template'])) {
        //     return response()->json(['message' => 'Select a frame template when use_frame is enabled.', 'errors' => ['frame_template' => ['The frame template field is required.']]], 422);
        // }
        // Music/audio settings are metadata only. The frontend is responsible
        // for any video/audio composition, so this endpoint never invokes FFmpeg.
        $requestedMediaType = (int) ($validated['media_type'] ?? 1);

        // Media: public/storage/news/{images|videos|pdfs}/ (ads/profile jaisa)
        // Images (type 1 ya type na bheja ho): images[] ki saari files save hoti hain.
        $mediaType = $requestedMediaType;
        $imagePaths = $mediaType === 1 ? $this->saveNewsImages($request) : [];
        $mediaPath = $mediaType === 1 ? ($imagePaths[0] ?? null) : $this->saveNewsMedia($request);
        $originalMedia = $mediaPath;
        $voicePath = null;

        $news = News::query()->create([
            'user_id' => (string) $request->user()->id,
            'type' => (int) ($validated['type'] ?? News::TYPE_NEWS),
            'tittle' => $validated['tittle'],
            'sub_tittle' => $validated['sub_tittle'] ?? null,
            'description' => $validated['description'] ?? null,
            'report_type' => $validated['report_type'] ?? null,
            'media_type' => $mediaType,
            'media' => $mediaPath,
            'images' => $imagePaths ?: null,
            'use_frame' => (bool) ($validated['use_frame'] ?? false), 'frame_template' => $validated['frame_template'] ?? null, 'frame_merged' => false,
            'music_id' => $validated['music_id'] ?? null, 'music_url' => $validated['music_url'] ?? null, 'music_volume' => $validated['music_volume'] ?? 0.6, 'voice' => $voicePath, 'mute_original' => (bool) ($validated['mute_original'] ?? false), 'original_media' => $originalMedia,
            'status' => 1,
            'is_deleted' => 1,
        ]);

        NewsStatsService::bumpFeedVersion();

        return response()->json([
            'message' => 'News created successfully and sent for admin approval.',
            'news' => $this->formatNews($news->load('user:id,firstname,lastname,profile_image')),
            // Subscriber ke liye limit nahi (null). Credits sirf info ke liye pehle jaise.
            'remaining_credits' => $activeSubscription
                ? max(0, (int) ($activeSubscription->plan?->credits ?? 0) - $this->usedSubscriptionCredits($activeSubscription))
                : null,
            'free_posts_remaining' => $activeSubscription ? null : max(0, self::FREE_NEWS_LIMIT - ($totalPosts + 1)),
        ], 201);
    }

    public function myNews(Request $request): JsonResponse
    {
        $news = News::query()
            ->with('user:id,firstname,lastname,profile_image')
            ->where('user_id', (string) $request->user()->id)
            ->where('status', '1')
            ->where('is_deleted', 1)
            ->when(
                in_array((int) $request->query('type'), [News::TYPE_NEWS, News::TYPE_FEED], true),
                fn ($q) => $q->where('type', (int) $request->query('type'))
            )
            ->latest('created_at')
            ->get();

        return response()->json([
            'news' => $this->formatMany($news, (int) $request->user()->id),
        ]);
    }

    /**
     * Public reels/feed list (type=2).
     *
     * Query parameters: per_page (1-30), cursor, comments (0 to omit comment previews).
     * Response: { success: bool, news: array, pagination: { per_page, has_more, next_cursor, next_page_url } }.
     */
    public function feeds(Request $request): JsonResponse
    {
        return $this->servePublicFeed($request, News::TYPE_FEED);
    }

    /** Public news detail. Uses the same formatter as list/feed, including processed video URL. */
    public function show(Request $request, int $id): JsonResponse
    {
        $news = News::query()->with('user:id,firstname,lastname,profile_image')
            ->whereKey($id)->where('status', 1)->where('is_deleted', 1)->firstOrFail();

        return response()->json(['news' => $this->formatNews($news, $this->stats->forNewsIds([$news->id], $request->user()?->id)[$news->id] ?? [])]);
    }

    public function showMyNews(Request $request, int $id): JsonResponse
    {
        $news = News::query()
            ->where('id', $id)
            ->where('user_id', (string) $request->user()->id)
            ->where('status', 'approved')
            ->where('is_deleted', 1)
            ->firstOrFail();

        return response()->json([
            'news' => $this->formatNews($news),
        ]);
    }

    public function updateMyNews(Request $request, int $id): JsonResponse
    {
        $news = News::query()
            ->where('id', $id)
            ->where('user_id', (string) $request->user()->id)
            ->where('status', '1')
            ->where('is_deleted', 1)
            ->firstOrFail();

        $validated = $request->validate([
            'type' => ['nullable', 'integer', 'in:1,2'], // 1 = news, 2 = feeds (reels)
            'tittle' => ['nullable', 'string', 'max:255'],
            'sub_tittle' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'report_type' => ['nullable', 'string', 'max:255'],
            'media_type' => ['nullable', 'integer', 'in:1,2,3,4'],
            'media' => ['nullable'],
            'use_frame' => ['nullable', 'boolean'],
            'frame_template' => ['nullable', 'string', 'max:255'],
            'music_id' => ['nullable', 'string', 'max:255'],
            'music_url' => ['nullable', 'string', 'max:2048'],
            'music_volume' => ['nullable', 'numeric', 'between:0,2'],
            'voice' => ['nullable', 'file', 'mimes:mp3,wav,m4a,aac,mp4,webm,ogg', 'max:10240'],
            'mute_original' => ['nullable', 'boolean'],
        ]);

        $mediaPath = $news->media; // default: purana media
        $images = $news->images;   // default: purani images
        $newType = (int) ($validated['media_type'] ?? $news->media_type);

        // Naya media aaya tabhi purani file(s) delete hoti hain (ads jaisa)
        $newMediaUploaded = false;
        if ($request->has('media_type') || $request->hasFile('images') || $request->hasFile('media')) {
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
                    $newMediaUploaded = true;
                }
            }
        }

        // Audio settings are saved as metadata; all composition happens in the frontend.
        $voicePath = $news->voice;
        $originalMedia = $newMediaUploaded ? $mediaPath : $news->original_media;

        $news->update([
            'type' => $validated['type'] ?? $news->type,
            'tittle' => $validated['tittle'] ?? $news->tittle,
            'sub_tittle' => $validated['sub_tittle'] ?? $news->sub_tittle,
            'description' => $validated['description'] ?? $news->description,
            'report_type' => $validated['report_type'] ?? $news->report_type,
            'media_type' => $validated['media_type'] ?? $news->media_type,
            'media' => $mediaPath,
            'images' => $images,
            'use_frame' => $validated['use_frame'] ?? $news->use_frame,
            'frame_template' => $validated['frame_template'] ?? (array_key_exists('use_frame', $validated) && ! $validated['use_frame'] ? null : $news->frame_template),
            'frame_merged' => $news->frame_merged,
            'music_id' => $validated['music_id'] ?? $news->music_id,
            'music_url' => $validated['music_url'] ?? $news->music_url,
            'music_volume' => $validated['music_volume'] ?? $news->music_volume,
            'voice' => $voicePath,
            'mute_original' => $validated['mute_original'] ?? $news->mute_original,
            'original_media' => $originalMedia,
        ]);

        NewsStatsService::bumpFeedVersion();

        return response()->json([
            'message' => 'News updated successfully.',
            'news' => $this->formatNews($news->fresh()->load('user:id,firstname,lastname,profile_image')),
        ]);
    }

    public function destroyMyNews(Request $request, int $id): JsonResponse
    {
        $news = News::query()
            ->where('id', $id)
            ->where('user_id', (string) $request->user()->id)
            ->where('status', 'approved')
            ->where('is_deleted', 1)
            ->firstOrFail();

        $news->forceFill([
            'is_deleted' => 0,
        ])->save();

        NewsStatsService::bumpFeedVersion();

        return response()->json([
            'message' => 'News soft deleted successfully.',
        ]);
    }

    /**
     * Share count +1. Public route (login optional).
     * Same user / IP ek ghante mein ek hi news ko dobara share kare to count nahi badhta.
     */
    public function share(Request $request, int $id): JsonResponse
    {
        $who = $request->user()?->id ?? $request->ip();
        $counted = Cache::add("news_share:{$id}:{$who}", 1, now()->addHour());

        if ($counted) {
            $updated = DB::table('news')
                ->where('id', $id)
                ->where('status', 1)
                ->where('is_deleted', 1)
                ->increment('share_count');

            if (! $updated) {
                Cache::forget("news_share:{$id}:{$who}");

                return response()->json(['message' => 'News not found.'], 404);
            }
        }

        return response()->json([
            'success' => true,
            'share_count' => (int) DB::table('news')->where('id', $id)->value('share_count'),
        ]);
    }

    protected function getMediaUrl($news)
    {
        if (! $news->media) {
            return null;
        }

        // agar URL already hai (media_type = 4)
        if ($news->media_type == 4) {
            return $news->media;
        }

        // public/storage ka full URL
        return asset('storage/' . ltrim($news->media, '/'));
    }

    /** User ki active ROLE subscription (user_subscription table). Quiz/ecom/ad plans alag hain. */
    protected function activeRoleSubscription(Request $request): ?UserSubscription
    {
        return UserSubscription::query()
            ->with('plan')
            ->active()
            ->where('user_id', (string) $request->user()->id)
            ->latest('id')
            ->first();
    }

    protected function usedSubscriptionCredits(UserSubscription $subscription): int
    {
        $query = News::query()
            ->where('user_id', (string) $subscription->user_id)
            ->where('is_deleted', 1);

        if ($subscription->start_date) {
            $query->whereDate('created_at', '>=', $subscription->start_date->toDateString());
        }

        if ($subscription->end_date) {
            $query->whereDate('created_at', '<=', $subscription->end_date->toDateString());
        }

        return $query->count();
    }

    /**
     * Kai news ek saath format karo (N+1 ke bina).
     */
    protected function formatMany(Collection $items, ?int $viewerId = null, int $commentPreview = 2): Collection
    {
        $extras = $this->stats->forNewsIds($items->pluck('id')->all(), $viewerId, $commentPreview);
        $authors = $this->stats->authorMeta($items);

        return $items
            ->map(fn (News $item) => $this->formatNews($item, $extras[$item->id] ?? [], $authors))
            ->values();
    }

    protected function formatNews(News $news, array $extras = [], ?array $authors = null): array
    {
        $authors ??= $this->stats->authorMeta(collect([$news]));

        $author = $news->user;
        $key = (string) $news->user_id;

        $roleSubscription = $authors['subscriptions']->get($key);
        $seatName = $authors['seats']->get($key)?->state_seat;

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
            // media_type 1 par saari images ke full URL: ["https://.../img1.jpg", "https://.../img2.jpg"]
            'images' => $this->newsImageUrls($this->newsImagePaths($news)),
            'use_frame' => (bool) $news->use_frame, 'frame_template' => $news->frame_template, 'frame_merged' => (bool) $news->frame_merged,
            'music_id' => $news->music_id, 'music_url' => $news->music_url, 'music_volume' => $news->music_volume, 'voice_url' => $news->voice ? asset('storage/'.ltrim($news->voice, '/')) : null, 'mute_original' => (bool) $news->mute_original,
            'status' => $news->status,

            'likes_count' => (int) $news->likes_count,
            'comments_count' => (int) $news->comments_count,
            'share_count' => (int) $news->share_count,
            'liked_by_me' => $extras['liked_by_me'] ?? false,
            'latest_comments' => $extras['latest_comments'] ?? [],

            'created_at' => $news->created_at,
            'updated_at' => $news->updated_at,
            'user' => $author ? [
                'name' => trim(implode(' ', array_filter([$author->firstname, $author->lastname]))),
                'profile_image' => $author->profile_image,
                'profile_image_url' => $author->profile_image
                    ? asset('storage/' . ltrim($author->profile_image, '/'))
                    : null,
                'has_blue_tick' => (bool) $roleSubscription?->plan?->isPremium(),
                'is_premium' => (bool) $roleSubscription?->plan?->isPremium(),
                'is_subscriber' => $roleSubscription !== null,
                'is_subscribed' => $roleSubscription !== null,
                'role' => $roleSubscription?->plan?->role_id,
                'role_label' => $roleSubscription?->plan?->isPremium() ? 'Premium' : $roleSubscription?->plan?->title,
                'state_seat' => [
                    'seat_name' => $seatName,
                ],
            ] : null,
        ];
    }
}
