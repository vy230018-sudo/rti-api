<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentHistoryController extends Controller
{
    public function __construct(protected PaymentHistoryService $history)
    {
    }

    /** GET /payment-history - logged-in user ki apni complete history (Roles + Ads + Ecom + Quiz). */
    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request, defaultStatus: 'all');
        $filters['user_id'] = $request->user()->id; // hamesha token se, request param se nahi

        return $this->respond($request, $filters);
    }

    /** GET /rti-admin/payment-history?user_id=&module=&status=&from=&to=&search= */
    public function adminIndex(Request $request): JsonResponse
    {
        $filters = $this->filters($request, defaultStatus: 'all');

        if ($request->filled('user_id')) {
            $filters['user_id'] = (int) $request->input('user_id');
        }

        return $this->respond($request, $filters);
    }

    /**
     * Admin: Ads / Ecom / Quiz subscription khareedne wale users.
     * GET .../purchasers            -> us module ke saare purchasers
     * GET .../{id}/purchasers       -> ek plan ke purchasers
     * Default sirf paid; status=all|created|failed se baaki dekh sakte hain. Latest purchase sabse upar.
     */
    public function purchasers(Request $request, string $module, ?string $id = null): JsonResponse
    {
        abort_unless(in_array($module, ['ads', 'ecom', 'quiz'], true), 404);

        $filters = $this->filters($request, defaultStatus: 'paid');
        $filters['module'] = $module;

        if ($id !== null) {
            $filters['plan_id'] = (int) $id;
        }

        if ($request->filled('user_id')) {
            $filters['user_id'] = (int) $request->input('user_id');
        }

        return $this->respond($request, $filters, withUniqueUsers: true);
    }

    protected function filters(Request $request, string $defaultStatus): array
    {
        $data = $request->validate([
            'module' => ['nullable', Rule::in(PaymentHistoryService::MODULES)],
            'type' => ['nullable', Rule::in(PaymentHistoryService::MODULES)], // module ka alias
            'status' => ['nullable', Rule::in(['all', 'created', 'paid', 'failed'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return [
            'module' => $data['module'] ?? $data['type'] ?? null,
            'status' => $data['status'] ?? $defaultStatus,
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'search' => $data['search'] ?? null,
        ];
    }

    protected function respond(Request $request, array $filters, bool $withUniqueUsers = false): JsonResponse
    {
        $perPage = $request->integer('per_page', 20);
        $query = $this->history->query($filters);
        $page = (clone $query)->paginate($perPage);

        $summary = [
            'total_records' => $page->total(),
            'paid_amount' => round((float) (clone $query)->reorder()->where('status', 'paid')->sum('amount'), 2),
        ];

        if ($withUniqueUsers) {
            $summary['unique_users'] = (clone $query)->reorder()->distinct()->count('user_id');
        }

        return response()->json([
            'data' => $this->history->rows(collect($page->items())),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'summary' => $summary,
        ]);
    }
}
