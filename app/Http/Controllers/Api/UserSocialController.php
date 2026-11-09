<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\AppNotification;
use App\Notifications\NewFollowerNotification;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\UserFollow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSocialController extends Controller
{
    public function follow(Request $request): JsonResponse
    {
        $target = $this->targetUser($request);
        $user = $request->user();

        if (UserBlock::query()->where(function ($query) use ($user, $target) {
            $query->where('blocker_id', $user->id)->where('blocked_id', $target->id);
        })->orWhere(function ($query) use ($user, $target) {
            $query->where('blocker_id', $target->id)->where('blocked_id', $user->id);
        })->exists()) {
            return response()->json(['message' => 'You cannot follow this user because one of you has blocked the other.'], 422);
        }

        $follow = UserFollow::query()->firstOrCreate([
            'follower_id' => $user->id,
            'following_id' => $target->id,
        ]);

        if ($follow->wasRecentlyCreated) {
            AppNotification::dispatchTo($target->id, $user->id, new NewFollowerNotification(
                AppNotification::nameOf($user),
                (int) $user->id,
            ));
        }

        return response()->json(['message' => 'User followed successfully.']);
    }

    public function unfollow(Request $request): JsonResponse
    {
        $target = $this->targetUser($request);

        UserFollow::query()
            ->where('follower_id', $request->user()->id)
            ->where('following_id', $target->id)
            ->delete();

        return response()->json(['message' => 'User unfollowed successfully.']);
    }

    public function followers(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'followers' => UserFollow::query()->with('follower')
                ->where('following_id', $user->id)->latest('id')->get()
                ->map(fn (UserFollow $follow) => $this->formatUser($follow->follower)),
        ]);
    }

    public function following(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'following' => UserFollow::query()->with('following')
                ->where('follower_id', $user->id)->latest('id')->get()
                ->map(fn (UserFollow $follow) => $this->formatUser($follow->following)),
        ]);
    }

    public function block(Request $request): JsonResponse
    {
        $target = $this->targetUser($request);
        $user = $request->user();

        UserBlock::query()->firstOrCreate([
            'blocker_id' => $user->id,
            'blocked_id' => $target->id,
        ]);

        UserFollow::query()->where(function ($query) use ($user, $target) {
            $query->where('follower_id', $user->id)->where('following_id', $target->id);
        })->orWhere(function ($query) use ($user, $target) {
            $query->where('follower_id', $target->id)->where('following_id', $user->id);
        })->delete();

        return response()->json(['message' => 'User blocked successfully.']);
    }

    public function unblock(Request $request): JsonResponse
    {
        $target = $this->targetUser($request);

        UserBlock::query()
            ->where('blocker_id', $request->user()->id)
            ->where('blocked_id', $target->id)
            ->delete();

        return response()->json(['message' => 'User unblocked successfully.']);
    }

    public function blocked(Request $request): JsonResponse
    {
        return response()->json([
            'blocked_users' => UserBlock::query()->with('blocked')
                ->where('blocker_id', $request->user()->id)->latest('id')->get()
                ->map(fn (UserBlock $block) => $this->formatUser($block->blocked)),
        ]);
    }

    protected function targetUser(Request $request): User
    {
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $user = $request->user();

        if ((int) $data['user_id'] === (int) $user->id) {
            abort(422, 'You cannot perform this action on your own account.');
        }

        return User::query()->where('is_admin', false)->where('status', '1')->where('is_deleted', '1')
            ->findOrFail($data['user_id']);
    }

    protected function formatUser(?User $user): ?array
    {
        if (! $user) return null;

        return [
            'id' => $user->id,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'profile_image' => $user->profile_image,
            'bio' => $user->bio,
        ];
    }
}
