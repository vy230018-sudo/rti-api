<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\AppNotification;
use App\Notifications\ProfileReviewNotification;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->where('is_admin', false)
            ->latest('id');

        if (! $request->boolean('include_deleted')) {
            $query->where('is_deleted', '1');
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        return response()->json([
            'users' => $query->get()->map(fn (User $user) => $this->formatUser($user)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'firstname' => ['required', 'string', 'max:191'],
            'lastname' => ['nullable', 'string', 'max:191'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'status' => ['nullable', 'in:0,1'],
        ]);

        $user = User::query()->create([
            'firstname' => $validated['firstname'],
            'lastname' => $validated['lastname'] ?? null,
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'bio' => $validated['bio'] ?? null,
            'password' => $validated['password'],
            'is_admin' => false,
            'status' => $validated['status'] ?? '1',
            'is_deleted' => '1',
        ]);

        return response()->json([
            'message' => 'User created successfully.',
            'user' => $this->formatUser($user->fresh()),
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        abort_if($user->is_admin, 404);

        // Detail endpoint only: keep the admin users list response unchanged.
        $subscriptions = UserSubscription::query()
            ->with('plan')
            ->where('user_id', (string) $user->id)
            ->where('is_deleted', 1)
            ->latest('id')
            ->get()
            ->map(fn (UserSubscription $subscription) => [
                'id' => $subscription->id,
                'plan_id' => $subscription->plan_id,
                'plan_name' => $subscription->plan?->title,
                'plan_type' => $subscription->plan?->plan_type,
                'role_id' => $subscription->plan?->role_id,
                'price' => $subscription->price,
                'start_date' => $subscription->start_date?->toDateString(),
                'end_date' => $subscription->end_date?->toDateString(),
                'payment_status' => (int) $subscription->payment_status,
                'status' => (int) $subscription->status,
                'is_active' => $subscription->active()->whereKey($subscription->id)->exists(),
                'created_at' => $subscription->created_at,
            ])
            ->values();

        return response()->json([
            'user' => $this->formatUser($user),
            'role_subscriptions' => $subscriptions,
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_if($user->is_admin, 404);

        $validated = $request->validate([
            'firstname' => ['nullable', 'string', 'max:191'],
            'lastname' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:8'],
            'status' => ['nullable', 'in:0,1'],
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->fill($validated);
        $user->save();

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => $this->formatUser($user->fresh()),
        ]);
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        abort_if($user->is_admin, 404);

        $validated = $request->validate([
            'status' => ['required', 'in:0,1'],
        ]);

        $user->forceFill([
            'status' => $validated['status'],
        ])->save();

        return response()->json([
            'message' => 'User status updated successfully.',
            'user' => $this->formatUser($user->fresh()),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        abort_if($user->is_admin, 404);

        $user->forceFill([
            'is_deleted' => '0',
        ])->save();

        return response()->json([
            'message' => 'User soft deleted successfully.',
        ]);
    }

    public function pendingProfiles(Request $request): JsonResponse
    {
        $query = User::query()
            ->where('is_admin', false)
            ->where('profile_update_status', 1)
            ->latest('id');

        // Admin can either see every pending profile update or inspect one user.
        if ($request->filled('user_id')) {
            // Profile-update requests are stored on the users row, whose key is `id`.
            $query->where('id', (string) $request->user_id);
        }

        return response()->json([
            'users' => $query->get()->map(fn (User $user) => $this->formatUser($user)),
        ]);
    }

    public function approveProfile(User $user): JsonResponse
    {
        abort_if($user->is_admin, 404);

        if (! $this->hasPendingProfileUpdate($user)) {
            return response()->json([
                'message' => 'No pending profile update found for this user.',
                'code' => 'no_pending_profile_update',
            ], 422);
        }

        $updateData = [
            'profile_update_status' => 0,
            'pending_phone' => null,
            'pending_bio' => null,
            'pending_profile_image' => null,
        ];
        
        if ($user->pending_phone !== null) {
            $updateData['phone'] = $user->pending_phone;
        }
        if ($user->pending_bio !== null) {
            $updateData['bio'] = $user->pending_bio;
        }
        if ($user->pending_profile_image !== null) {
            if ($user->profile_image) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($user->profile_image);
            }
            $updateData['profile_image'] = $user->pending_profile_image;
        }
        
        $user->forceFill($updateData)->save();

        AppNotification::dispatchSystem($user->id, new ProfileReviewNotification(true));
        
        return response()->json([
            'message' => 'User profile update approved successfully.',
            'approved' => true,
            'user' => $this->formatUser($user->fresh()),
        ]);
    }

    public function rejectProfile(User $user): JsonResponse
    {
        abort_if($user->is_admin, 404);

        if (! $this->hasPendingProfileUpdate($user)) {
            return response()->json([
                'message' => 'No pending profile update found for this user.',
                'code' => 'no_pending_profile_update',
            ], 422);
        }

        if ($user->pending_profile_image !== null) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($user->pending_profile_image);
        }

        $user->forceFill([
            'profile_update_status' => 2,
            'pending_phone' => null,
            'pending_bio' => null,
            'pending_profile_image' => null,
        ])->save();
        
        AppNotification::dispatchSystem($user->id, new ProfileReviewNotification(false));

        return response()->json([
            'message' => 'User profile update rejected.',
            'user' => $this->formatUser($user->fresh()),
        ]);
    }

    /** Profile update tab pending maana jayega jab status 1 ho ya koi pending_* value bachi ho. */
    protected function hasPendingProfileUpdate(User $user): bool
    {
        return (int) $user->profile_update_status === 1
            || $user->pending_phone !== null
            || $user->pending_bio !== null
            || $user->pending_profile_image !== null;
    }

    protected function formatUser(User $user): array
    {
        $state = $user->states()->latest('id')->first();

        return [
            'id' => $user->id,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'email' => $user->email,
            'phone' => $user->phone,
            'pending_phone' => $user->pending_phone,
            'bio' => $user->bio,
            'pending_bio' => $user->pending_bio,
            'profile_update_status' => $user->profile_update_status,
            'profile_image' => $user->profile_image,
            'profile_image_url' => $user->profile_image ? \Illuminate\Support\Facades\Storage::disk('public')->url($user->profile_image) : null,
            'pending_profile_image' => $user->pending_profile_image,
            'pending_profile_image_url' => $user->pending_profile_image ? \Illuminate\Support\Facades\Storage::disk('public')->url($user->pending_profile_image) : null,
            'referral_code' => $user->referral_code,
            'status' => $user->status,
            'is_deleted' => $user->is_deleted,
            'state' => $state?->state,
            'district' => $state?->district,
            'taluka' => $state?->taluka,
            'created_at' => $user->created_at,
        ];
    }
}
