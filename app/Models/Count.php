<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'news_id', 'count', 'is_deleted'])]
class Count extends Model
{
    protected $table = 'social_media_count';
}
