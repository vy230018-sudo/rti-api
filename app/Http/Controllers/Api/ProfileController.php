<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IdCard;
use App\Models\News;
use App\Models\Seat;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'profile' => $this->formatProfile($request->user()),
        ]);
    }

    public function showPublic(int $id): JsonResponse
    {
        $user = User::query()
            ->where('is_admin', false)
            ->where('status', '1')
            ->where('is_deleted', '1')
            ->findOrFail($id);

        $roleSubscription = $this->activeSubscription($user);
        $seat = Seat::query()
            ->where('user_id', (string) $user->id)
            ->where('status', 1)
            ->where('is_deleted', 1)
            ->when($roleSubscription, fn ($query) => $query->where('user_subscription_id', $roleSubscription->id))
            ->latest('id')
            ->first();
        $idCard = $roleSubscription
            ? IdCard::query()
                ->where('user_id', (string) $user->id)
                ->where('user_subscription_id', $roleSubscription->id)
                ->latest('id')
                ->first()
            : null;

        // Keep public contact visibility in sync with the approved/pending profile
        // value used by the private profile endpoint.
        $showPendingProfileData = (int) $user->profile_update_status === 0;
        $phone = $showPendingProfileData && $user->pending_phone !== null
            ? $user->pending_phone
            : $user->phone;

        $posts = News::query()
            ->where('user_id', (string) $user->id)
            ->where('status', '1')
            ->where('is_deleted', 1)
            ->latest('created_at')
            ->get()
            ->map(fn (News $news) => [
                'id' => $news->id,
                'type' => (int) ($news->type ?? News::TYPE_NEWS), // 1 = news, 2 = feeds (reels)
                'tittle' => $news->tittle,
                'sub_tittle' => $news->sub_tittle,
                'description' => $news->description,
                'report_type' => $news->report_type,
                'media_type' => $news->media_type,
                'media' => $news->media_type == 4 ? $news->media : ($news->media ? asset('storage/'.$news->media) : null),
                'images' => (int) $news->media_type === 1
                    ? collect(is_array($news->images) && $news->images ? $news->images : array_filter([$news->media]))
                        ->map(fn ($path) => filter_var($path, FILTER_VALIDATE_URL) ? $path : asset('storage/'.ltrim($path, '/')))
                        ->values()
                        ->all()
                    : [],
                'use_frame' => (bool) $news->use_frame,
                'frame_template' => $news->frame_template,
                'frame_merged' => (bool) $news->frame_merged,
                'music_id' => $news->music_id,
                'music_url' => $news->music_url,
                'music_volume' => $news->music_volume,
                'voice_url' => $news->voice ? asset('storage/'.ltrim($news->voice, '/')) : null,
                'mute_original' => (bool) $news->mute_original,
                'created_at' => $news->created_at,
                'updated_at' => $news->updated_at,
            ]);

        return response()->json([
            'profile' => [
                'id' => $user->id,
                'name' => trim(implode(' ', array_filter([$user->firstname, $user->lastname]))),
                'avatar' => $user->profile_image ? asset('storage/' . $user->profile_image) : null,
                'profile_image' => $user->profile_image,
                'profile_image_change_count' => (int) $user->profile_image_change_count,
                'has_blue_tick' => (bool) $roleSubscription?->plan?->isPremium(),
                'is_premium' => (bool) $roleSubscription?->plan?->isPremium(),
                'is_subscribed' => $roleSubscription !== null,
                'role' => $roleSubscription?->plan?->role_id,
                'role_label' => $roleSubscription?->plan?->isPremium() ? 'Premium' : $roleSubscription?->plan?->title,
                'state_seat' => [
                    'seat_name' => $seat?->state_seat,
                    'seat_id' => $seat?->role_id,
                    'state' => $seat?->state,
                    'district' => $seat?->district,
                    'taluka' => $seat?->taluka,
                ],
                // Active subscription holder ka contact aur ID card public profile par dikhte hain.
                'phone' => $roleSubscription ? $phone : null,
                'id_card' => $idCard ? [
                    'id_number' => $idCard->id_number,
                    'valid_until' => $roleSubscription->end_date?->toDateString(),
                ] : null,
                'subscription_plan' => $this->formatSubscriptionPlan($roleSubscription),
                'bio' => $user->bio,
                'followers_count' => $user->followers()->count(),
                'following_count' => $user->following()->count(),
                'posts_count' => $posts->count(),
                'posts' => $posts,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'firstname' => ['nullable', 'string', 'max:191'],
            'lastname' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();

        $updateData = [];
        if (array_key_exists('firstname', $validated)) {
            $updateData['firstname'] = $validated['firstname'];
        }
        if (array_key_exists('lastname', $validated)) {
            $updateData['lastname'] = $validated['lastname'];
        }

        $pendingApproval = false;
        
        if (array_key_exists('phone', $validated) && $validated['phone'] !== $user->phone) {
            $updateData['pending_phone'] = $validated['phone'];
            $pendingApproval = true;
        }
        if (array_key_exists('bio', $validated) && $validated['bio'] !== $user->bio) {
            $updateData['pending_bio'] = $validated['bio'];
            $pendingApproval = true;
        }

        if ($pendingApproval) {
            $updateData['profile_update_status'] = 1;
        }

        $user->fill($updateData);
        $user->save();

        $message = $pendingApproval 
            ? 'Profile details updated. Contact or bio changes are pending admin approval.'
            : 'Profile details saved successfully.';

        return response()->json([
            'message' => $message,
            'profile' => $this->formatProfile($user->fresh()),
        ], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'profile_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();

        $hasEligibleSubscription = UserSubscription::query()
            ->active()
            ->where('user_id', (string) $user->id)
            ->whereHas('plan')
            ->exists();

        if (! $hasEligibleSubscription && (int) $user->profile_image_change_count >= 1) {
            return response()->json([
                'code' => 'photo_change_limit',
                'message' => 'Free photo change already used. Take a Role or Premium subscription.',
            ], 403);
        }

        // Symlink (public/storage) par depend na karke, seedha public folder mein save karein.
        // Isse "storage/app/public -> public/storage" symlink broken hone par bhi image serve hoti rahegi.
        $destinationDir = public_path('storage/profile-images');
        if (! is_dir($destinationDir)) {
            mkdir($destinationDir, 0755, true);
        }

        $file = $validated['profile_image'];
        $filename = Str::random(40) . '.' . $file->getClientOriginalExtension();
        $file->move($destinationDir, $filename);

        $path = 'profile-images/' . $filename;

        $user->forceFill([
            'pending_profile_image' => $path,
            'profile_update_status' => 1,
            'profile_image_change_count' => (int) $user->profile_image_change_count + 1,
        ])->save();

        return response()->json([
            'message' => 'Profile image uploaded. Pending admin approval.',
            'profile' => $this->formatProfile($user->fresh()),
        ]);
    }

    protected function formatProfile(User $user): array
    {
        $state = $user->states()->latest('id')->first();
        $roleSubscription = $this->activeSubscription($user);
        $showPendingProfileData = (int) $user->profile_update_status === 0;

        $phone = $showPendingProfileData && $user->pending_phone !== null
            ? $user->pending_phone
            : $user->phone;
        $bio = $showPendingProfileData && $user->pending_bio !== null
            ? $user->pending_bio
            : $user->bio;
        $profileImage = $showPendingProfileData && $user->pending_profile_image !== null
            ? $user->pending_profile_image
            : $user->profile_image;

        return [
            'id' => $user->id,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'email' => $user->email,
            'phone' => $phone,
            'pending_phone' => $user->pending_phone,
            'bio' => $bio,
            'pending_bio' => $user->pending_bio,
            'profile_update_status' => $user->profile_update_status,
            'profile_image_change_count' => (int) $user->profile_image_change_count,
            'profile_image' => $profileImage,
            'profile_image_url' => $profileImage ? asset('storage/' . $profileImage) : null,
            'pending_profile_image' => $user->pending_profile_image,
            'pending_profile_image_url' => $user->pending_profile_image ? asset('storage/' . $user->pending_profile_image) : null,
            'referral_code' => $user->referralCode(),
            'has_blue_tick' => (bool) $roleSubscription?->plan?->isPremium(),
            'is_premium' => (bool) $roleSubscription?->plan?->isPremium(),
            'is_subscribed' => $roleSubscription !== null,
            'referral_eligible' => $roleSubscription !== null,
            'role' => $roleSubscription?->plan?->role_id,
            'role_label' => $roleSubscription?->plan?->isPremium() ? 'Premium' : $roleSubscription?->plan?->title,
            'subscription_plan' => $this->formatSubscriptionPlan($roleSubscription),
            'state' => $state?->state,
            'district' => $state?->district,
            'taluka' => $state?->taluka,
            'status' => $user->status,
        ];
    }

    /** Prefer an active Premium plan so blue-tick metadata is consistent everywhere. */
    protected function activeSubscription(User $user): ?UserSubscription
    {
        $subscriptions = UserSubscription::query()
            ->with('plan')
            ->active()
            ->where('user_id', (string) $user->id)
            ->latest('id')
            ->get();

        return $subscriptions->first(fn (UserSubscription $subscription): bool => (bool) $subscription->plan?->isPremium())
            ?? $subscriptions->first();
    }

    protected function formatSubscriptionPlan(?UserSubscription $subscription): ?array
    {
        if (! $subscription) {
            return null;
        }

        $isPremium = (bool) $subscription->plan?->isPremium();

        return [
            'plan_id' => (int) $subscription->plan_id,
            'plan_name' => $subscription->plan?->title,
            'role_names' => $subscription->plan?->role_id,
            'is_premium' => $isPremium,
            'status' => 'active',
            'purchased_at' => $subscription->created_at,
            'expires_at' => $subscription->end_date?->toDateString(),
        ];
    }
}
