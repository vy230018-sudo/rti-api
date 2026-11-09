<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function __construct(protected JwtService $jwtService)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->where('email', $validated['email'])
            ->where('is_admin', true)
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided admin credentials are incorrect.'],
            ]);
        }

        return response()->json([
            'message' => 'Admin login successful.',
            'user' => $user,
            'authorization' => $this->jwtService->issueToken($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = (string) $request->attributes->get('jwt_token');
        $this->jwtService->invalidate($token);

        return response()->json([
            'message' => 'Admin logout successful.',
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $recentUsers = User::query()
            ->where('is_admin', false)
            ->where('is_deleted', '1')
            ->where('created_at', '>=', now()->subDays(2))
            ->latest('id')
            ->get();

        return response()->json([
            'message' => 'Admin API is authenticated.',
            'user' => $request->user(),
            'recent_users' => $this->formatUsers($recentUsers),
            'recent_users_date_from' => now()->subDays(2)->toDateTimeString(),
        ]);
    }

    protected function formatUsers(Collection $users): array
    {
        return $users->map(function (User $user): array {
            $state = $user->states()->latest('id')->first();

            return [
                'id' => $user->id,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'email' => $user->email,
                'phone' => $user->phone,
                'bio' => $user->bio,
                'profile_image' => $user->profile_image,
                'referral_code' => $user->referral_code,
                'status' => $user->status,
                'state' => $state?->state,
                'district' => $state?->district,
                'taluka' => $state?->taluka,
                'created_at' => $user->created_at,
            ];
        })->all();
    }
}
