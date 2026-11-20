<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\PlatformAuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowlisted_operator_can_invite_staff_and_recipient_creates_their_own_account(): void
    {
        Config::set('services.platform.admin_emails', ['operator@example.com']);
        Config::set('app.env', 'local');
        Mail::fake();
        $operator = User::factory()->create(['name' => 'Platform Operator', 'email' => 'operator@example.com']);
        $organization = $this->organization('Northstar Academy');
        Sanctum::actingAs($operator);

        $response = $this->postJson('/api/v1/platform/invitations', [
            'organization_id' => $organization->id,
            'email' => 'New.Teacher@example.com',
            'role' => 'teacher',
            'reason' => 'Approved staffing request',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'new.teacher@example.com')
            ->assertJsonPath('data.role', 'teacher')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('message', 'Invitation sent. The recipient creates their account and accepts the organization role using the invitation link.');

        $invitationUrl = $response->json('invitation_url');
        $this->assertIsString($invitationUrl);
        parse_str((string) parse_url($invitationUrl, PHP_URL_QUERY), $query);
        $token = $query['token'] ?? null;
        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));
        $invitation = OrganizationInvitation::query()->firstOrFail();
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertDatabaseMissing('users', ['email' => 'new.teacher@example.com']);
        $this->assertDatabaseHas('platform_audit_events', [
            'actor_user_id' => $operator->id,
            'organization_id' => $organization->id,
            'action' => 'organization.member_invited',
            'reason' => 'Approved staffing request',
            'after->email' => 'new.teacher@example.com',
            'after->role' => 'teacher',
        ]);
        $this->assertStringNotContainsString($token, (string) PlatformAuditEvent::query()->firstOrFail()->getRawOriginal('after'));
        Mail::assertSent(OrganizationInvitationMail::class, fn (OrganizationInvitationMail $mail): bool => $mail->hasTo('new.teacher@example.com') && $mail->invitationUrl === $invitationUrl);

        $this->getJson("/api/v1/auth/invitations/{$token}")->assertOk()
            ->assertJsonPath('data.organization_id', $organization->id)
            ->assertJsonPath('data.email', 'new.teacher@example.com')
            ->assertJsonPath('data.role', 'teacher');
        $this->postJson('/api/v1/auth/invitations/register', [
            'token' => $token,
            'name' => 'New Teacher',
            'password' => 'strongpassword123',
            'password_confirmation' => 'strongpassword123',
        ])->assertCreated()->assertJsonPath('data.email_verified_at', fn ($value) => is_string($value));
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id,
            'role' => OrganizationRole::Teacher->value,
            'status' => MembershipStatus::Active->value,
        ]);
        $this->assertDatabaseHas('teachers', ['organization_id' => $organization->id, 'email' => 'new.teacher@example.com']);
        $this->assertNotNull(User::query()->where('email', 'new.teacher@example.com')->firstOrFail()->email_verified_at);
    }

    public function test_platform_invitation_requires_operator_allowlist_and_valid_role_reason_and_active_organization(): void
    {
        Config::set('services.platform.admin_emails', ['operator@example.com']);
        $operator = User::factory()->create(['email' => 'operator@example.com']);
        $ordinaryUser = User::factory()->create(['email' => 'school-admin@example.com']);
        $organization = $this->organization('Northstar Academy');
        Sanctum::actingAs($ordinaryUser);
        $payload = ['organization_id' => $organization->id, 'email' => 'teacher@example.com', 'role' => 'teacher', 'reason' => 'Approved request'];
        $this->postJson('/api/v1/platform/invitations', $payload)->assertForbidden();

        Sanctum::actingAs($operator);
        $this->postJson('/api/v1/platform/invitations', [...$payload, 'role' => 'student'])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->postJson('/api/v1/platform/invitations', [...$payload, 'reason' => '       '])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $suspended = $this->organization('Suspended Academy', 'suspended');
        $this->postJson('/api/v1/platform/invitations', [...$payload, 'organization_id' => $suspended->id])
            ->assertUnprocessable()->assertJsonValidationErrors('organization_id');
        $this->assertDatabaseCount('organization_invitations', 0);
        $this->assertDatabaseCount('platform_audit_events', 0);
    }

    public function test_platform_operator_cannot_invite_an_existing_active_or_suspended_organization_member(): void
    {
        Config::set('services.platform.admin_emails', ['operator@example.com']);
        $operator = User::factory()->create(['email' => 'operator@example.com']);
        $organization = $this->organization('Northstar Academy');
        $member = User::factory()->create(['email' => 'member@example.com']);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $member->id,
            'role' => OrganizationRole::Teacher,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);
        Sanctum::actingAs($operator);

        $this->postJson('/api/v1/platform/invitations', [
            'organization_id' => $organization->id, 'email' => $member->email,
            'role' => 'admin', 'reason' => 'Approved account change',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $suspendedAccount = User::factory()->create(['email' => 'suspended@example.com', 'platform_status' => 'suspended']);
        $this->postJson('/api/v1/platform/invitations', [
            'organization_id' => $organization->id, 'email' => $suspendedAccount->email,
            'role' => 'teacher', 'reason' => 'Approved staff invitation',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('organization_invitations', 0);
        $this->assertDatabaseCount('platform_audit_events', 0);
    }

    private function organization(string $name, string $status = 'active'): Organization
    {
        return Organization::query()->create([
            'name' => $name, 'slug' => str()->slug($name).'-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => $status, 'timezone' => 'Asia/Kolkata',
        ]);
    }
}
