<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\User;
use App\Models\ReferralCommission;
use App\Models\UserSubscription;
use App\Services\ReferralCommissionService;
use Illuminate\Support\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReferralController extends Controller
{
    public function __construct(protected ReferralCommissionService $commissionService)
    {
    }

    public function store(Request $request): JsonResponse
{
    $validated = $request->validate([
        'referral_code' => ['required', 'string', 'max:255'],
    ]);

    $user = $request->user();

    if (!$user) {
        return response()->json([
            'message' => 'Unauthorized',
        ], 401);
    }

    $existingReferral = Referral::query()
        ->where('user_id', $user->id)
        ->where('is_deleted', 1)
        ->first();

    if ($existingReferral) {
        throw ValidationException::withMessages([
            'referral_code' => ['Referral has already been applied.'],
        ]);
    }

    $referrer = $this->findUserByReferralCode($validated['referral_code']);

    if (!$referrer || $referrer->id == $user->id) {
        throw ValidationException::withMessages([
            'referral_code' => ['Invalid referral code.'],
        ]);
    }

    if (! $this->commissionService->isEligible($referrer)) {
        throw ValidationException::withMessages([
            'referral_code' => ['This referral code belongs to a user without an active Premium/Blue Tick role subscription.'],
        ]);
    }

    DB::beginTransaction();

    try {

        $referral = Referral::create([
            'user_id'       => $user->id,
            'from_user_id'  => $referrer->id,
            'refral_code'   => $validated['referral_code'],
            'amount'        => 0,
            'level'         => 1,
            'status'        => 1,
            'is_deleted'    => 1,
        ]);

        // Joining only creates a relationship. Commission is generated after a
        // successful eligible paid subscription, never at registration.

        DB::commit();

        return response()->json([
            'message' => 'Referral applied successfully.',
            'data' => $this->formatReferral(
                $referral->load(['user', 'referrer'])
            ),
        ], 201);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([
            'message' => $e->getMessage(),
        ], 500);
    }
}

public function index(Request $request): JsonResponse
{
    $user = $request->user();

    // Purana (direct-only) format - baaki screens/clients ke liye same rakha hai.
    $referrals = Referral::query()
        ->with(['user', 'referrer'])
        ->where('from_user_id', $user->id)
        ->where('is_deleted', 1)
        ->latest('id')
        ->get()
        ->map(function (Referral $referral) {
            return $this->formatReferral($referral);
        });
    $appliedReferral = Referral::query()
        ->with(['user', 'referrer'])
        ->where('user_id', $user->id)
        ->where('is_deleted', 1)
        ->latest('id')
        ->first();

    $commission = ReferralCommission::query()
        ->where('user_id', $user->id)
        ->where('is_deleted', 1)
        ->whereIn('status', ['pending', 'approved', 'paid'])
        ->sum('commission_amount');

    // App ke "members" list ke liye: L1..L5 poori downline, flat fields ke saath.
    $referredUsers = $this->referredUsers($user);

    return response()->json([
        'referral_code'     => $user->referralCode(),
        'total_referrals'   => $referredUsers->count(),
        'referral_count'    => $referredUsers->count(),
        'direct_referrals'  => $referrals->count(),
        'total_commission'  => $commission,
        'referrals'         => $referrals,
        'referred_users'    => $referredUsers,
        'applied_referral'  => $appliedReferral ? $this->formatReferral($appliedReferral) : null,
        'referral_eligible' => $this->commissionService->isEligible($user),
        'eligibility_message' => 'Referral benefits require an active Premium/Blue Tick role subscription and are generated only on successful eligible subscription payments.',
    ]);
}

/**
 * Logged-in user ki downline (max 5 levels) - har member ke liye:
 * id, name, email, mobile, level, commission, is_subscribed, created_at.
 *
 * commission = is member ki wajah se is user ko mili (reversed ko chhodkar) total commission.
 */
protected function referredUsers(User $viewer, int $maxLevel = 5): Collection
{
    $links = [];            // [['referral' => Referral, 'level' => int]]
    $seen = [(string) $viewer->id => true];
    $frontier = [(string) $viewer->id];

    for ($level = 1; $level <= $maxLevel && $frontier; $level++) {
        $found = Referral::query()
            ->whereIn('from_user_id', $frontier)
            ->where('is_deleted', 1)
            ->orderBy('id')
            ->get();

        $frontier = [];

        foreach ($found as $referral) {
            $memberId = (string) $referral->user_id;

            if (isset($seen[$memberId])) {
                continue; // loop / duplicate se bachav
            }

            $seen[$memberId] = true;
            $frontier[] = $memberId;
            $links[] = ['referral' => $referral, 'level' => $level];
        }
    }

    if ($links === []) {
        return collect();
    }

    $memberIds = array_map(fn ($l) => (string) $l['referral']->user_id, $links);

    $members = User::query()->whereIn('id', $memberIds)->get()->keyBy(fn (User $u) => (string) $u->id);

    $subscribed = UserSubscription::query()->active()
        ->whereIn('user_id', $memberIds)
        ->pluck('user_id')
        ->map(fn ($id) => (string) $id)
        ->flip();

    $earned = ReferralCommission::query()
        ->where('user_id', (string) $viewer->id)
        ->where('is_deleted', 1)
        ->whereIn('status', ['pending', 'approved', 'paid'])
        ->whereIn('referred_user_id', $memberIds)
        ->selectRaw('referred_user_id, COALESCE(SUM(commission_amount), 0) as total')
        ->groupBy('referred_user_id')
        ->pluck('total', 'referred_user_id');

    return collect($links)
        ->map(function (array $link) use ($members, $subscribed, $earned) {
            $referral = $link['referral'];
            $memberId = (string) $referral->user_id;
            $member = $members->get($memberId);

            $name = $member ? trim(implode(' ', array_filter([$member->firstname, $member->lastname]))) : '';

            if ($name === '' && $member?->email) {
                $name = strstr($member->email, '@', true) ?: $member->email;
            }

            return [
                'id' => (int) $referral->user_id,
                'name' => $name,
                'email' => $member?->email,
                'mobile' => $member?->phone,
                'level' => $link['level'],
                'commission' => round((float) ($earned[$memberId] ?? 0), 2),
                'is_subscribed' => $subscribed->has($memberId),
                'created_at' => $referral->created_at,
            ];
        })
        ->sortByDesc('created_at')
        ->values();
}

public function adminIndex(Request $request): JsonResponse
{
    $query = Referral::query()
        ->with(['user', 'referrer'])
        ->where('is_deleted', 1)
        ->latest('id');

    if ($request->filled('user_id')) {
        $query->where('from_user_id', $request->user_id);
    }

    $referrals = $query->get()->map(function (Referral $referral) {
        return $this->formatReferral($referral);
    });

    return response()->json([
        'total_referrals' => $referrals->count(),
        'data' => $referrals,
        'referrals' => $referrals,
    ]);
}
public function adminUserWise(string $userId): JsonResponse
{
    $referrals = Referral::query()
        ->with(['user', 'referrer'])
        ->where('from_user_id', $userId)
        ->where('is_deleted', 1)
        ->latest('id')
        ->get()
        ->map(function (Referral $referral) {
            return $this->formatReferral($referral);
        });

    $commission = ReferralCommission::query()
        ->where('user_id', $userId)
        ->whereIn('status', ['pending', 'approved', 'paid'])
        ->sum('commission_amount');

    return response()->json([
        'user_id' => $userId,
        'total_referrals' => $referrals->count(),
        'total_commission' => $commission,
        'data' => $referrals,
        'referrals' => $referrals,
    ]);
}

protected function formatReferral(Referral $referral): array
{
    return [
        'id' => $referral->id,
        'refral_code' => $referral->refral_code,
        'amount' => $referral->amount,
        'level' => $referral->level,
        'status' => $referral->status,

        'user' => $referral->user ? [
            'id' => $referral->user->id,
            'firstname' => $referral->user->firstname,
            'lastname' => $referral->user->lastname,
            'email' => $referral->user->email,
            'phone' => $referral->user->phone,
            'has_blue_tick' => $this->commissionService->isEligible($referral->user),
        ] : null,

        'from_user' => $referral->referrer ? [
            'id' => $referral->referrer->id,
            'firstname' => $referral->referrer->firstname,
            'lastname' => $referral->referrer->lastname,
            'email' => $referral->referrer->email,
            'phone' => $referral->referrer->phone,
            'has_blue_tick' => $this->commissionService->isEligible($referral->referrer),
        ] : null,

        'created_at' => $referral->created_at,
        'updated_at' => $referral->updated_at,
    ];
}

protected function findUserByReferralCode(string $referralCode): ?User
{
    $referralCode = strtoupper(trim($referralCode));
    $userId = preg_replace('/^RTI/', '', $referralCode);

    if ($userId !== '' && ctype_digit($userId)) {
        $user = User::query()
            ->whereKey((int) $userId)
            ->where('is_admin', false)
            ->where('is_deleted', 1)
            ->first();

        if ($user) {
            return $user;
        }
    }

    return User::query()
        ->whereRaw('UPPER(referral_code) = ?', [$referralCode])
        ->where('is_admin', false)
        ->where('is_deleted', 1)
        ->first();
}
}
