<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:news,user'],
            'status' => ['nullable', 'in:pending,reviewed,resolved,rejected'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $reports = Report::query()
            ->with(['reporter:id,firstname,lastname,email,profile_image', 'news.user:id,firstname,lastname', 'reportedUser:id,firstname,lastname,email,profile_image'])
            ->when($data['type'] ?? null, fn ($query, $type) => $query->where('reportable_type', $type))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);

        return response()->json([
            'reports' => $reports->getCollection()->map(fn (Report $report) => $this->format($report)),
            'pagination' => [
                'current_page' => $reports->currentPage(),
                'per_page' => $reports->perPage(),
                'total' => $reports->total(),
                'last_page' => $reports->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $report = Report::query()->with(['reporter', 'news.user', 'reportedUser'])->findOrFail($id);

        return response()->json(['report' => $this->format($report)]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:pending,reviewed,resolved,rejected']]);
        $report = Report::query()->findOrFail($id);
        $report->update($data);

        return response()->json(['message' => 'Report status updated successfully.', 'report' => $this->format($report->fresh(['reporter', 'news.user', 'reportedUser']))]);
    }

    protected function format(Report $report): array
    {
        $user = fn ($user) => $user ? ['id' => $user->id, 'firstname' => $user->firstname, 'lastname' => $user->lastname, 'email' => $user->email, 'profile_image' => $user->profile_image] : null;

        return [
            'id' => $report->id,
            'type' => $report->reportable_type,
            'reason' => $report->reason,
            'details' => $report->details,
            'status' => $report->status,
            'created_at' => $report->created_at,
            'updated_at' => $report->updated_at,
            'reporter' => $user($report->reporter),
            'news' => $report->reportable_type === Report::TYPE_NEWS && $report->news ? [
                'id' => $report->news->id,
                'tittle' => $report->news->tittle,
                'description' => $report->news->description,
                'user' => $user($report->news->user),
            ] : null,
            'reported_user' => $report->reportable_type === Report::TYPE_USER ? $user($report->reportedUser) : null,
        ];
    }
}
