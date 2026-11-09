<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class YoutubeLiveStream extends Model
{
    protected $fillable = [
        'title', 'description', 'broadcast_id', 'stream_id', 'stream_key',
        'ingestion_address', 'watch_url', 'embed_url', 'privacy_status', 'status',
        'scheduled_start_time', 'started_at', 'ended_at', 'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_start_time' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'stream_key' => 'encrypted',
            'refresh_token' => 'encrypted',
        ];
    }
}
