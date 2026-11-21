<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PlatformAuditEvent;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowlisted_platform_operator_gets_aggregate_platform_counts(): void
    {
        Config::set('services.platform.admin_emails', ['operator@example.com']);
        $operator = User::factory()->create(['email' => 'operator@example.com']);
        User::factory()->create();
        $this->organization('Active Academy', 'active-academy', 'active');
        $this->organization('Suspended Academy', 'suspended-academy', 'suspended');
        $teacher = User::factory()->create();
        $organization = Organization::query()->where('slug', 'active-academy')->firstOrFail();
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $teacher->id,
            'role' => OrganizationRole::Teacher, 'status' => MembershipStatus::Active,
        ]);

        Sanctum::actingAs($operator);

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.is_platform_admin', true);
        $this->getJson('/api/v1/platform/overview')
            ->assertOk()
            ->assertJsonPath('data.organizations.total', 2)
            ->assertJsonPath('data.organizations.active', 1)
            ->assertJsonPath('data.organizations.suspended', 1)
            ->assertJsonPath('data.users.total', 3)
            ->assertJsonPath('data.educators', 1)
            ->assertJsonFragment(['name' => 'Active Academy'])
            ->assertJsonFragment(['name' => 'Suspended Academy']);
        $this->getJson('/api/v1/platform/organizations?status=active&q=Active&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'active-academy')
            ->assertJsonPath('data.0.active_members', 1)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_platform_overview_is_forbidden_for_non_allowlisted_users(): void
    {
        Config::set('services.platform.admin_emails', ['operator@example.com']);
        Sanctum::actingAs(User::factory()->create(['email' => 'school-admin@example.com']));

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.is_platform_admin', false);
        $this->getJson('/api/v1/platform/overview')->assertForbidden();
        $this->getJson('/api/v1/platform/organizations')->assertForbidden();
        $this->getJson('/api/v1/platform/audit-events')->assertForbidden();
        $this->getJson('/api/v1/platform/users')->assertForbidden();
        $this->patchJson('/api/v1/platform/organizations/1', ['status' => 'suspended', 'reason' => 'Policy violation review'])->assertForbidden();
    }

    public function test_allowlisted_operator_can_suspend_and_reactivate_an_organization_with_audit_history(): void
    {
        Config::set('services.platform.admin_emails', ['operator@example.com']);
        $operator = User::factory()->create(['email' => 'operator@example.com']);
        $organization = $this->organization('Audit Academy', 'audit-academy', 'active');
        $member = User::factory()->create();
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $member->id,
            'role' => OrganizationRole::Admin, 'status' => MembershipStatus::Active,
        ]);
        Sanctum::actingAs($operator);

        $this->patchJson("/api/v1/platform/organizations/{$organization->id}", [
            'status' => 'suspended', 'reason' => 'Compliance review requested',
        ])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertDatabaseHas('platform_audit_events', [
            'actor_user_id' => $operator->id,
            'organization_id' => $organization->id,
            'action' => 'organization.status_changed',
            'reason' => 'Compliance review requested',
        ]);
        $this->assertDatabaseHas('platform_audit_events', ['organization_id' => $organization->id, 'before->status' => 'active', 'after->status' => 'suspended']);
        $this->getJson('/api/v1/platform/audit-events?per_page=1')
            ->assertOk()->assertJsonPath('data.0.organization_name', 'Audit Academy')
            ->assertJsonPath('data.0.actor.email', 'operator@example.com')
            ->assertJsonPath('data.0.before.status', 'active')
            ->assertJsonPath('data.0.after.status', 'suspended')
            ->assertJsonMissingPath('data.0.actor.password');

        Sanctum::actingAs($member);
        $this->getJson('/api/v1/organizations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/organizations/{$organization->id}")->assertNotFound();

        Sanctum::actingAs($operator);
        $this->patchJson("/api/v1/platform/organizations/{$organization->id}", [
            'status' => 'active', 'reason' => 'Compliance review completed',
        ])->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame(2, PlatformAuditEvent::query()->where('organization_id', $organization->id)->count());
        $this->assertSame('active', $organization->fresh()->status);
        Sanctum::actingAs($member);
        $this->getJson('/api/v1/organizations')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_organization_status_change_requires_an_explanation_and_supported_status(): void
    {
        Config::set('services.platform.admin_emails', ['operator@example.com']);
        $operator = User::factory()->create(['email' => 'operator@example.com']);
        $organization = $this->organization('Validated Academy', 'validated-academy', 'active');
        Sanctum::actingAs($operator);

        $this->patchJson("/api/v1/platform/organizations/{$organization->id}", ['status' => 'deleted', 'reason' => 'Short'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'reason']);
        $this->assertSame('active', $organization->fresh()->status);
        $this->assertDatabaseCount('platform_audit_events', 0);
    }

    public function test_allowlisted_operator_can_filter_a_paginated_user_directory(): void
    {
        Config::set('services.platform.admin_emails', ['operator@example.com']);
        $operator = User::factory()->create(['email' => 'operator@example.com']);
        $teacher = User::factory()->create(['name' => 'School Teacher', 'email' => 'teacher@example.com']);
        $pending = User::factory()->create([
            'name' => 'Pending Parent', 'email' => 'pending-parent@example.com', 'email_verified_at' => null,
        ]);
        $organization = $this->organization('Directory School', 'directory-school', 'active');
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $teacher->id,
            'role' => OrganizationRole::Teacher, 'status' => MembershipStatus::Active,
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $pending->id,
            'role' => OrganizationRole::Parent, 'status' => MembershipStatus::Active,
        ]);

        Sanctum::actingAs($operator);

        $this->getJson('/api/v1/platform/users?q=SCHOOL&role=teacher&email_verified=true&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'teacher@example.com')
            ->assertJsonPath('data.0.active_organizations', 1)
            ->assertJsonPath('data.0.platform_status', 'active')
            ->assertJsonPath('data.0.status_change_allowed', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('data.0.password');
        $this->getJson('/api/v1/platform/users?email_verified=false')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'pending-parent@example.com');
    }

    public function test_platform_operator_can_review_and_change_membership_roles_with_audit_and_admin_protection(): void
    {
        Config::set('services.platform.admin_emails', ['operator@example.com']);
        $operator = User::factory()->create(['email' => 'operator@example.com']);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $teacher = User::factory()->create(['email' => 'teacher@example.com']);
        $organization = $this->organization('Role Review Academy', 'role-review-academy', 'active');
        $adminMembership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $admin->id,
            'role' => OrganizationRole::Admin, 'status' => MembershipStatus::Active,
        ]);
        $teacherMembership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $teacher->id,
            'role' => OrganizationRole::Teacher, 'status' => MembershipStatus::Active,
        ]);
        $teacherMembership->permissionOverrides()->create([
            'permission_key' => 'organization.memberships.read', 'granted_by' => $operator->id,
        ]);
        Sanctum::actingAs($operator);

        $this->getJson("/api/v1/platform/users/{$teacher->id}/memberships")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.organization', 'Role Review Academy')
            ->assertJsonPath('data.0.role', 'teacher')
            ->assertJsonMissingPath('data.0.user.password');
        $this->patchJson("/api/v1/platform/users/{$teacher->id}/memberships/{$adminMembership->id}", [
            'role' => 'student', 'reason' => 'Attempted cross-account membership edit',
        ])->assertNotFound();
        $this->patchJson("/api/v1/platform/users/{$teacher->id}/memberships/{$teacherMembership->id}", [
            'role' => 'student', 'reason' => 'Corrected organization role after review',
        ])->assertOk()->assertJsonPath('data.role', 'student');
        $this->assertSame('student', $teacherMembership->fresh()->role->value);
        $this->assertDatabaseHas('organization_access_audits', [
            'organization_id' => $organization->id,
            'membership_id' => $teacherMembership->id,
            'actor_user_id' => $operator->id,
            'subject_user_id' => $teacher->id,
            'previous_role' => 'teacher',
            'role' => 'student',
        ]);
        $this->assertDatabaseHas('platform_audit_events', [
            'actor_user_id' => $operator->id,
            'organization_id' => $organization->id,
            'action' => 'user.membership_role_changed',
            'reason' => 'Corrected organization role after review',
            'before->role' => 'teacher',
            'after->role' => 'student',
        ]);
        $this->patchJson("/api/v1/platform/users/{$admin->id}/memberships/{$adminMembership->id}", [
            'role' => 'teacher', 'reason' => 'Corrected organization role after review',
        ])->assertStatus(409);
        $this->assertSame('admin', $adminMembership->fresh()->role->value);
        $this->patchJson("/api/v1/platform/users/{$teacher->id}/memberships/{$teacherMembership->id}", [
            'role' => 'independent_educator', 'reason' => 'Promoted after organization review',
        ])->assertOk()->assertJsonPath('data.role', 'independent_educator');
        $this->assertDatabaseMissing('organization_membership_permissions', [
            'organization_membership_id' => $teacherMembership->id,
            'permission_key' => 'organization.memberships.read',
        ]);
        $this->assertDatabaseHas('organization_access_audits', [
            'organization_id' => $organization->id,
            'membership_id' => $teacherMembership->id,
            'event_type' => 'membership_and_permissions',
            'previous_permissions' => json_encode(['organization.memberships.read']),
            'permissions' => json_encode([]),
        ]);

        Sanctum::actingAs($teacher->fresh());
        $this->getJson("/api/v1/platform/users/{$teacher->id}/memberships")->assertForbidden();
    }

    public function test_platform_membership_role_change_rejects_bad_reasons_and_protected_operator_targets(): void
    {
        Config::set('services.platform.admin_emails', ['operator@example.com', 'protected@example.com']);
        $operator = User::factory()->create(['email' => 'operator@example.com']);
        $protectedOperator = User::factory()->create(['email' => 'protected@example.com']);
        $member = User::factory()->create();
        $organization = $this->organization('Protected Role Academy', 'protected-role-academy', 'active');
        $protectedMembership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $protectedOperator->id,
            'role' => OrganizationRole::Teacher, 'status' => MembershipStatus::Active,
        ]);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $member->id,
            'role' => OrganizationRole::Teacher, 'status' => MembershipStatus::Active,
        ]);
        Sanctum::actingAs($operator);

        $this->getJson("/api/v1/platform/users/{$protectedOperator->id}/memberships")->assertStatus(409);
        $this->patchJson("/api/v1/platform/users/{$protectedOperator->id}/memberships/{$protectedMembership->id}", [
            'role' => 'student', 'reason' => 'Protect the allowlisted operator',
        ])->assertStatus(409);
        $this->patchJson("/api/v1/platform/users/{$member->id}/memberships/{$membership->id}", [
            'role' => 'not-a-role', 'reason' => 'Short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['role', 'reason']);
        $this->assertSame('teacher', $membership->fresh()->role->value);
        $this->assertDatabaseCount('platform_audit_events', 0);
    }

    public function test_platform_operator_can_reasonably_suspend_and_restore_user_accounts(): void
    {
        Config::set('services.platform.admin_emails', ['operator@example.com', 'second-operator@example.com']);
        $operator = User::factory()->create(['email' => 'operator@example.com']);
        $secondOperator = User::factory()->create(['email' => 'second-operator@example.com']);
        $member = User::factory()->create(['email' => 'learner@example.com']);
        Sanctum::actingAs($operator);

        $this->patchJson("/api/v1/platform/users/{$member->id}", ['status' => 'suspended', 'reason' => 'Repeated account abuse reports'])
            ->assertOk()->assertJsonPath('data.platform_status', 'suspended');
        $this->assertDatabaseHas('platform_audit_events', [
            'actor_user_id' => $operator->id,
            'action' => 'user.status_changed',
            'organization_name' => 'Platform accounts',
            'reason' => 'Repeated account abuse reports',
        ]);
        $this->assertDatabaseHas('platform_audit_events', [
            'action' => 'user.status_changed',
            'before->status' => 'active',
            'after->status' => 'suspended',
        ]);
        $this->getJson('/api/v1/platform/users?status=suspended')->assertOk()
            ->assertJsonPath('data.0.email', 'learner@example.com');

        Sanctum::actingAs($member->fresh());
        $this->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('status', 'account_suspended');
        $this->postJson('/api/v1/auth/logout')->assertOk();

        Sanctum::actingAs($operator);
        $this->patchJson("/api/v1/platform/users/{$member->id}", ['status' => 'active', 'reason' => '        '])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson("/api/v1/platform/users/{$operator->id}", ['status' => 'suspended', 'reason' => 'Self suspension request'])
            ->assertStatus(409);
        $this->patchJson("/api/v1/platform/users/{$secondOperator->id}", ['status' => 'suspended', 'reason' => 'Operator suspension request'])
            ->assertStatus(409);
        $this->patchJson("/api/v1/platform/users/{$member->id}", ['status' => 'active', 'reason' => 'Appeal review completed'])
            ->assertOk()->assertJsonPath('data.platform_status', 'active');
        $this->assertDatabaseCount('platform_audit_events', 2);

        Sanctum::actingAs($member->fresh());
        $this->getJson('/api/v1/me')->assertOk();
    }

    public function test_allowlisted_operator_can_change_account_identity_with_audit_and_new_email_verification(): void
    {
        Config::set('services.platform.admin_emails', ['operator@example.com', 'protected@example.com']);
        Config::set('app.env', 'local');
        Notification::fake();
        $operator = User::factory()->create(['email' => 'operator@example.com']);
        $member = User::factory()->create(['name' => 'Previous Name', 'email' => 'old@example.com']);
        $protectedOperator = User::factory()->create(['email' => 'protected@example.com']);
        $other = User::factory()->create(['email' => 'already-used@example.com']);
        Sanctum::actingAs($operator);

        $response = $this->patchJson("/api/v1/platform/users/{$member->id}/profile", [
            'name' => 'Updated Name',
            'email' => 'NEW@example.com',
            'reason' => 'Corrected identity details',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonPath('data.email_verified_at', null)
            ->assertJsonPath('email_changed', true);

        $verificationUrl = $response->json('verification_url');
        $this->assertIsString($verificationUrl);
        $this->assertStringContainsString('/api/v1/auth/verify-email/', $verificationUrl);
        $this->assertDatabaseHas('platform_audit_events', [
            'actor_user_id' => $operator->id,
            'action' => 'user.identity_changed',
            'reason' => 'Corrected identity details',
            'before->email' => 'old@example.com',
            'after->email' => 'new@example.com',
        ]);
        Notification::assertSentTo($member, VerifyEmail::class);
        $verificationPath = parse_url($verificationUrl, PHP_URL_PATH).'?'.parse_url($verificationUrl, PHP_URL_QUERY);
        $this->getJson($verificationPath)->assertOk()->assertJsonPath('status', 'verified');
        $this->assertNotNull($member->fresh()->email_verified_at);

        $this->patchJson("/api/v1/platform/users/{$member->id}/profile", [
            'name' => 'Updated Name', 'email' => $other->email, 'reason' => 'Corrected duplicate address',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->patchJson("/api/v1/platform/users/{$protectedOperator->id}/profile", [
            'name' => 'Protected Operator', 'email' => $protectedOperator->email, 'reason' => 'Attempted operator update',
        ])->assertStatus(409);
        $this->patchJson("/api/v1/platform/users/{$member->id}/profile", [
            'name' => 'Updated Name', 'email' => $member->email, 'reason' => '     ',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertDatabaseCount('platform_audit_events', 1);
    }

    public function test_allowlisted_operator_can_request_a_recipient_chosen_password_reset(): void
    {
        Config::set('services.platform.admin_emails', ['operator@example.com', 'protected@example.com']);
        Config::set('app.env', 'local');
        $operator = User::factory()->create(['email' => 'operator@example.com']);
        $member = User::factory()->create(['email' => 'reset-me@example.com', 'password' => 'OriginalPass123']);
        $protectedOperator = User::factory()->create(['email' => 'protected@example.com']);
        Sanctum::actingAs($operator);

        $response = $this->postJson("/api/v1/platform/users/{$member->id}/password-reset", [
            'reason' => 'Account owner requested recovery',
        ])->assertAccepted()
            ->assertJsonPath('message', 'If the account can receive a password reset, instructions have been sent.');
        $resetUrl = $response->json('reset_url');
        $this->assertIsString($resetUrl);
        parse_str((string) parse_url($resetUrl, PHP_URL_QUERY), $query);
        $this->assertSame($member->email, $query['email'] ?? null);
        $this->assertIsString($query['token'] ?? null);
        $this->assertDatabaseHas('platform_audit_events', [
            'actor_user_id' => $operator->id,
            'action' => 'user.password_reset_requested',
            'reason' => 'Account owner requested recovery',
            'after->status' => 'reset_link_sent',
        ]);
        $this->assertStringNotContainsString($query['token'], (string) PlatformAuditEvent::query()->firstOrFail()->getRawOriginal('after'));

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $member->email,
            'token' => $query['token'],
            'password' => 'UpdatedPassword123',
            'password_confirmation' => 'UpdatedPassword123',
        ])->assertOk();
        $this->assertTrue(Hash::check('UpdatedPassword123', $member->fresh()->password));
        $this->postJson("/api/v1/platform/users/{$protectedOperator->id}/password-reset", [
            'reason' => 'Operator password request',
        ])->assertStatus(409);
        $this->assertDatabaseCount('platform_audit_events', 1);
    }

    private function organization(string $name, string $slug, string $status): Organization
    {
        return Organization::query()->create([
            'name' => $name, 'slug' => $slug, 'type' => OrganizationType::School,
            'status' => $status, 'timezone' => 'Asia/Kolkata',
        ]);
    }
}
