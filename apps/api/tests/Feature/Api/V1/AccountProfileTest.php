<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AccountProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_update_their_name_with_their_current_password(): void
    {
        $user = User::factory()->create([
            'email' => 'asha@example.com',
            'password' => 'LearningPass123',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me/profile', [
            'name' => 'Asha Rao',
            'email' => 'ASHA@example.com',
            'current_password' => 'LearningPass123',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Asha Rao')
            ->assertJsonPath('data.email', 'asha@example.com')
            ->assertJsonPath('email_changed', false);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_changing_email_requires_reverification_before_login(): void
    {
        Notification::fake();
        config(['app.env' => 'local', 'app.url' => 'http://127.0.0.1:8002']);
        URL::forceRootUrl('http://127.0.0.1:8002');
        $user = User::factory()->create([
            'email' => 'asha@example.com',
            'password' => 'LearningPass123',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me/profile', [
            'name' => 'Asha Rao',
            'email' => 'ASHA.RAO@example.com',
            'current_password' => 'LearningPass123',
        ])->assertOk()
            ->assertJsonPath('data.email', 'asha.rao@example.com')
            ->assertJsonPath('data.email_verified_at', null)
            ->assertJsonPath('email_changed', true);

        $updatedUser = $user->fresh();
        Notification::assertSentTo($updatedUser, VerifyEmail::class);
        $verificationUrl = $response->json('verification_url');
        $this->assertIsString($verificationUrl);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'asha.rao@example.com',
            'password' => 'LearningPass123',
        ])->assertForbidden()->assertJsonPath('status', 'email_verification_required');

        $this->getJson($verificationUrl)->assertOk()->assertJsonPath('status', 'verified');
        Auth::forgetGuards();
        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/login',
        ])->postJson('/api/v1/auth/login', [
            'email' => 'asha.rao@example.com',
            'password' => 'LearningPass123',
        ])->assertOk()->assertJsonPath('data.email', 'asha.rao@example.com');
    }

    public function test_profile_updates_require_the_current_password_and_a_unique_email(): void
    {
        $user = User::factory()->create(['email' => 'asha@example.com', 'password' => 'LearningPass123']);
        User::factory()->create(['email' => 'rohan@example.com']);

        $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me/profile', [
            'name' => 'Asha Rao', 'email' => 'asha@example.com', 'current_password' => 'WrongPassword123',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->patchJson('/api/v1/me/profile', [
            'name' => 'Asha Rao', 'email' => 'rohan@example.com', 'current_password' => 'LearningPass123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_account_profile_cannot_change_into_or_out_of_the_platform_operator_allowlist(): void
    {
        Config::set('services.platform.admin_emails', ['operator@example.com']);
        $user = User::factory()->create(['email' => 'member@example.com', 'password' => 'LearningPass123']);
        $operator = User::factory()->create(['email' => 'operator@example.com', 'password' => 'LearningPass123']);

        $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me/profile', [
            'name' => 'Member', 'email' => 'operator@example.com', 'current_password' => 'LearningPass123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        Auth::forgetGuards();
        $this->actingAs($operator, 'sanctum')->patchJson('/api/v1/me/profile', [
            'name' => 'Operator', 'email' => 'new-address@example.com', 'current_password' => 'LearningPass123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }
}
