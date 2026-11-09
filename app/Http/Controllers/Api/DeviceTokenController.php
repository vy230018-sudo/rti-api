<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceTokenController extends Controller
{
    /**
     * POST /device-tokens  { token, platform: android|ios|web, device_name? }
     * Same token dobara aaye to sirf owner/update hota hai (device par user badal jaye to bhi sahi).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', Rule::in(['android', 'ios', 'web'])],
            'device_name' => ['nullable', 'string', 'max:191'],
        ]);

        DeviceToken::query()->updateOrCreate(
            ['token_hash' => DeviceToken::hashFor($data['token'])],
            [
                'user_id' => $request->user()->id,
                'token' => $data['token'],
                'platform' => $data['platform'],
                'device_name' => $data['device_name'] ?? null,
                'last_used_at' => now(),
            ],
        );

        return response()->json(['message' => 'Device registered.']);
    }

    /** DELETE /device-tokens  { token }  -> logout se pehle call karein */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);

        DeviceToken::query()
            ->where('user_id', $request->user()->id)
            ->where('token_hash', DeviceToken::hashFor($data['token']))
            ->delete();

        return response()->json(['message' => 'Device removed.']);
    }
}
