<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_an_organization_membership_and_sends_verification(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Asha Teacher',
            'email' => 'ASHA@example.com',
            'password' => 'LearningPass123',
            'password_confirmation' => 'LearningPass123',
            'organization_name' => 'Northstar Academy',
            'organization_type' => 'academy',
        ])->assertAccepted()->assertJsonPath('status', 'verification_required')->assertJsonMissingPath('verification_url');

        $user = User::query()->where('email', 'asha@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseHas('organizations', [
            'name' => 'Northstar Academy',
            'type' => OrganizationType::Academy->value,
        ]);
        $this->assertDatabaseHas('organization_memberships', [
            'user_id' => $user->id,
            'role' => OrganizationRole::Admin->value,
            'status' => MembershipStatus::Active->value,
        ]);

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_duplicate_registration_does_not_create_another_account_or_organization(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['email' => 'asha@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Different Name',
            'email' => 'asha@example.com',
            'password' => 'LearningPass123',
            'password_confirmation' => 'LearningPass123',
            'organization_name' => 'Different Academy',
            'organization_type' => 'academy',
        ])->assertAccepted();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('organizations', 0);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_signed_verification_enables_login_and_mobile_token_access(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'asha@example.com',
            'password' => 'LearningPass123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'asha@example.com',
            'password' => 'LearningPass123',
            'device_name' => 'Asha phone',
        ])->assertForbidden()->assertJsonPath('status', 'email_verification_required');

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(30),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );
        $this->getJson($verificationUrl)->assertOk()->assertJsonPath('status', 'verified');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ASHA@example.com',
            'password' => 'LearningPass123',
            'device_name' => 'Asha phone',
        ])->assertOk()->assertJsonPath('data.email', 'asha@example.com')
            ->assertJsonPath('token_type', 'Bearer');

        $token = $response->json('token');
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_local_registration_and_resend_responses_expose_a_clickable_verification_link(): void
    {
        Notification::fake();
        config(['app.env' => 'local', 'app.url' => 'http://127.0.0.1:8002']);
        URL::forceRootUrl('http://127.0.0.1:8002');

        $registration = $this->postJson('/api/v1/auth/register', [
            'name' => 'Asha Teacher', 'email' => 'asha@example.com',
            'password' => 'LearningPass123', 'password_confirmation' => 'LearningPass123',
            'organization_name' => 'Northstar Academy', 'organization_type' => 'academy',
        ])->assertAccepted()->assertJsonPath('status', 'verification_required');
        $registrationUrl = $registration->json('verification_url');
        $this->assertIsString($registrationUrl);
        $this->assertStringStartsWith('http://127.0.0.1:8002/', $registrationUrl);

        $resendUrl = $this->postJson('/api/v1/auth/email/verification-notification', ['email' => 'asha@example.com'])
            ->assertAccepted()->json('verification_url');
        $this->assertIsString($resendUrl);
        $this->getJson($resendUrl)->assertOk()->assertJsonPath('status', 'verified');
        $this->postJson('/api/v1/auth/login', [
            'email' => 'asha@example.com', 'password' => 'LearningPass123', 'device_name' => 'Verification test',
        ])->assertOk()->assertJsonPath('data.email', 'asha@example.com')->assertJsonPath('token_type', 'Bearer');
        $this->assertNotNull(User::query()->where('email', 'asha@example.com')->value('email_verified_at'));
    }

    public function test_first_party_login_uses_a_session_cookie_for_spa_requests(): void
    {
        $user = User::factory()->create([
            'email' => 'asha@example.com',
            'password' => 'LearningPass123',
        ]);
        $spaRequest = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/login',
        ]);

        $spaRequest->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'LearningPass123',
        ])->assertOk()->assertJsonPath('data.email', $user->email);

        $spaRequest->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $spaRequest->postJson('/api/v1/auth/logout')->assertOk();
        Auth::forgetGuards();
        $spaRequest->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_password_reset_is_generic_and_accepts_a_valid_reset_token(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'asha@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'asha@example.com'])
            ->assertAccepted()->assertJsonMissingPath('reset_url');

        Notification::assertSentTo($user, ResetPassword::class);
        $notification = Notification::sent($user, ResetPassword::class)->first();
        $token = $notification->token;

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'asha@example.com',
            'token' => $token,
            'password' => 'NewLearningPass456',
            'password_confirmation' => 'NewLearningPass456',
        ])->assertOk();

        $this->assertTrue(password_verify('NewLearningPass456', $user->fresh()->password));
    }

    public function test_local_password_reset_returns_a_clickable_link_and_resets_without_sending_email(): void
    {
        Notification::fake();
        config(['app.env' => 'local', 'app.frontend_url' => 'http://127.0.0.1:4178']);
        $user = User::factory()->create(['email' => 'asha@example.com']);

        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'asha@example.com'])
            ->assertAccepted()
            ->assertJsonPath('message', 'If an account exists for that address, password reset instructions have been sent.')
            ->assertJsonStructure(['reset_url']);

        $resetUrl = $response->json('reset_url');
        $this->assertStringStartsWith('http://127.0.0.1:4178/reset-password?', $resetUrl);
        parse_str((string) parse_url($resetUrl, PHP_URL_QUERY), $query);
        $this->assertSame('asha@example.com', $query['email'] ?? null);
        $this->assertIsString($query['token'] ?? null);
        Notification::assertNothingSent();

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'asha@example.com',
            'token' => $query['token'],
            'password' => 'NewLocalPassword123',
            'password_confirmation' => 'NewLocalPassword123',
        ])->assertOk();

        $this->assertTrue(password_verify('NewLocalPassword123', $user->fresh()->password));
    }

    public function test_organization_detail_is_only_visible_to_an_active_member(): void
    {
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Northstar Academy',
            'slug' => 'northstar-academy',
            'type' => OrganizationType::Academy,
            'status' => 'active',
            'timezone' => 'Asia/Kolkata',
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $member->id,
            'role' => OrganizationRole::Admin,
            'status' => MembershipStatus::Active,
        ]);

        $this->actingAs($outsider)->getJson('/api/v1/organizations/'.$organization->id)->assertNotFound();
        $this->actingAs($member)->getJson('/api/v1/organizations/'.$organization->id)
            ->assertOk()->assertJsonPath('data.organization.name', 'Northstar Academy');
    }
}
