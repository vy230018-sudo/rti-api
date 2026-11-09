<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuestionAns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuestionAnsController extends Controller
{
    /**
     * Admin - List Questions
     */
    public function adminIndex(Request $request): JsonResponse{
        $query = QuestionAns::query()->latest('id');

        if (! $request->boolean('include_deleted')) {
            $query->notDeleted();
        }

        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }

        if ($request->filled('quiz_type_id')) {
            $query->where('quiz_type_id', (int) $request->input('quiz_type_id'));
        }

        if ($request->filled('group_key')) {
            $query->where('group_key', $request->input('group_key'));
        }

        return response()->json([
            'questions' => $query->get()->map(fn (QuestionAns $question) => $this->formatQuestion($question)),
        ]);
    }

    /**
     * Admin - Store Multiple Questions
     */
    public function adminStore(Request $request): JsonResponse{
        $validated = $request->validate([
            'quiz_type_id' => ['required', 'integer'],
            'group_key' => ['required', 'string', 'max:255'],
            'questions' => ['required', 'array', 'min:1'],

            'questions.*.question' => ['required', 'string'],
            'questions.*.option' => ['required', 'array', 'min:2'],
            'questions.*.correct_ans' => ['required', 'string'],
            'questions.*.explation' => ['nullable', 'string'],
            'questions.*.marks' => ['required', 'numeric'],
        ]);

        $insertedQuestions = [];

        foreach ($validated['questions'] as $item) {

            $question = QuestionAns::create([
                'quiz_type_id' => $validated['quiz_type_id'],
                'group_key' => $validated['group_key'],
                'question' => $item['question'],
                'option' => $item['option'], // Model cast array hai
                'correct_ans' => $item['correct_ans'],
                'explation' => $item['explation'] ?? null,
                'marks' => $item['marks'],
                'status' => 1,
                'is_deleted' => 1,
            ]);

            $insertedQuestions[] = $this->formatQuestion($question);
        }

        return response()->json([
            'message' => count($insertedQuestions) . ' Questions added successfully.',
            'questions' => $insertedQuestions,
        ], 201);
    }
    /**
     * Admin - Show Questions by Quiz Type
     */
public function adminShow(int $quizTypeId): JsonResponse
{
    $questions = QuestionAns::query()
        ->notDeleted()
        ->where('quiz_type_id', $quizTypeId)
        ->latest('id')
        ->get();

    return response()->json([
        'questions' => $questions->map(fn (QuestionAns $question) => $this->formatQuestion($question)),
    ]);
}

    /**
     * Admin - Update Multiple Questions
     */
public function adminUpdate(Request $request, int $quizTypeId): JsonResponse
{
    $validated = $request->validate([
        'questions' => ['required', 'array', 'min:1'],

        'questions.*.id' => ['required', 'integer'],
        'questions.*.question' => ['required', 'string'],
        'questions.*.option' => ['required', 'array', 'min:2'],
        'questions.*.correct_ans' => ['required', 'string'],
        'questions.*.explation' => ['nullable', 'string'],
        'questions.*.marks' => ['required', 'numeric'],
        'questions.*.status' => ['nullable', 'in:0,1'],
    ]);

    $updatedQuestions = [];

    foreach ($validated['questions'] as $item) {

        $question = QuestionAns::query()
            ->where('quiz_type_id', $quizTypeId)
            ->findOrFail($item['id']);

        $question->update([
            'question' => $item['question'],
            'option' => $item['option'],
            'correct_ans' => $item['correct_ans'],
            'explation' => $item['explation'] ?? null,
            'marks' => $item['marks'],
            'status' => $item['status'] ?? $question->status,
        ]);

        $updatedQuestions[] = $this->formatQuestion($question->fresh());
    }

    return response()->json([
        'message' => count($updatedQuestions) . ' Questions updated successfully.',
        'questions' => $updatedQuestions,
    ]);
}

    /**
     * Admin - Soft Delete Question
     */
    public function adminDestroy(int $id): JsonResponse{
        $question = QuestionAns::query()->findOrFail($id);

        $question->forceFill([
            'is_deleted' => 0,
        ])->save();

        return response()->json([
            'message' => 'Question soft deleted successfully.',
        ]);
    }

    /**
     * Admin - Restore Question
     */
    public function adminRestore(int $id): JsonResponse{
        $question = QuestionAns::query()->findOrFail($id);

        $question->forceFill([
            'is_deleted' => 1,
        ])->save();

        return response()->json([
            'message' => 'Question restored successfully.',
            'question' => $this->formatQuestion($question->fresh()),
        ]);
    }

    /**
     * Validate Request
     */
    protected function validatePayload(Request $request, bool $isUpdate): array
    {
        $required = $isUpdate ? 'nullable' : 'required';

        return $request->validate([
            'quiz_type_id' => [$required, 'integer'],
            'group_key' => [$required, 'string', 'max:255'],

            'questions' => [$required, 'array', 'min:1'],

            'questions.*.id' => ['nullable', 'integer'],
            'questions.*.question' => ['required', 'string'],
            'questions.*.option' => ['required', 'array', 'min:2'],
            'questions.*.correct_ans' => ['required', 'string'],
            'questions.*.explation' => ['nullable', 'string'],
            'questions.*.marks' => ['required', 'numeric'],

            'status' => ['nullable', Rule::in([0, 1, '0', '1'])],
            'is_deleted' => ['nullable', Rule::in([0, 1, '0', '1'])],
        ]);
    }

    /**
     * Format Response
     */
    protected function formatQuestion(QuestionAns $question): array{
        return [
            'id' => $question->id,
            'quiz_type_id' => $question->quiz_type_id,
            'group_key' => $question->group_key,
            'question' => $question->question,
            'option' => $question->option,
            'correct_ans' => $question->correct_ans,
            'explation' => $question->explation,
            'marks' => $question->marks,
            'status' => (int) $question->status,
            'is_deleted' => (int) $question->is_deleted,
            'created_at' => $question->created_at,
            'updated_at' => $question->updated_at,
        ];
    }
}