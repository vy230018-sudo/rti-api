<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReferralCommissionRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReferralCommissionRateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['commission_rates' => ReferralCommissionRate::query()->orderBy('level')->get()]);
    }

    public function update(Request $request, int $level): JsonResponse
    {
        $data = $request->validate([
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'status' => ['sometimes', Rule::in([0, 1, '0', '1'])],
        ]);

        $rate = ReferralCommissionRate::query()->firstOrNew(['level' => $level]);
        abort_unless($level >= 1 && $level <= 5, 422, 'Only levels 1 to 5 are supported.');
        $rate->fill($data);
        $rate->status = array_key_exists('status', $data) ? (bool) $data['status'] : ($rate->exists ? $rate->status : true);
        $rate->save();

        return response()->json(['message' => 'Referral commission rate updated.', 'commission_rate' => $rate]);
    }
}
