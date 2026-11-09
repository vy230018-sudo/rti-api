<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuizType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuizTypeController extends Controller
{
    public function adminIndex(Request $request): JsonResponse
    {
        $query = QuizType::query()->latest('id');

        if (! $request->boolean('include_deleted')) {
            $query->notDeleted();
        }

        if ($request->filled('status')) {
            $query->where('status', (int) $request->input('status'));
        }

        foreach (['subject', 'difficulty', 'test_type', 'group_key'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, (string) $request->input($filter));
            }
        }

        return response()->json([
            'quiz_types' => $query->get()->map(fn (QuizType $quizType) => $this->formatQuizType($quizType)),
        ]);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, false);

        $quizType = QuizType::query()->create([
            'group_key'   => $validated['group_key'],
            'title'       => $validated['title'],
            'subject'     => $validated['subject'],
            'difficulty'  => $validated['difficulty'],
            'test_type'   => $validated['test_type'],
            'time_in_min' => $validated['time_in_min'],
            'status'      => $validated['status'] ?? 1,
            'is_deleted'  => 1,
        ]);

        return response()->json([
            'message' => 'Quiz paper created successfully.',
            'quiz_type' => $this->formatQuizType($quizType->fresh()),
        ], 201);
    }

    public function adminShow(int $id): JsonResponse
    {
        $quizType = QuizType::query()->findOrFail($id);

        return response()->json([
            'quiz_type' => $this->formatQuizType($quizType),
        ]);
    }

    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $quizType = QuizType::query()->findOrFail($id);

        $validated = $this->validatePayload($request, true);

        $quizType->fill($validated);
        $quizType->save();

        return response()->json([
            'message' => 'Quiz paper updated successfully.',
            'quiz_type' => $this->formatQuizType($quizType->fresh()),
        ]);
    }

    public function adminDestroy(int $id): JsonResponse
    {
        $quizType = QuizType::query()->findOrFail($id);

        $quizType->forceFill([
            'is_deleted' => 0,
        ])->save();

        return response()->json([
            'message' => 'Quiz paper soft deleted successfully.',
        ]);
    }

    public function adminRestore(int $id): JsonResponse
    {
        $quizType = QuizType::query()->findOrFail($id);

        $quizType->forceFill([
            'is_deleted' => 1,
        ])->save();

        return response()->json([
            'message' => 'Quiz paper restored successfully.',
            'quiz_type' => $this->formatQuizType($quizType->fresh()),
        ]);
    }

    public function practice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:191'],
            'difficulty' => ['required', 'string', 'max:191'],
        ]);

        $quizTypes = QuizType::query()
            ->visible()
            // ->where('test_type', 'practice')
            ->where('subject', $validated['subject'])
            ->where('difficulty', $validated['difficulty'])
            ->latest('id')
            ->get();

        return response()->json([
            'quiz_types' => $quizTypes->map(fn (QuizType $quizType) => $this->formatQuizType($quizType)),
        ]);
    }

    public function training(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:191'],
        ]);

        $quizTypes = QuizType::query()
            ->visible()
            // ->where('test_type', 'training')
            ->where('subject', $validated['subject'])
            ->latest('id')
            ->get();

        return response()->json([
            'quiz_types' => $quizTypes->map(fn (QuizType $quizType) => $this->formatQuizType($quizType)),
        ]);
    }

    public function exam(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:191'],
        ]);

        $quizType = QuizType::query()
            ->visible()
            // ->where('test_type', 'exam')
            ->where('subject', $validated['subject'])
            ->latest('id')
            ->first();

        return response()->json([
            'quiz_type' => $quizType ? $this->formatQuizType($quizType) : null,
        ]);
    }

    protected function validatePayload(Request $request, bool $isUpdate): array
    {
        $required = $isUpdate ? 'nullable' : 'required';

        return $request->validate([
            'group_key'   => [$required, 'string', 'max:191'],
            'title'       => [$required, 'string', 'max:191'],
            'subject'     => [$required, 'string', 'max:191'],
            'difficulty'  => [$required, 'string', 'max:191'],
            'test_type'   => [$required],
            'time_in_min' => [$required, 'integer', 'min:1'],
            'status'      => ['nullable', Rule::in([0, 1, '0', '1'])],
            'is_deleted'  => ['nullable', Rule::in([0, 1, '0', '1'])],
        ]);
    }

    protected function formatQuizType(QuizType $quizType): array
    {
        return [
            'id' => $quizType->id,
            'group_key' => $quizType->group_key,
            'title' => $quizType->title,
            'subject' => $quizType->subject,
            'difficulty' => $quizType->difficulty,
            'test_type' => $quizType->test_type,
            'time_in_min' => (int) $quizType->time_in_min,
            'status' => (int) $quizType->status,
            'is_deleted' => (int) $quizType->is_deleted,
            'created_at' => $quizType->created_at,
            'updated_at' => $quizType->updated_at,
        ];
    }
}