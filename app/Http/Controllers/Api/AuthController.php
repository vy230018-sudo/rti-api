<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\User;
use App\Services\JwtService;
use App\Services\ReferralCommissionService;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected JwtService $jwtService,
        protected ReferralCommissionService $referralCommissionService,
    )
    {
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'firstname' => ['nullable', 'string', 'max:191'],
            'lastname' => ['nullable', 'string', 'max:191'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:500'],
            'password' => ['required', 'string', 'min:8'],
            'otp' => ['required', 'digits:6'],
            'referral_code' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            'state' => ['required', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:255'],
            'taluka' => ['required', 'string', 'max:255'],
            'village' => ['required', 'string', 'max:255'],
            'state_id' => ['nullable', 'integer', 'min:1'],
            'district_id' => ['nullable', 'integer', 'min:1'],
            'taluka_id' => ['nullable', 'integer', 'min:1'],
            'village_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $firstname = $validated['firstname'] ?? $validated['name'] ?? null;

        if (! $firstname) {
            throw ValidationException::withMessages([
                'firstname' => ['The firstname field is required.'],
            ]);
        }

        $registrationOtp = \DB::table('email_otp_codes')
            ->where('email', $validated['email'])
            ->where('purpose', 'registration')
            ->first();

        if (! $registrationOtp || ! $registrationOtp->verified_at || ! $this->isValidOtpRecord($registrationOtp->created_at, $registrationOtp->token, $validated['otp'])) {
            throw ValidationException::withMessages([
                'otp' => ['Please verify a valid email OTP before registering.'],
            ]);
        }

        $code = $validated['code'] ?? null;
        $referrer = null;

        if (!empty($validated['code'])) {
        $referrer = User::where('referral_code', $validated['code'])->first();

        if (!$referrer) {
            throw ValidationException::withMessages([
                'code' => ['Check your referral code.'],
            ]);
        }
    }


        if (! empty($validated['referral_code'])) {
            $referrer = $this->findUserByReferralCode($validated['referral_code']);

            if (! $referrer) {
                throw ValidationException::withMessages([
                    'referral_code' => ['The provided referral code is invalid.'],
                ]);
            }
        }

        if ($referrer && ! $this->referralCommissionService->isEligible($referrer)) {
            throw ValidationException::withMessages([
                'referral_code' => ['This referral code requires an active Premium/Blue Tick role subscription.'],
            ]);
        }
        $ref_code = 'RTI' . mt_rand(100000, 999999);
        $user = User::query()->create([
            'firstname' => $firstname,
            'lastname' => $validated['lastname'] ?? null,
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'bio' => $validated['bio'] ?? null,
            'referral_code' => $ref_code,
            'password' => $validated['password'],
            'email_verified_at' => now(),
            'is_admin' => false,
            'status' => '1',
            'is_deleted' => '1',
        ]);

        // Location is created in the same registration flow, so an account can
        // never be created with an incomplete location hierarchy.
        $user->states()->create([
            'state' => $validated['state'],
            'district' => $validated['district'],
            'taluka' => $validated['taluka'],
            'village' => $validated['village'],
            'state_id' => $validated['state_id'] ?? null,
            'district_id' => $validated['district_id'] ?? null,
            'taluka_id' => $validated['taluka_id'] ?? null,
            'village_id' => $validated['village_id'] ?? null,
            'status' => '1',
            'id_deleted' => '1',
        ]);

        \DB::table('email_otp_codes')
            ->where('email', $validated['email'])
            ->where('purpose', 'registration')
            ->delete();

        if ($referrer && $referrer->id == $user->id) {
        throw ValidationException::withMessages([
            'code' => ['You cannot use your own referral code.'],
        ]);
    }

    if ($referrer) {
        Referral::create([
            'user_id' => $user->id, 
            'from_user_id' => $referrer->id,
            'refral_code' => $validated['referral_code'] ?? $code,
            'amount' => 0,
            'level' => 1,
            'status' => 1,
            'is_deleted' => 1,
        ]);
    }

        $token = $this->jwtService->issueToken($user);

        return response()->json([
            'message' => 'User registered successfully.',
            'user' => $this->formatUserDetails($user->fresh()),
            'authorization' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        if (! $user || $user->is_admin || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided user credentials are incorrect.'],
            ]);
        }
        if ((string) $user->is_deleted === '0') {
            // Account delete (inactive) hai. App `code` dekh kar "Reactivate" option dikha sakta hai.
            return response()->json([
                'message' => 'Your account is deleted. Reactivate it to continue.',
                'errors' => ['email' => ['Your account is deleted. Reactivate it to continue.']],
                'code' => 'account_deleted',
            ], 422);
        }

        if ($user->status == 0) {
            throw ValidationException::withMessages([
                'email' => ['Your account is inactive. Please contact admin.'],
            ]);
        }
        // Fetch a fresh record so a profile approved by an admin is returned
        // immediately on the user's next login.
        $user = $user->fresh();

        return response()->json([
            'message' => 'Login successful.',
            'user' => $this->formatUserDetails($user),
            'profile' => $this->formatLoginProfile($user),
            'authorization' => $this->jwtService->issueToken($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->formatUserDetails($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = (string) $request->attributes->get('jwt_token');
        $this->jwtService->invalidate($token);

        return response()->json([
            'message' => 'Logout successful.',
        ]);
    }

    /**
     * User apna account delete (inactive) kare. Data nahi hatta, sirf is_deleted = 0.
     * DELETE|POST /api/account   body: { "password": "..." }
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if ($user->is_admin) {
            return response()->json([
                'message' => 'Admin account cannot be deleted here.',
                'code' => 'admin_account_delete_not_allowed',
            ], 403);
        }

        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The provided password is incorrect.'],
            ]);
        }

        $user->forceFill(['is_deleted' => '0'])->save();

        \App\Models\DeviceToken::query()->where('user_id', $user->id)->delete();

        // Current token turant band.
        $this->jwtService->invalidate((string) $request->attributes->get('jwt_token'));

        return response()->json([
            'message' => 'Your account has been deleted. You can reactivate it anytime by logging in again.',
        ]);
    }

    /**
     * Delete hua account wapas active karo. Public route (user login nahi hai).
     * POST /api/account/reactivate   body: { "email": "...", "password": "..." }
     * Success par login jaisa hi response (user, profile, authorization token) deta hai.
     */
    public function reactivateAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        if (! $user || $user->is_admin || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided user credentials are incorrect.'],
            ]);
        }

        if ((string) $user->is_deleted !== '0') {
            throw ValidationException::withMessages([
                'email' => ['This account is already active.'],
            ]);
        }

        // Admin ne block (status = 0) kiya ho to user khud active nahi kar sakta.
        if ((string) $user->status === '0') {
            throw ValidationException::withMessages([
                'email' => ['Your account is inactive. Please contact admin.'],
            ]);
        }

        $user->forceFill(['is_deleted' => '1'])->save();
        $user = $user->fresh();

        return response()->json([
            'message' => 'Account reactivated successfully.',
            'user' => $this->formatUserDetails($user),
            'profile' => $this->formatLoginProfile($user),
            'authorization' => $this->jwtService->issueToken($user),
        ]);
    }

    public function requestForgotPasswordOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $user = User::query()
            ->where('email', $validated['email'])
            ->where('is_admin', false)
            ->where('is_deleted', '1')
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['No active user account was found for this email address.'],
            ]);
        }

        $otp = (string) random_int(100000, 999999);

        \DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $validated['email']],
            [
                'token' => Hash::make($otp),
                'created_at' => now(),
            ]
        );

        $this->sendOtpEmail($validated['email'], $otp, 'Password reset');

        return response()->json([
            'message' => 'OTP sent successfully.',
            'email' => $validated['email'],
            'expires_in_minutes' => 5,
        ]);
    }

    public function verifyForgotPasswordOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'otp' => ['required', 'digits:6'],
        ]);

        $record = \DB::table('password_reset_tokens')
            ->where('email', $validated['email'])
            ->first();

         

        if (! $record || ! $this->isValidOtpRecord($record->created_at, $record->token, $validated['otp'])) {
            throw ValidationException::withMessages([
                'otp' => ['The provided OTP is invalid or expired.'],
            ]);
        }

        return response()->json([
            'message' => 'OTP verified successfully.',
        ]);
    }

    public function resetPasswordWithOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'otp' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::query()
            ->where('email', $validated['email'])
            ->where('is_admin', false)
            ->first();

        $record = \DB::table('password_reset_tokens')
            ->where('email', $validated['email'])
            ->first();

            

        if (! $user || ! $record || ! $this->isValidOtpRecord($record->created_at, $record->token, $validated['otp'])) {
            throw ValidationException::withMessages([
                'otp' => ['The provided OTP is invalid or expired.'],
            ]);
        }

        $user->forceFill([
            'password' => $validated['password'],
        ])->save();

        \DB::table('password_reset_tokens')
            ->where('email', $validated['email'])
            ->delete();

        return response()->json([
            'message' => 'Password reset successful.',
        ]);
    }

    protected function isValidOtpRecord(?string $createdAt, string $hashedOtp, string $otp): bool
    {
        if (! $createdAt) {
            return false;
        }

        return Carbon::parse($createdAt)->addMinutes(5)->isFuture() && Hash::check($otp, $hashedOtp);
    }

    public function requestRegistrationOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        ]);

        // SMTP abhi server pe kaam nahi kar raha, isliye registration ke liye
        // fixed OTP use hota hai (default 183232) aur email nahi bheja jata.
        // Real email OTP wapas chalu karne ke liye .env me REGISTRATION_STATIC_OTP= (khali) kar do.
        $staticOtp = config('services.registration_static_otp');
        $otp = $staticOtp ? (string) $staticOtp : (string) random_int(100000, 999999);

        \DB::table('email_otp_codes')->updateOrInsert(
            ['email' => $validated['email'], 'purpose' => 'registration'],
            ['token' => Hash::make($otp), 'verified_at' => null, 'created_at' => now()]
        );

        if (! $staticOtp) {
            $this->sendOtpEmail($validated['email'], $otp, 'Email verification');
        }

        return response()->json([
            'message' => 'Registration OTP sent successfully.',
            'email' => $validated['email'],
            'expires_in_minutes' => 5,
        ]);
    }

    public function verifyRegistrationOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'otp' => ['required', 'digits:6'],
        ]);

        $record = \DB::table('email_otp_codes')
            ->where('email', $validated['email'])
            ->where('purpose', 'registration')
            ->first();

        if (! $record || ! $this->isValidOtpRecord($record->created_at, $record->token, $validated['otp'])) {
            throw ValidationException::withMessages([
                'otp' => ['The provided OTP is invalid or expired.'],
            ]);
        }

        \DB::table('email_otp_codes')
            ->where('email', $validated['email'])
            ->where('purpose', 'registration')
            ->update(['verified_at' => now()]);

        return response()->json(['message' => 'Email OTP verified successfully.']);
    }

    protected function sendOtpEmail(string $email, string $otp, string $purpose): void
    {
        Mail::raw("Your {$purpose} OTP is: {$otp}. It is valid for 5 minutes.", function ($message) use ($email, $purpose) {
            $message->to($email)->subject("{$purpose} OTP");
        });
    }

    protected function findUserByReferralCode(string $referralCode): ?User
    {
        $userId = preg_replace('/^RTI/i', '', trim($referralCode));

        if (! $userId || ! ctype_digit($userId)) {
            return null;
        }

        return User::query()
            ->whereKey((int) $userId)
            ->where('is_admin', false)
            ->where('is_deleted', '1')
            ->first();
    }

    protected function formatUserDetails(User $user): array
    {
        $state = $user->states()->latest('id')->first();

        return [
            'id' => $user->id,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'email' => $user->email,
            'mobile_no' => $user->phone,
            'bio' => $user->bio,
            'profile_image' => $user->profile_image,
            'referral_code' => $user->referralCode(),
            'has_blue_tick' => $this->referralCommissionService->isEligible($user),
            'referral_eligible' => $this->referralCommissionService->isEligible($user),
            'state' => $state?->state,
            'district' => $state?->district,
            'taluka' => $state?->taluka,
            'village' => $state?->village,
            'status' => $user->status,
            'is_deleted' => $user->is_deleted,
        ];
    }

    /**
     * Profile data returned with login. Approved changes are stored in the
     * primary fields; unapproved changes remain in the pending_* fields.
     */
    protected function formatLoginProfile(User $user): array
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
            'profile_image' => $user->profile_image,
            'profile_image_url' => $user->profile_image ? Storage::disk('public')->url($user->profile_image) : null,
            'pending_profile_image' => $user->pending_profile_image,
            'pending_profile_image_url' => $user->pending_profile_image ? Storage::disk('public')->url($user->pending_profile_image) : null,
            'profile_update_status' => $user->profile_update_status,
            'profile_image_change_count' => (int) $user->profile_image_change_count,
            'referral_code' => $user->referralCode(),
            'has_blue_tick' => $this->referralCommissionService->isEligible($user),
            'referral_eligible' => $this->referralCommissionService->isEligible($user),
            'state' => $state?->state,
            'district' => $state?->district,
            'taluka' => $state?->taluka,
            'village' => $state?->village,
            'status' => $user->status,
        ];
    }
}
