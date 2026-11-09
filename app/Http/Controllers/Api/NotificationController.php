<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    /** GET /notifications?per_page=20&cursor=...&unread=1 */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->notifications()->latest()->orderByDesc('id');

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $perPage = min(max((int) $request->get('per_page', 20), 1), 50);
        $page = $query->cursorPaginate($perPage);

        return response()->json([
            'success' => true,
            'notifications' => $page->getCollection()->map(fn ($n) => $this->format($n))->values(),
            'unread_count' => $request->user()->unreadNotifications()->count(),
            'has_more' => $page->hasMorePages(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /** GET /notifications/unread-count */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /** POST /notifications/{id}/read */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->findOwn($request->user(), $id);
        $notification->markAsRead();

        return response()->json([
            'message' => 'Notification marked as read.',
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /** POST /notifications/read-all */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.', 'unread_count' => 0]);
    }

    /** DELETE /notifications/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->findOwn($request->user(), $id)->delete();

        return response()->json([
            'message' => 'Notification deleted.',
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    protected function findOwn(User $user, string $id): DatabaseNotification
    {
        return $user->notifications()->where('id', $id)->firstOrFail();
    }

    protected function format(DatabaseNotification $n): array
    {
        $d = $n->data;

        return [
            'id' => $n->id,
            'type' => $d['type'] ?? 'general',
            'title' => $d['title'] ?? '',
            'body' => $d['body'] ?? '',
            'data' => $d['data'] ?? (object) [],
            'is_read' => $n->read_at !== null,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
