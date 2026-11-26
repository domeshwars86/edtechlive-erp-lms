<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformRoleCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowlisted_operator_gets_fixed_roles_and_aggregate_membership_counts(): void
    {
        Config::set('services.platform.admin_emails', ['operator@example.com']);
        $organization = Organization::query()->create([
            'name' => 'Role Catalog Academy',
            'slug' => 'role-catalog-academy',
            'type' => OrganizationType::School,
            'status' => 'active',
        ]);
        $admin = User::factory()->create();
        $management = User::factory()->create();
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $admin->id,
            'role' => OrganizationRole::Admin, 'status' => MembershipStatus::Active,
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $management->id,
            'role' => OrganizationRole::Management, 'status' => MembershipStatus::Suspended,
        ]);
        Sanctum::actingAs(User::factory()->create(['email' => 'operator@example.com']));

        $this->getJson('/api/v1/platform/roles')
            ->assertOk()
            ->assertJsonPath('data.platform_role.key', 'super_admin')
            ->assertJsonPath('data.organization_roles.0.key', 'admin')
            ->assertJsonPath('data.organization_roles.0.active_memberships', 1)
            ->assertJsonPath('data.organization_roles.1.key', 'management')
            ->assertJsonPath('data.organization_roles.1.suspended_memberships', 1)
            ->assertJsonPath('data.access_model.editable_here', false);
    }

    public function test_role_catalog_is_denied_to_non_allowlisted_users(): void
    {
        Config::set('services.platform.admin_emails', ['operator@example.com']);
        Sanctum::actingAs(User::factory()->create(['email' => 'school-admin@example.com']));

        $this->getJson('/api/v1/platform/roles')->assertForbidden();
    }
}
