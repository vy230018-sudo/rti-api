<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuizAttemptAnswer extends Model
{
    protected $table = 'quiz_attempt_answers';

    protected $fillable = [
        'attempt_id',
        'question_id',
        'selected_answer',
        'correct_answer',
        'is_correct',
        'marks_obtained',
    ];

    protected $casts = [
        'attempt_id' => 'integer',
        'question_id' => 'integer',
        'is_correct' => 'boolean',
        'marks_obtained' => 'float',
    ];

    public function attempt()
    {
        return $this->belongsTo(QuizAttempt::class, 'attempt_id');
    }

    public function question()
    {
        return $this->belongsTo(QuestionAns::class, 'question_id');
    }
    public function attemptAnswers()
{
    return $this->hasMany(QuizAttemptAnswer::class, 'question_id');
}
    
}