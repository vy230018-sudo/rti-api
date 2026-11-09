<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_auth_routes_are_available_without_api_prefix(): void
    {
        $response = $this->postJson('/register', [
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.email', 'user@example.com')
            ->assertJsonStructure([
                'message',
                'user',
                'authorization' => ['token', 'expires_at', 'expires_in'],
            ]);
    }

    public function test_authenticated_user_can_access_direct_profile_route(): void
    {
        $user = User::factory()->create([
            'password' => 'password123',
        ]);

        $loginResponse = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('authorization.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_non_admin_user_cannot_access_admin_rti_routes(): void
    {
        $user = User::factory()->create([
            'password' => 'password123',
            'is_admin' => false,
        ]);

        $loginResponse = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('authorization.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/admin-rti/dashboard')
            ->assertForbidden()
            ->assertJsonPath('message', 'Admin access is required.');
    }

    public function test_admin_user_can_login_and_access_admin_rti_routes(): void
    {
        $admin = User::factory()->create([
            'password' => 'password123',
            'is_admin' => true,
        ]);

        $loginResponse = $this->postJson('/admin-rti/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('authorization.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/admin-rti/dashboard')
            ->assertOk()
            ->assertJsonPath('user.email', $admin->email);
    }

    public function test_user_can_request_verify_and_reset_password_with_otp(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'password' => 'password123',
        ]);

        $requestOtp = $this->postJson('/forgot-password/request-otp', [
            'email' => $user->email,
        ]);

        $otp = $requestOtp
            ->assertOk()
            ->assertJsonPath('email', $user->email)
            ->json('otp');

        $this->postJson('/forgot-password/verify-otp', [
            'email' => $user->email,
            'otp' => $otp,
        ])->assertOk();

        $this->postJson('/forgot-password/reset-password', [
            'email' => $user->email,
            'otp' => $otp,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'newpassword123',
        ])->assertOk();
    }

    public function test_authenticated_user_can_save_profile_and_update_profile_image(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'password' => 'password123',
        ]);

        $token = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json('authorization.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/profile', [
                'firstname' => 'Updated',
                'lastname' => 'User',
                'phone' => '9999999999',
                'bio' => 'Profile bio',
            ])
            ->assertCreated()
            ->assertJsonPath('profile.firstname', 'Updated');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/profile/image', [
                'profile_image' => UploadedFile::fake()->image('avatar.png'),
            ])
            ->assertOk()
            ->assertJsonPath('profile.firstname', 'Updated');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/profile')
            ->assertOk()
            ->assertJsonPath('profile.phone', '9999999999');
    }

    public function test_referral_is_created_on_register_and_visible_to_user_and_admin(): void
    {
        $referrer = User::factory()->create([
            'password' => 'password123',
            'firstname' => 'Referrer',
        ]);

        $response = $this->postJson('/register', [
            'firstname' => 'Joined',
            'email' => 'joined@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'referral_code' => $referrer->referralCode(),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.email', 'joined@example.com');

        $joinedUserToken = $response->json('authorization.token');

        $this->withHeader('Authorization', 'Bearer '.$joinedUserToken)
            ->getJson('/referrals')
            ->assertOk()
            ->assertJsonPath('applied_referral.from_user.email', $referrer->email);

        $referrerToken = $this->postJson('/login', [
            'email' => $referrer->email,
            'password' => 'password123',
        ])->json('authorization.token');

        $this->withHeader('Authorization', 'Bearer '.$referrerToken)
            ->getJson('/referrals')
            ->assertOk()
            ->assertJsonPath('referral_count', 1);

        $admin = User::factory()->create([
            'password' => 'password123',
            'is_admin' => true,
        ]);

        $adminToken = $this->postJson('/admin-rti/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ])->json('authorization.token');

        $joinedUserId = (string) User::query()->where('email', 'joined@example.com')->value('id');

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/admin-rti/referrals')
            ->assertOk()
            ->assertJsonCount(1, 'referrals');

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/admin-rti/users/'.$referrer->id.'/referrals')
            ->assertOk()
            ->assertJsonPath('referrals.0.user.id', (int) $joinedUserId);
    }

    public function test_admin_can_manage_user_status_and_soft_delete(): void
    {
        $admin = User::factory()->create([
            'password' => 'password123',
            'is_admin' => true,
        ]);

        $user = User::factory()->create([
            'password' => 'password123',
        ]);

        $adminToken = $this->postJson('/admin-rti/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ])->json('authorization.token');

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->patchJson('/admin-rti/users/'.$user->id.'/status', [
                'status' => '0',
            ])
            ->assertOk()
            ->assertJsonPath('user.status', '0');

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->deleteJson('/admin-rti/users/'.$user->id)
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_deleted' => '0',
        ]);
    }

    public function test_admin_jwt_can_approve_a_pending_profile_update(): void
    {
        $admin = User::factory()->create([
            'password' => 'password123',
            'is_admin' => true,
        ]);
        $user = User::factory()->create([
            'phone' => '9000000000',
            'bio' => 'Current bio',
            'pending_phone' => '9999999999',
            'pending_bio' => 'Pending bio',
            'profile_update_status' => 1,
        ]);

        $adminToken = $this->postJson('/admin-rti/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ])->json('authorization.token');

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->patchJson('/admin-rti/users/'.$user->id.'/approve-profile')
            ->assertOk()
            ->assertJsonPath('approved', true)
            ->assertJsonPath('user.phone', '9999999999')
            ->assertJsonPath('user.bio', 'Pending bio')
            ->assertJsonPath('user.profile_update_status', 0);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'phone' => '9999999999',
            'bio' => 'Pending bio',
            'pending_phone' => null,
            'pending_bio' => null,
            'profile_update_status' => 0,
        ]);
    }
}
