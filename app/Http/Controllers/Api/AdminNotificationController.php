<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AdminBroadcastNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class AdminNotificationController extends Controller
{
    /**
     * POST /admin-rti/notifications/broadcast
     * { title, body, user_ids?: [1,2,3] }   user_ids na diya to sab active users ko jayegi.
     */
    public function broadcast(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:500'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer'],
            'data' => ['nullable', 'array'],
        ]);

        $query = User::query()
            ->where('is_admin', false)
            ->where('is_deleted', '1')
            ->where('status', '1');

        if (! empty($data['user_ids'])) {
            $query->whereIn('id', $data['user_ids']);
        }

        $count = 0;
        $query->chunkById(200, function ($users) use ($data, &$count) {
            Notification::send($users, new AdminBroadcastNotification($data['title'], $data['body'], $data['data'] ?? []));
            $count += $users->count();
        });

        return response()->json(['message' => 'Notification queued.', 'recipients' => $count]);
    }
}
