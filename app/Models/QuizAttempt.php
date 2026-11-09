<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class QuizAttempt extends Model
{
    protected $table = 'quiz_attempt';

    protected $fillable = [
        'user_id',
        'quiz_type_id',
        'total_questions',
        'correct_answers',
        'wrong_answers',
        'skipped_answers',
        'total_marks',
        'obtained_marks',
        'percentage',
        'started_at',
        'submitted_at',
        'time_taken',
        'status',
        'is_deleted',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'quiz_type_id' => 'integer',
        'total_questions' => 'integer',
        'correct_answers' => 'integer',
        'wrong_answers' => 'integer',
        'skipped_answers' => 'integer',
        'total_marks' => 'float',
        'obtained_marks' => 'float',
        'percentage' => 'float',
        'time_taken' => 'integer',
        'status' => 'integer',
        'is_deleted' => 'integer',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function quizType()
    {
        return $this->belongsTo(QuizType::class, 'quiz_type_id');
    }

    public function answers()
    {
        return $this->hasMany(QuizAttemptAnswer::class, 'attempt_id');
    }

    public function scopeNotDeleted(Builder $query): Builder
    {
        return $query->where('is_deleted', 1);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 1);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 0);
    }
}