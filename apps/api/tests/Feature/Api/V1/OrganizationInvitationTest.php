<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_invite_new_teacher_who_registers_and_is_linked_to_roster(): void
    {
        [$admin, $organization] = $this->workspace();
        Teacher::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Teacher Roster Record',
            'email' => 'teacher@example.com',
            'employee_number' => 'T-100',
            'status' => 'active',
        ]);
        Sanctum::actingAs($admin);
        Mail::fake();

        $endpoint = "/api/v1/organizations/{$organization->id}/invitations";
        $response = $this->postJson($endpoint, ['email' => 'Teacher@example.com', 'role' => 'teacher'])
            ->assertCreated()->assertJsonPath('data.email', 'teacher@example.com')->assertJsonPath('data.status', 'pending');
        $this->getJson($endpoint)->assertOk()->assertJsonPath('meta.total', 1);

        $token = null;
        Mail::assertSent(OrganizationInvitationMail::class, function (OrganizationInvitationMail $mail) use (&$token, $organization): bool {
            parse_str((string) parse_url($mail->invitationUrl, PHP_URL_QUERY), $query);
            $token = $query['token'] ?? null;

            return $mail->organizationName === $organization->name && $mail->role === 'teacher';
        });
        $this->assertNotNull($token);
        $this->getJson("/api/v1/auth/invitations/{$token}")->assertOk()
            ->assertJsonPath('data.organization', $organization->name)->assertJsonPath('data.email', 'teacher@example.com');

        $this->postJson('/api/v1/auth/invitations/register', [
            'token' => $token,
            'name' => 'Teacher Account',
            'password' => 'TeacherPass12345',
            'password_confirmation' => 'TeacherPass12345',
        ])->assertCreated();

        $teacherUser = User::query()->where('email', 'teacher@example.com')->firstOrFail();
        $this->assertNotNull($teacherUser->email_verified_at);
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id, 'user_id' => $teacherUser->id, 'role' => 'teacher', 'status' => 'active',
        ]);
        $this->assertDatabaseHas('teachers', ['organization_id' => $organization->id, 'user_id' => $teacherUser->id, 'employee_number' => 'T-100']);
        $this->postJson('/api/v1/auth/invitations/register', [
            'token' => $token, 'name' => 'Other', 'password' => 'TeacherPass12345', 'password_confirmation' => 'TeacherPass12345',
        ])->assertNotFound();
    }

    public function test_existing_account_can_accept_only_its_address_invitation(): void
    {
        [$admin, $organization] = $this->workspace();
        $invitee = User::factory()->create(['email' => 'management@example.com']);
        Sanctum::actingAs($admin);
        Mail::fake();
        $this->postJson("/api/v1/organizations/{$organization->id}/invitations", [
            'email' => $invitee->email, 'role' => 'management',
        ])->assertCreated();
        $token = null;
        Mail::assertSent(OrganizationInvitationMail::class, function (OrganizationInvitationMail $mail) use (&$token): bool {
            parse_str((string) parse_url($mail->invitationUrl, PHP_URL_QUERY), $query);
            $token = $query['token'] ?? null;

            return true;
        });
        $this->assertNotNull($token);

        $otherUser = User::factory()->create(['email' => 'other@example.com', 'email_verified_at' => now()]);
        Sanctum::actingAs($otherUser);
        $this->postJson("/api/v1/organizations/{$organization->id}/invitations/{$token}/accept")->assertForbidden();

        $invitee->forceFill(['email_verified_at' => now()])->save();
        Sanctum::actingAs($invitee->fresh());
        $this->postJson("/api/v1/organizations/{$organization->id}/invitations/{$token}/accept")->assertOk();
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id, 'user_id' => $invitee->id, 'role' => 'management', 'status' => 'active',
        ]);
    }

    public function test_existing_unverified_account_can_accept_with_its_password_and_invitation_token(): void
    {
        [$admin, $organization] = $this->workspace();
        $invitee = User::factory()->create(['email' => 'existing@example.com', 'password' => 'ExistingPass12345']);
        Sanctum::actingAs($admin);
        Mail::fake();
        $this->postJson("/api/v1/organizations/{$organization->id}/invitations", [
            'email' => $invitee->email, 'role' => 'teacher',
        ])->assertCreated();
        $token = null;
        Mail::assertSent(OrganizationInvitationMail::class, function (OrganizationInvitationMail $mail) use (&$token): bool {
            parse_str((string) parse_url($mail->invitationUrl, PHP_URL_QUERY), $query);
            $token = $query['token'] ?? null;

            return true;
        });

        $this->postJson('/api/v1/auth/invitations/register', [
            'token' => $token, 'name' => 'Ignored Existing Name',
            'password' => 'ExistingPass12345', 'password_confirmation' => 'ExistingPass12345',
        ])->assertOk();
        $this->assertNotNull($invitee->fresh()->email_verified_at);
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id, 'user_id' => $invitee->id, 'role' => 'teacher', 'status' => 'active',
        ]);
        $this->assertDatabaseHas('teachers', [
            'organization_id' => $organization->id, 'user_id' => $invitee->id, 'email' => $invitee->email,
        ]);
    }

    public function test_pending_invitation_can_be_revoked_and_its_token_becomes_invalid(): void
    {
        [$admin, $organization] = $this->workspace();
        Sanctum::actingAs($admin);
        Mail::fake();
        $created = $this->postJson("/api/v1/organizations/{$organization->id}/invitations", [
            'email' => 'revoke@example.com', 'role' => 'student',
        ])->assertCreated()->json('data.id');
        $token = null;
        Mail::assertSent(OrganizationInvitationMail::class, function (OrganizationInvitationMail $mail) use (&$token): bool {
            parse_str((string) parse_url($mail->invitationUrl, PHP_URL_QUERY), $query);
            $token = $query['token'] ?? null;

            return true;
        });

        $this->deleteJson("/api/v1/organizations/{$organization->id}/invitations/{$created}")->assertOk();
        $this->getJson("/api/v1/auth/invitations/{$token}")->assertNotFound();
    }

    public function test_non_admin_cannot_invite_management_and_suspended_members_cannot_be_reinvited(): void
    {
        [$management, $organization] = $this->workspace(OrganizationRole::Management);
        Sanctum::actingAs($management);
        $this->postJson("/api/v1/organizations/{$organization->id}/invitations", [
            'email' => 'manager2@example.com', 'role' => 'management',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');

        $suspended = User::factory()->create(['email' => 'suspended@example.com']);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $suspended->id,
            'role' => OrganizationRole::Teacher,
            'status' => MembershipStatus::Suspended,
        ]);
        $this->postJson("/api/v1/organizations/{$organization->id}/invitations", [
            'email' => $suspended->email, 'role' => 'teacher',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_expired_invitation_cannot_be_accepted(): void
    {
        [$admin, $organization] = $this->workspace();
        Sanctum::actingAs($admin);
        Mail::fake();
        $this->postJson("/api/v1/organizations/{$organization->id}/invitations", [
            'email' => 'expired@example.com', 'role' => 'teacher',
        ])->assertCreated();
        $invitation = OrganizationInvitation::query()->firstOrFail();
        $token = Str::random(64);
        $invitation->update(['token_hash' => hash('sha256', $token), 'expires_at' => now()->subMinute()]);

        $this->getJson("/api/v1/auth/invitations/{$token}")->assertNotFound();
    }

    private function workspace(OrganizationRole $role = OrganizationRole::Admin): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Northstar Academy', 'slug' => 'northstar-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        return [$user, $organization];
    }
}
