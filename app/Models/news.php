<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'type', 'tittle', 'sub_tittle', 'description', 'report_type', 'media_type', 'media', 'images', 'use_frame', 'frame_template', 'frame_merged', 'music_id', 'music_url', 'music_volume', 'voice', 'mute_original', 'original_media', 'status', 'is_deleted'])]
class News extends Model
{
    /** news.type: 1 = news, 2 = feeds (reels) */
    public const TYPE_NEWS = 1;
    public const TYPE_FEED = 2;

    protected $table = 'news';

    protected function casts(): array
    {
        return ['images' => 'array', 'type' => 'integer', 'use_frame' => 'boolean', 'frame_merged' => 'boolean', 'mute_original' => 'boolean', 'music_volume' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    // public function referrer(): BelongsTo
    // {
    //     return $this->belongsTo(User::class, 'from_user_id', 'id');
    // }
}
