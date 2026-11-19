<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PlatformAuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
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

    private function organization(string $name, string $slug, string $status): Organization
    {
        return Organization::query()->create([
            'name' => $name, 'slug' => $slug, 'type' => OrganizationType::School,
            'status' => $status, 'timezone' => 'Asia/Kolkata',
        ]);
    }
}
