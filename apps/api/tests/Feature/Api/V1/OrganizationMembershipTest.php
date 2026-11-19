<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\OrganizationPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_search_members_change_roles_suspend_access_and_review_audit(): void
    {
        [$admin, $organization] = $this->workspace();
        $teacher = User::factory()->create(['name' => 'Teacher One', 'email' => 'teacher@example.test']);
        $membership = $this->membership($organization, $teacher, OrganizationRole::Teacher);
        Sanctum::actingAs($admin);
        $base = "/api/v1/organizations/{$organization->id}";

        $this->getJson("{$base}/memberships?search=teacher&role=teacher&status=active")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.user.email', 'teacher@example.test');
        $this->patchJson("{$base}/memberships/{$membership->id}", ['role' => 'student', 'status' => 'suspended'])
            ->assertOk()->assertJsonPath('data.role', 'student')->assertJsonPath('data.status', 'suspended');
        $this->assertDatabaseHas('organization_access_audits', [
            'organization_id' => $organization->id, 'membership_id' => $membership->id,
            'actor_user_id' => $admin->id, 'subject_user_id' => $teacher->id,
            'previous_role' => 'teacher', 'role' => 'student',
            'previous_status' => 'active', 'status' => 'suspended',
        ]);
        $this->getJson("{$base}/memberships?status=suspended")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.role', 'student');
        $this->getJson("{$base}/membership-access-audit")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.actor', $admin->name)
            ->assertJsonPath('data.0.subject', $teacher->name);
        $this->patchJson("{$base}/memberships/{$membership->id}", ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_role_changes_reject_self_updates_and_cross_tenant_memberships(): void
    {
        [$admin, $organization, $adminMembership] = $this->workspace();
        $otherOrganization = $this->organization('Other organization');
        $otherUser = User::factory()->create();
        $foreignMembership = $this->membership($otherOrganization, $otherUser, OrganizationRole::Teacher);
        Sanctum::actingAs($admin);
        $base = "/api/v1/organizations/{$organization->id}/memberships";

        $this->patchJson("{$base}/{$adminMembership->id}", ['role' => 'teacher'])->assertStatus(409);
        $this->patchJson("{$base}/{$foreignMembership->id}", ['role' => 'student'])->assertNotFound();
        $this->patchJson("{$base}/999999", ['status' => 'invited'])->assertUnprocessable();
    }

    public function test_management_and_teachers_cannot_change_organization_roles(): void
    {
        [$admin, $organization] = $this->workspace();
        $teacher = User::factory()->create();
        $membership = $this->membership($organization, $teacher, OrganizationRole::Teacher);
        $manager = User::factory()->create();
        $this->membership($organization, $manager, OrganizationRole::Management);
        Sanctum::actingAs($manager);
        $base = "/api/v1/organizations/{$organization->id}/memberships";

        $this->getJson($base)->assertOk()->assertJsonPath('can_manage_memberships', false);
        $this->patchJson("{$base}/{$membership->id}", ['role' => 'student'])->assertForbidden();
        $teacher = User::factory()->create();
        $this->membership($organization, $teacher, OrganizationRole::Teacher);
        Sanctum::actingAs($teacher);
        $this->getJson($base)->assertForbidden();
        $this->patchJson("{$base}/{$membership->id}", ['role' => 'student'])->assertForbidden();
        $this->assertDatabaseCount('organization_access_audits', 0);
    }

    public function test_admin_can_grant_and_revoke_catalog_permissions_with_audited_metadata(): void
    {
        [$admin, $organization] = $this->workspace();
        $manager = $this->membership($organization, User::factory()->create(), OrganizationRole::Management);
        Sanctum::actingAs($admin);
        $base = "/api/v1/organizations/{$organization->id}";

        $this->getJson("{$base}/memberships?role=management")
            ->assertOk()
            ->assertJsonPath('can_manage_permissions', true)
            ->assertJsonPath('can_manage_memberships', true)
            ->assertJsonPath('data.0.permissions.effective.0', 'organization.memberships.read')
            ->assertJsonPath('data.0.permissions.editable', true)
            ->assertJsonPath('permission_catalog.0.key', 'organization.memberships.read');

        $this->patchJson("{$base}/memberships/{$manager->id}", [
            'permissions' => ['organization.memberships.manage', 'organization.memberships.audit'],
        ])->assertOk()
            ->assertJsonPath('data.permissions.overrides.0', 'organization.memberships.audit')
            ->assertJsonPath('data.permissions.overrides.1', 'organization.memberships.manage')
            ->assertJsonPath('data.permissions.overrides.2', 'organization.memberships.read');

        $this->getJson("{$base}/membership-access-audit")
            ->assertOk()
            ->assertJsonPath('data.0.event_type', 'permissions')
            ->assertJsonPath('data.0.previous_permissions', [])
            ->assertJsonPath('data.0.permissions.0', 'organization.memberships.audit');
        $this->assertDatabaseHas('organization_access_audits', [
            'organization_id' => $organization->id,
            'membership_id' => $manager->id,
            'actor_user_id' => $admin->id,
            'event_type' => 'permissions',
        ]);

        $this->patchJson("{$base}/memberships/{$manager->id}", ['permissions' => []])
            ->assertOk()
            ->assertJsonPath('data.permissions.overrides', [])
            ->assertJsonPath('data.permissions.effective', ['organization.memberships.read']);
        $this->getJson("{$base}/membership-access-audit")
            ->assertOk()->assertJsonPath('data.0.previous_permissions.0', 'organization.memberships.audit')
            ->assertJsonPath('data.0.permissions', []);
    }

    public function test_management_has_read_only_membership_access_by_default_and_delegated_capabilities_are_enforced(): void
    {
        [$admin, $organization] = $this->workspace();
        $manager = User::factory()->create();
        $managerMembership = $this->membership($organization, $manager, OrganizationRole::Management);
        $teacherMembership = $this->membership($organization, User::factory()->create(), OrganizationRole::Teacher);
        $base = "/api/v1/organizations/{$organization->id}";

        Sanctum::actingAs($manager);
        $this->getJson("{$base}/memberships?role=management")
            ->assertOk()
            ->assertJsonPath('can_manage_memberships', false)
            ->assertJsonPath('can_manage_permissions', false)
            ->assertJsonPath('data.0.permissions.effective.0', 'organization.memberships.read');
        $this->getJson("{$base}/membership-access-audit")->assertForbidden();
        $this->patchJson("{$base}/memberships/{$teacherMembership->id}", ['role' => 'student'])->assertForbidden();

        Sanctum::actingAs($admin);
        $this->patchJson("{$base}/memberships/{$managerMembership->id}", [
            'permissions' => ['organization.memberships.manage', 'organization.memberships.audit'],
        ])->assertOk();

        Sanctum::actingAs($manager);
        $this->getJson("{$base}/memberships")
            ->assertOk()->assertJsonPath('can_manage_memberships', true)->assertJsonPath('can_manage_permissions', false);
        $this->getJson("{$base}/membership-access-audit")->assertOk();
        $this->patchJson("{$base}/memberships/{$teacherMembership->id}", ['role' => 'student'])
            ->assertOk()->assertJsonPath('data.role', 'student');
        $this->patchJson("{$base}/memberships/{$teacherMembership->id}", ['permissions' => []])->assertForbidden();
        $this->patchJson("{$base}/memberships/{$teacherMembership->id}", ['role' => 'admin'])->assertForbidden();
        $this->patchJson("{$base}/memberships/{$teacherMembership->id}", ['role' => 'management'])->assertForbidden();
        $this->assertDatabaseHas('organization_memberships', [
            'id' => $managerMembership->id, 'organization_id' => $organization->id, 'role' => 'management',
        ]);
    }

    public function test_permission_grants_reject_invalid_keys_self_cross_tenant_suspended_and_privileged_targets(): void
    {
        [$admin, $organization, $adminMembership] = $this->workspace();
        $target = $this->membership($organization, User::factory()->create(), OrganizationRole::Teacher);
        $suspended = $this->membership($organization, User::factory()->create(), OrganizationRole::Teacher, MembershipStatus::Suspended);
        $privileged = $this->membership($organization, User::factory()->create(), OrganizationRole::IndependentEducator);
        $foreignOrganization = $this->organization('Foreign workspace');
        $foreign = $this->membership($foreignOrganization, User::factory()->create(), OrganizationRole::Teacher);
        Sanctum::actingAs($admin);
        $base = "/api/v1/organizations/{$organization->id}/memberships";
        $grant = ['permissions' => ['organization.memberships.audit']];

        $this->patchJson("{$base}/{$target->id}", ['permissions' => ['root.superuser']])->assertUnprocessable();
        $this->patchJson("{$base}/{$adminMembership->id}", ['permissions' => []])->assertStatus(409);
        $this->patchJson("{$base}/{$foreign->id}", $grant)->assertNotFound();
        $this->patchJson("{$base}/{$suspended->id}", $grant)->assertStatus(409);
        $this->patchJson("{$base}/{$target->id}", [
            'permissions' => ['organization.memberships.audit'], 'status' => 'suspended',
        ])->assertStatus(409);
        $this->patchJson("{$base}/{$privileged->id}", $grant)->assertStatus(409);

        $this->assertDatabaseCount('organization_membership_permissions', 0);
        $this->assertDatabaseCount('organization_access_audits', 0);
    }

    public function test_suspended_actor_and_non_privileged_grantor_cannot_grant_permissions(): void
    {
        [$admin, $organization] = $this->workspace();
        $target = $this->membership($organization, User::factory()->create(), OrganizationRole::Teacher);
        $manager = User::factory()->create();
        $managerMembership = $this->membership($organization, $manager, OrganizationRole::Management);
        $base = "/api/v1/organizations/{$organization->id}";

        Sanctum::actingAs($admin);
        $this->patchJson("{$base}/memberships/{$managerMembership->id}", [
            'permissions' => ['organization.memberships.manage'],
        ])->assertOk();

        Sanctum::actingAs($manager);
        $this->patchJson("{$base}/memberships/{$target->id}", ['permissions' => ['organization.memberships.audit']])->assertForbidden();

        Sanctum::actingAs($admin);
        $this->patchJson("{$base}/memberships/{$managerMembership->id}", ['status' => 'suspended'])->assertOk();
        Sanctum::actingAs($manager);
        $this->getJson("{$base}/memberships")->assertNotFound();
        $this->patchJson("{$base}/memberships/{$target->id}", ['role' => 'student'])->assertNotFound();
    }

    public function test_independent_educator_keeps_privileged_defaults_and_admin_role_changes_clear_old_overrides(): void
    {
        [$admin, $organization] = $this->workspace();
        $independent = $this->membership($organization, User::factory()->create(), OrganizationRole::IndependentEducator);
        $target = $this->membership($organization, User::factory()->create(), OrganizationRole::Teacher);
        Sanctum::actingAs($admin);
        $base = "/api/v1/organizations/{$organization->id}";

        $this->getJson("{$base}/memberships")
            ->assertOk()->assertJsonPath('data.0.permissions.effective.0', 'organization.memberships.read');
        $this->assertSame([
            'organization.memberships.read',
            'organization.memberships.manage',
            'organization.memberships.audit',
        ], app(OrganizationPermissions::class)->effective($independent));

        $this->patchJson("{$base}/memberships/{$target->id}", ['permissions' => ['organization.memberships.audit']])->assertOk();
        $this->patchJson("{$base}/memberships/{$target->id}", ['role' => 'admin'])->assertOk();
        $this->assertDatabaseMissing('organization_membership_permissions', [
            'organization_membership_id' => $target->id,
            'permission_key' => 'organization.memberships.audit',
        ]);
    }

    private function workspace(): array
    {
        $admin = User::factory()->create();
        $organization = $this->organization('Northstar Academy');
        $membership = $this->membership($organization, $admin, OrganizationRole::Admin);

        return [$admin, $organization, $membership];
    }

    private function membership(Organization $organization, User $user, OrganizationRole $role, MembershipStatus $status = MembershipStatus::Active): OrganizationMembership
    {
        return OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id,
            'role' => $role, 'status' => $status, 'accepted_at' => $status === MembershipStatus::Active ? now() : null,
        ]);
    }

    private function organization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name, 'slug' => str()->slug($name).'-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
    }
}
