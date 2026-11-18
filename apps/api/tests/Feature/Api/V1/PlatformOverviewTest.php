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
        $this->getJson('/api/v1/platform/users')->assertForbidden();
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
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('data.0.password');
        $this->getJson('/api/v1/platform/users?email_verified=false')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'pending-parent@example.com');
    }

    private function organization(string $name, string $slug, string $status): Organization
    {
        return Organization::query()->create([
            'name' => $name, 'slug' => $slug, 'type' => OrganizationType::School,
            'status' => $status, 'timezone' => 'Asia/Kolkata',
        ]);
    }
}
