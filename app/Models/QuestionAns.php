<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class QuestionAns extends Model
{
    protected $table = 'question_ans';

    protected $fillable = [
        'group_key',
        'quiz_type_id',
        'question',
        'option',
        'correct_ans',
        'explation',
        'marks',
        'status',
        'is_deleted',
    ];

    protected $casts = [
        'option' => 'array',
        'quiz_type_id' => 'integer',
        'status' => 'integer',
        'is_deleted' => 'integer',
    ];

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

    public function quizType()
    {
        return $this->belongsTo(QuizType::class, 'quiz_type_id');
    }
}