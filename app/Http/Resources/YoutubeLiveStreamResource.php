<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class YoutubeLiveStreamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'title' => $this->title, 'description' => $this->description,
            'broadcast_id' => $this->broadcast_id, 'stream_id' => $this->stream_id,
            'stream_key' => $this->stream_key, 'ingestion_address' => $this->ingestion_address,
            'watch_url' => $this->watch_url, 'embed_url' => $this->embed_url,
            'privacy_status' => $this->privacy_status, 'status' => $this->status,
            'scheduled_start_time' => $this->scheduled_start_time?->toISOString(),
            'started_at' => $this->started_at?->toISOString(), 'ended_at' => $this->ended_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
