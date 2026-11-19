<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
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

        $this->getJson($base)->assertForbidden();
        $this->patchJson("{$base}/{$membership->id}", ['role' => 'student'])->assertForbidden();
        $this->assertDatabaseCount('organization_access_audits', 0);
    }

    private function workspace(): array
    {
        $admin = User::factory()->create();
        $organization = $this->organization('Northstar Academy');
        $membership = $this->membership($organization, $admin, OrganizationRole::Admin);

        return [$admin, $organization, $membership];
    }

    private function membership(Organization $organization, User $user, OrganizationRole $role): OrganizationMembership
    {
        return OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id,
            'role' => $role, 'status' => MembershipStatus::Active, 'accepted_at' => now(),
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
