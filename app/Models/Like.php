<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'news_id', 'like', 'is_deleted'])]
class Like extends Model
{
    protected $table = 'social_media_like';
}
