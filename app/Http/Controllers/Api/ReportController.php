<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\News;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function reportNews(Request $request, int $newsId): JsonResponse
    {
        $news = News::query()->where('status', 1)->where('is_deleted', 1)->findOrFail($newsId);

        if ((int) $news->user_id === (int) $request->user()->id) {
            return response()->json(['message' => 'You cannot report your own post.'], 422);
        }

        return $this->store($request, Report::TYPE_NEWS, $news->id);
    }

    public function reportUser(Request $request, int $userId): JsonResponse
    {
        if ($userId === (int) $request->user()->id) {
            return response()->json(['message' => 'You cannot report your own account.'], 422);
        }

        User::query()->where('is_admin', false)->where('status', '1')->where('is_deleted', '1')->findOrFail($userId);

        return $this->store($request, Report::TYPE_USER, $userId);
    }

    protected function store(Request $request, string $type, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);

        $report = Report::query()->updateOrCreate([
            'reporter_id' => $request->user()->id,
            'reportable_type' => $type,
            'reportable_id' => $id,
        ], [
            'reason' => $data['reason'],
            'details' => $data['details'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => $report->wasRecentlyCreated ? 'Report submitted successfully.' : 'Your report has been updated.',
            'report_id' => $report->id,
        ], $report->wasRecentlyCreated ? 201 : 200);
    }
}
