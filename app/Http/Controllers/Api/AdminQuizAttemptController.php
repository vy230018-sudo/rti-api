<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminQuizAttemptController extends Controller
{
    /**
     * GET /rti-admin/quiz-attempts - sabhi users ke attempts.
     * Filters (optional): user_id, quiz_type_id, status (0 = in progress, 1 = completed), from, to, search, per_page.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->listing($request, $request->filled('user_id') ? (int) $request->input('user_id') : null);
    }

    /** GET /rti-admin/users/{userId}/quiz-attempts - sirf selected user ke attempts. */
    public function byUser(Request $request, int $userId): JsonResponse
    {
        abort_unless(User::query()->whereKey($userId)->exists(), 404);

        return $this->listing($request, $userId);
    }

    /** DELETE /rti-admin/quiz-attempts/{id} - soft delete (is_deleted = 0). */
    public function destroy(int $id): JsonResponse
    {
        $attempt = QuizAttempt::notDeleted()->findOrFail($id);

        $attempt->forceFill(['is_deleted' => 0])->save();

        return response()->json(['message' => 'Quiz attempt deleted successfully.']);
    }

    protected function listing(Request $request, ?int $userId): JsonResponse
    {
        $request->validate([
            'user_id' => ['nullable', 'integer'],
            'quiz_type_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in([0, 1, '0', '1'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = QuizAttempt::notDeleted()->with(['user', 'quizType'])->latest('id');

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        if ($request->filled('quiz_type_id')) {
            $query->where('quiz_type_id', (int) $request->input('quiz_type_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        if ($request->filled('search')) {
            $like = '%'.addcslashes((string) $request->input('search'), '\\%_').'%';

            $query->where(function (Builder $q) use ($like): void {
                $q->whereHas('user', function (Builder $user) use ($like): void {
                    $user->where('firstname', 'like', $like)
                        ->orWhere('lastname', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like);
                })->orWhereHas('quizType', fn (Builder $quiz) => $quiz->where('title', 'like', $like));
            });
        }

        $attempts = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => collect($attempts->items())->map(fn (QuizAttempt $attempt) => $this->format($attempt))->values(),
            'meta' => [
                'current_page' => $attempts->currentPage(),
                'last_page' => $attempts->lastPage(),
                'per_page' => $attempts->perPage(),
                'total' => $attempts->total(),
            ],
        ]);
    }

    protected function format(QuizAttempt $attempt): array
    {
        $user = $attempt->user;
        $quiz = $attempt->quizType;
        $seconds = $attempt->time_taken;

        return [
            'attempt_id' => $attempt->id,
            'user_id' => $attempt->user_id,
            'user_name' => $user ? trim($user->firstname.' '.$user->lastname) : null,
            'user_email' => $user?->email,
            'user_mobile' => $user?->phone,
            'quiz_id' => $attempt->quiz_type_id,
            'quiz_title' => $quiz?->title,
            'quiz_type' => match ($quiz?->test_type) {
                1 => 'practice',
                2 => 'training',
                3 => 'exam',
                default => null,
            },
            'attempt_date' => $attempt->started_at ?? $attempt->created_at,
            'submitted_at' => $attempt->submitted_at,
            'total_questions' => (int) $attempt->total_questions,
            'attempted_questions' => (int) $attempt->correct_answers + (int) $attempt->wrong_answers,
            'correct_answers' => (int) $attempt->correct_answers,
            'wrong_answers' => (int) $attempt->wrong_answers,
            'skipped_answers' => (int) $attempt->skipped_answers,
            'total_marks' => (float) $attempt->total_marks,
            'obtained_marks' => (float) $attempt->obtained_marks,
            'percentage' => (float) $attempt->percentage,
            'time_taken' => $seconds, // seconds
            'time_taken_formatted' => $seconds !== null ? sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60) : null,
            'status' => (int) $attempt->status,
            'status_label' => (int) $attempt->status === 1 ? 'completed' : 'in_progress',
            // Quiz me pass mark ka koi field nahi hai, isliye abhi null. Pass mark add hone par yahan calculate hoga.
            'pass_status' => null,
        ];
    }
}
