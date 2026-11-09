<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\AppNotification;
use App\Notifications\IdCardStatusNotification;
use App\Models\IdCard;
use App\Models\UserSubscription;
use App\Services\IdNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class IdCardController extends Controller
{
    /** Create an ID card only for the caller's current active role subscription. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['subscription_id' => ['required', 'integer']]);

        $subscription = UserSubscription::query()
            ->with('plan')
            ->active()
            ->where('user_id', (string) $request->user()->id)
            ->findOrFail($data['subscription_id']);

        $card = IdCard::query()->firstOrCreate(
            ['user_subscription_id' => $subscription->id],
            [
                'user_id' => $request->user()->id,
                'verification_token' => Str::random(64),
                'status' => IdCard::STATUS_ACTIVE,
            ]
        );

        if (! $card->id_number) {
            $card->forceFill(['id_number' => $this->makeIdNumber($request->user()->email, $card)])->save();
        }

        return response()->json([
            'message' => 'ID card is ready.',
            'id_card' => $this->formatOwnerCard($card->fresh()->load('subscription.plan')),
        ], 201);
    }

    /**
     * Email-hash wala ID number (card, PDF aur verification page teeno me yahi dikhega).
     * Email khali ho, ya same ID kisi doosre card ko pehle se mil chuka ho (hash collision),
     * to purana unique format RTI-ID-00000001 use hota hai.
     */
    protected function makeIdNumber(?string $email, IdCard $card): string
    {
        $idNumber = IdNumberService::fromEmail($email);

        if ($idNumber !== null
            && ! IdCard::query()->where('id_number', $idNumber)->where('id', '!=', $card->id)->exists()) {
            return $idNumber;
        }

        return 'RTI-ID-'.str_pad((string) $card->id, 8, '0', STR_PAD_LEFT);
    }

    public function mine(Request $request): JsonResponse
    {
        $cards = IdCard::query()
            ->with('subscription.plan')
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->get()
            ->map(fn (IdCard $card) => $this->formatOwnerCard($card));

        return response()->json(['id_cards' => $cards]);
    }

    /**
     * Public endpoint used by QR scanners. It intentionally returns no email, phone or address.
     *
     * - Browser / phone camera scan (Accept: text/html)  -> verification PAGE
     * - App / API (Accept: application/json) ya ?format=json -> JSON (pehle jaisa hi, koi change nahi)
     */
    public function verify(Request $request, string $token): JsonResponse|Response
    {
        $wantsJson = $request->expectsJson() || $request->query('format') === 'json';

        $card = IdCard::query()->with(['user', 'subscription.plan'])->where('verification_token', $token)->first();

        if (! $card) {
            $payload = [
                'verified' => false,
                'status' => 'invalid',
                'message' => 'This ID card could not be verified.',
            ];

            return $wantsJson
                ? response()->json($payload, 404)
                : $this->verifyPage($payload, 404);
        }

        $status = $this->liveStatus($card);
        $subscription = $card->subscription;
        $user = $card->user;

        // Only the *approved* profile image is exposed here — a pending
        // (not-yet-approved) upload must never appear on a public ID card.
        $profileImage = $user?->profile_image;

        $payload = [
            'verified' => $status === IdCard::STATUS_ACTIVE,
            'status' => $status,
            'verification' => $status === IdCard::STATUS_ACTIVE ? 'valid' : 'not_valid',
            'id_number' => $card->id_number,
            'name' => trim(implode(' ', array_filter([$user?->firstname, $user?->lastname]))),
            'organisation' => config('app.name'),
            'role' => $subscription?->plan?->role_id,
            'designation' => $subscription?->plan?->title,
            'profile_status' => $user?->status === '1' && $user?->is_deleted === '1' ? 'active' : 'inactive',
            'profile_image' => $profileImage,
            'profile_image_url' => $profileImage ? asset('storage/'.$profileImage) : null,
            'valid_from' => $subscription?->start_date?->toDateString(),
            'valid_until' => $subscription?->end_date?->toDateString(),
        ];

        return $wantsJson ? response()->json($payload) : $this->verifyPage($payload);
    }

    /** QR scan ke baad dikhne wala verification page. */
    protected function verifyPage(array $data, int $httpStatus = 200): Response
    {
        return response()
            ->view('id-card.verify', ['d' => $data], $httpStatus)
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function adminIndex(): JsonResponse
    {
        return response()->json([
            'id_cards' => IdCard::query()->with(['user', 'subscription.plan'])->latest('id')->get()->map(
                fn (IdCard $card) => $this->formatAdminCard($card)
            ),
        ]);
    }

    public function updateStatus(Request $request, IdCard $idCard): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([IdCard::STATUS_ACTIVE, IdCard::STATUS_SUSPENDED, IdCard::STATUS_CANCELLED])],
        ]);

        $idCard->forceFill(['status' => $data['status']])->save();

        if ($idCard->wasChanged('status')) {
            AppNotification::dispatchSystem($idCard->user_id, new IdCardStatusNotification((int) $idCard->id, $idCard->status));
        }

        return response()->json([
            'message' => 'ID card status updated successfully.',
            'id_card' => $this->formatAdminCard($idCard->fresh()->load(['user', 'subscription.plan'])),
        ]);
    }

    protected function liveStatus(IdCard $card): string
    {
        if ($card->status === IdCard::STATUS_CANCELLED) {
            return IdCard::STATUS_CANCELLED;
        }

        if ($card->status === IdCard::STATUS_SUSPENDED) {
            return IdCard::STATUS_SUSPENDED;
        }

        $subscription = $card->subscription;
        if ($subscription?->end_date && $subscription->end_date->lt(now()->startOfDay())) {
            return 'expired';
        }

        if (! $card->user || $card->user->status !== '1' || $card->user->is_deleted !== '1'
            || ! $subscription || ! $subscription->active()->whereKey($subscription->id)->exists()
            || ! $subscription->plan || (int) $subscription->plan->status !== 1 || (int) $subscription->plan->id_deleted !== 1) {
            return 'inactive';
        }

        return IdCard::STATUS_ACTIVE;
    }

    protected function formatOwnerCard(IdCard $card): array
    {
        return [
            'id' => $card->id,
            'id_number' => $card->id_number,
            'status' => $this->liveStatus($card),
            'role' => $card->subscription?->plan?->role_id,
            'designation' => $card->subscription?->plan?->title,
            'valid_from' => $card->subscription?->start_date?->toDateString(),
            'valid_until' => $card->subscription?->end_date?->toDateString(),
            'verification_url' => url('/api/id-cards/verify/'.$card->verification_token),
            'qr_payload' => url('/api/id-cards/verify/'.$card->verification_token),
        ];
    }

    protected function formatAdminCard(IdCard $card): array
    {
        return array_merge($this->formatOwnerCard($card), [
            'user_id' => $card->user_id,
            'name' => trim(implode(' ', array_filter([$card->user?->firstname, $card->user?->lastname]))),
            'configured_status' => $card->status,
        ]);
    }
}