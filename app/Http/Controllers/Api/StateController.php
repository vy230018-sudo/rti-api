<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\State;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StateController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'state' => ['required', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:255'],
            'taluka' => ['required', 'string', 'max:255'],
            'village' => ['required', 'string', 'max:255'],
            'state_id' => ['nullable', 'integer', 'min:1'],
            'district_id' => ['nullable', 'integer', 'min:1'],
            'taluka_id' => ['nullable', 'integer', 'min:1'],
            'village_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();

        if ($user->is_admin) {
            return response()->json([
                'message' => 'Only normal users can manage state details from this endpoint.',
            ], 403);
        }

        $state = State::query()->updateOrCreate(
            ['user_id' => (string) $user->getKey()],
            [
                'state' => $validated['state'],
                'district' => $validated['district'] ?? null,
                'taluka' => $validated['taluka'] ?? null,
                'village' => $validated['village'],
                'state_id' => $validated['state_id'] ?? null,
                'district_id' => $validated['district_id'] ?? null,
                'taluka_id' => $validated['taluka_id'] ?? null,
                'village_id' => $validated['village_id'] ?? null,
                'status' => $validated['status'] ?? '1',
                'id_deleted' => '1',
            ]
        );

        $userstate = [
            'state' => $state->state,
            'district' => $state->district,
            'taluka' => $state->taluka,
            'village' => $state->village,
            'state_id' => $state->state_id,
            'district_id' => $state->district_id,
            'taluka_id' => $state->taluka_id,
            'village_id' => $state->village_id,
            'user_id' => $state->user_id,
            'id' => $state->id,

        ];

        return response()->json([
            'message' => 'State details saved successfully.',
            'state' => $userstate,
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->is_admin) {
            return response()->json([
                'message' => 'Only normal users can access state details from this endpoint.',
            ], 403);
        }

        $state = State::query()
            ->where('user_id', (string) $user->getKey())
            ->latest('id')
            ->first();

        return response()->json([
            'state' => $state,
        ]);
    }
}
