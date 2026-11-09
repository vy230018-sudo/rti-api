<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    public const TYPE_NEWS = 'news';
    public const TYPE_USER = 'user';

    protected $fillable = [
        'reporter_id', 'reportable_type', 'reportable_id', 'reason', 'details', 'status',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class, 'reportable_id');
    }

    public function reportedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reportable_id');
    }
}
