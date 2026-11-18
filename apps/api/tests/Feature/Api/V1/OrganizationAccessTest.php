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

class OrganizationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_list_is_scoped_to_the_authenticated_users_active_memberships(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownOrganization = $this->organization('Northstar Academy', 'northstar-academy');
        $otherOrganization = $this->organization('Westfield School', 'westfield-school');
        $invitedOrganization = $this->organization('Pending Academy', 'pending-academy');
        $suspendedOrganization = $this->organization('Suspended Academy', 'suspended-academy', 'suspended');

        $this->membership($user, $ownOrganization, MembershipStatus::Active);
        $this->membership($otherUser, $otherOrganization, MembershipStatus::Active);
        $this->membership($user, $invitedOrganization, MembershipStatus::Invited);
        $this->membership($user, $suspendedOrganization, MembershipStatus::Active);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/organizations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.organization.slug', 'northstar-academy')
            ->assertJsonPath('data.0.role', 'admin')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissing(['slug' => 'westfield-school'])
            ->assertJsonMissing(['slug' => 'pending-academy'])
            ->assertJsonMissing(['slug' => 'suspended-academy']);
    }

    public function test_user_can_save_a_preferred_active_organization_and_restore_it_from_the_list(): void
    {
        $user = User::factory()->create();
        $organization = $this->organization('Northstar Academy', 'northstar-academy');
        $this->membership($user, $organization, MembershipStatus::Active);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/me/organization', ['organization_id' => $organization->id])
            ->assertOk()
            ->assertJsonPath('data.organization.id', $organization->id)
            ->assertJsonPath('data.role', 'admin');

        $this->getJson('/api/v1/organizations')
            ->assertOk()
            ->assertJsonPath('preferred_organization_id', $organization->id);
    }

    public function test_user_cannot_save_an_organization_without_an_active_membership(): void
    {
        $user = User::factory()->create();
        $otherOrganization = $this->organization('Westfield School', 'westfield-school');
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/me/organization', ['organization_id' => $otherOrganization->id])
            ->assertNotFound();

        $this->assertNull($user->fresh()->preferred_organization_id);
    }

    public function test_organization_settings_can_be_updated_by_an_admin_with_timezone_validation(): void
    {
        $admin = User::factory()->create();
        $manager = User::factory()->create();
        $organization = $this->organization('Northstar Academy', 'northstar-academy');
        $this->membership($admin, $organization, MembershipStatus::Active);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $manager->id,
            'role' => OrganizationRole::Management, 'status' => MembershipStatus::Active,
        ]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/organizations/{$organization->id}/settings", [
            'name' => 'Northstar Academy West', 'timezone' => 'Asia/Kolkata',
        ])->assertOk()->assertJsonPath('data.name', 'Northstar Academy West')
            ->assertJsonPath('data.timezone', 'Asia/Kolkata');
        $this->patchJson("/api/v1/organizations/{$organization->id}/settings", ['timezone' => 'Not/A_Timezone'])
            ->assertUnprocessable()->assertJsonValidationErrors('timezone');
        $this->patchJson("/api/v1/organizations/{$organization->id}/settings", [])->assertUnprocessable();

        Sanctum::actingAs($manager);
        $this->patchJson("/api/v1/organizations/{$organization->id}/settings", ['name' => 'Manager change'])
            ->assertForbidden();
    }

    public function test_me_route_requires_authentication_and_does_not_expose_password(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonMissingPath('data.password');
    }

    private function organization(string $name, string $slug, string $status = 'active'): Organization
    {
        return Organization::query()->create([
            'name' => $name,
            'slug' => $slug,
            'type' => OrganizationType::School,
            'status' => $status,
            'timezone' => 'Asia/Kolkata',
        ]);
    }

    private function membership(User $user, Organization $organization, MembershipStatus $status): void
    {
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => OrganizationRole::Admin,
            'status' => $status,
        ]);
    }
}
