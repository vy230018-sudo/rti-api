<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class YoutubeOauthToken extends Model
{
    protected $table = 'youtube_oauth_tokens';

    protected $fillable = [
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
        ];
    }
}