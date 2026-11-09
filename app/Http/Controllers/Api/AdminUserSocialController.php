<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserBlock;
use App\Models\UserFollow;
use Illuminate\Http\JsonResponse;

class AdminUserSocialController extends Controller
{
    public function follows(): JsonResponse
    {
        return response()->json(['follows' => UserFollow::query()->with(['follower', 'following'])->latest('id')->get()->map(fn (UserFollow $follow) => [
            'id' => $follow->id,
            'follower' => $this->user($follow->follower),
            'following' => $this->user($follow->following),
            'created_at' => $follow->created_at,
        ])]);
    }

    public function blocks(): JsonResponse
    {
        return response()->json(['blocks' => UserBlock::query()->with(['blocker', 'blocked'])->latest('id')->get()->map(fn (UserBlock $block) => [
            'id' => $block->id,
            'blocker' => $this->user($block->blocker),
            'blocked' => $this->user($block->blocked),
            'created_at' => $block->created_at,
        ])]);
    }

    public function destroyFollow(int $id): JsonResponse
    {
        UserFollow::query()->findOrFail($id)->delete();

        return response()->json(['message' => 'Follow relationship removed successfully.']);
    }

    public function destroyBlock(int $id): JsonResponse
    {
        UserBlock::query()->findOrFail($id)->delete();

        return response()->json(['message' => 'Block relationship removed successfully.']);
    }

    protected function user($user): ?array
    {
        return $user ? ['id' => $user->id, 'firstname' => $user->firstname, 'lastname' => $user->lastname, 'email' => $user->email, 'profile_image' => $user->profile_image] : null;
    }
}
