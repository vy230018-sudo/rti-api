<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuestionAns;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptAnswer;
use App\Models\QuizType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class QuizController extends Controller
{
    /**
     * User - Start Quiz
     */
public function startQuiz(Request $request): JsonResponse
{
    $validated = $request->validate([
        'user_id' => ['required', 'integer'],
        'quiz_type_id' => ['required', 'integer'],
    ]);

    $quizType = QuizType::query()
        ->visible()
        ->find($validated['quiz_type_id']);

    if (! $quizType) {
        return response()->json([
            'message' => 'Quiz not found.',
        ], 404);
    }

    $questions = QuestionAns::query()
        ->visible()
        ->where('quiz_type_id', $validated['quiz_type_id'])
        ->inRandomOrder()
        ->get();

    if ($questions->isEmpty()) {
        return response()->json([
            'message' => 'No questions found.',
        ], 404);
    }

    $attempt = QuizAttempt::create([
        'user_id' => $validated['user_id'],
        'quiz_type_id' => $validated['quiz_type_id'],
        'total_questions' => $questions->count(),
        'started_at' => now(),
        'status' => 0,
    ]);

    return response()->json([
        'message' => 'Quiz started successfully.',
        'attempt_id' => $attempt->id,
        'quiz' => [
            'id' => $quizType->id,
            'title' => $quizType->title,
            'subject' => $quizType->subject,
            'difficulty' => $quizType->difficulty,
            'time_in_min' => $quizType->time_in_min,
        ],
        'questions' => $questions->map(function (QuestionAns $question) {
            return [
                'id' => $question->id,
                'question' => $question->question,
                'option' => $question->option,
                'marks' => $question->marks,
            ];
        }),
    ]);
}

    /**
     * User - Submit Quiz
     */
public function submitQuiz(Request $request): JsonResponse
{
    $validated = $request->validate([
        'attempt_id' => ['required', 'integer'],
        'answers' => ['required', 'array'],

        'answers.*.question_id' => ['required', 'integer'],
        'answers.*.selected_answer' => ['nullable', 'string'],
    ]);

    DB::beginTransaction();

    try {

        $attempt = QuizAttempt::findOrFail($validated['attempt_id']);

        $correct = 0;
        $wrong = 0;
        $skipped = 0;
        $obtainedMarks = 0;
        $totalMarks = 0;

        foreach ($validated['answers'] as $answer) {

            $question = QuestionAns::findOrFail($answer['question_id']);

            $selected = $answer['selected_answer'] ?? null;

            $isCorrect = 0;
            $marks = 0;

            $totalMarks += $question->marks;

            if (empty($selected)) {

                $skipped++;

            } elseif ($selected == $question->correct_ans) {

                $correct++;
                $isCorrect = 1;
                $marks = $question->marks;
                $obtainedMarks += $question->marks;

            } else {

                $wrong++;
            }

            QuizAttemptAnswer::create([
                'attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'selected_answer' => $selected,
                'correct_answer' => $question->correct_ans,
                'is_correct' => $isCorrect,
                'marks_obtained' => $marks,
            ]);
        }

        $percentage = $totalMarks > 0
            ? round(($obtainedMarks / $totalMarks) * 100, 2)
            : 0;

        $attempt->update([
            'correct_answers' => $correct,
            'wrong_answers' => $wrong,
            'skipped_answers' => $skipped,
            'total_marks' => $totalMarks,
            'obtained_marks' => $obtainedMarks,
            'percentage' => $percentage,
            'submitted_at' => now(),
            'time_taken' => now()->diffInSeconds($attempt->started_at),
            'status' => 1,
        ]);

        DB::commit();

        return response()->json([
            'message' => 'Quiz submitted successfully.',
            'result' => [
                'attempt_id' => $attempt->id,
                'total_questions' => $attempt->total_questions,
                'correct_answers' => $correct,
                'wrong_answers' => $wrong,
                'skipped_answers' => $skipped,
                'obtained_marks' => $obtainedMarks,
                'total_marks' => $totalMarks,
                'percentage' => $percentage,
            ]
        ]);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([
            'message' => $e->getMessage(),
        ], 500);
    }
}

    /**
     * User - Quiz Result
     */
public function result(int $attemptId): JsonResponse
{
    $attempt = QuizAttempt::notDeleted()->with([
        'quizType',
        'answers.question'
    ])->findOrFail($attemptId);

    return response()->json([
        'result' => [
            'attempt_id' => $attempt->id,
            'quiz_type_id' => $attempt->quiz_type_id,
            'quiz_title' => optional($attempt->quizType)->title,
            'total_questions' => $attempt->total_questions,
            'correct_answers' => $attempt->correct_answers,
            'wrong_answers' => $attempt->wrong_answers,
            'skipped_answers' => $attempt->skipped_answers,
            'obtained_marks' => $attempt->obtained_marks,
            'total_marks' => $attempt->total_marks,
            'percentage' => $attempt->percentage,
            'started_at' => $attempt->started_at,
            'submitted_at' => $attempt->submitted_at,
            'time_taken' => $attempt->time_taken,
            'status' => $attempt->status,
        ],
        'answers' => $attempt->answers->map(function ($answer) {
            return [
                'question_id' => $answer->question_id,
                'question' => optional($answer->question)->question,
                'selected_answer' => $answer->selected_answer,
                'correct_answer' => $answer->correct_answer,
                'is_correct' => (bool) $answer->is_correct,
                'marks_obtained' => $answer->marks_obtained,
            ];
        }),
    ]);
}

    /**
     * User - Quiz History
     */
public function history(Request $request): JsonResponse
{
    $validated = $request->validate([
        'user_id' => ['required', 'integer'],
    ]);

    $attempts = QuizAttempt::notDeleted()->with('quizType')
        ->where('user_id', $validated['user_id'])
        ->latest('id')
        ->get();

    return response()->json([
        'history' => $attempts->map(function (QuizAttempt $attempt) {

            return [
                'attempt_id' => $attempt->id,
                'quiz_type' => match (optional($attempt->quizType)->test_type) {
                        1 => 'practice',
                        2 => 'training',
                        3 => 'exam',
                        default => null,
                    },                'quiz_type_id' => $attempt->quiz_type_id,
                'quiz_title' => optional($attempt->quizType)->title,
                'subject' => optional($attempt->quizType)->subject,
                'difficulty' => optional($attempt->quizType)->difficulty,

                'total_questions' => $attempt->total_questions,
                'correct_answers' => $attempt->correct_answers,
                'wrong_answers' => $attempt->wrong_answers,
                'skipped_answers' => $attempt->skipped_answers,

                'obtained_marks' => $attempt->obtained_marks,
                'total_marks' => $attempt->total_marks,
                'percentage' => $attempt->percentage,

                'started_at' => $attempt->started_at,
                'submitted_at' => $attempt->submitted_at,
                'time_taken' => $attempt->time_taken,
                'status' => $attempt->status,
            ];
        }),
    ]);
}

    /**
     * Validate Quiz Submission
     */
protected function validatePayload(Request $request): array
{
    return $request->validate([
        'attempt_id' => ['required', 'integer'],

        'answers' => ['required', 'array', 'min:1'],

        'answers.*.question_id' => ['required', 'integer'],

        'answers.*.selected_answer' => ['nullable', 'string', 'max:255'],
    ]);
}

    /**
     * Format Quiz Result
     */
 protected function formatResult(QuizAttempt $attempt): array
{
    return [
        'attempt_id' => $attempt->id,
        'user_id' => $attempt->user_id,
        'quiz_type_id' => $attempt->quiz_type_id,

        'total_questions' => (int) $attempt->total_questions,
        'correct_answers' => (int) $attempt->correct_answers,
        'wrong_answers' => (int) $attempt->wrong_answers,
        'skipped_answers' => (int) $attempt->skipped_answers,

        'obtained_marks' => (float) $attempt->obtained_marks,
        'total_marks' => (float) $attempt->total_marks,
        'percentage' => (float) $attempt->percentage,

        'started_at' => $attempt->started_at,
        'submitted_at' => $attempt->submitted_at,
        'time_taken' => $attempt->time_taken,
        'status' => (int) $attempt->status,

        'created_at' => $attempt->created_at,
        'updated_at' => $attempt->updated_at,
    ];
}
}
