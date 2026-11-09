<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Comment ya reply par like. target_type = 'comment' | 'reply'. */
class CommentLike extends Model
{
    protected $table = 'comment_likes';

    protected $fillable = ['user_id', 'target_type', 'target_id'];
}
