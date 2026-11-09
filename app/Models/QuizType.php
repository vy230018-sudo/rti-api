<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'group_key',
    'title',
    'subject',
    'difficulty',
    'test_type',
    'time_in_min',
    'status',
    'is_deleted'
])]
class QuizType extends Model
{
    protected $table = 'quiz_type';

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'is_deleted' => 'integer',
        ];
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->where('status', 1)
            ->where('is_deleted', 1);
    }

    public function scopeNotDeleted(Builder $query): Builder
    {
        return $query->where('is_deleted', 1);
    }

    public function attempts()
{
    return $this->hasMany(QuizAttempt::class, 'quiz_type_id');
}
}