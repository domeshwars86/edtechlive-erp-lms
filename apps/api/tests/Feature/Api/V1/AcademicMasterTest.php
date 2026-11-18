<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\AcademicSession;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AcademicMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_tenant_scoped_academic_masters(): void
    {
        [$user, $organization] = $this->workspace();
        Sanctum::actingAs($user);

        $sessionResponse = $this->postJson("/api/v1/organizations/{$organization->id}/academic-sessions", [
            'name' => '2026–2027', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31', 'is_current' => true,
        ])->assertCreated()->assertJsonPath('data.is_current', true);
        $sessionId = $sessionResponse->json('data.id');

        $classResponse = $this->postJson("/api/v1/organizations/{$organization->id}/classes", [
            'name' => 'Grade 8', 'code' => 'G8',
        ])->assertCreated()->assertJsonPath('data.organization_id', $organization->id);
        $classId = $classResponse->json('data.id');

        $this->postJson("/api/v1/organizations/{$organization->id}/classes/{$classId}/sections", [
            'name' => 'A', 'academic_session_id' => $sessionId, 'capacity' => 32,
        ])->assertCreated()->assertJsonPath('data.name', 'A');

        $this->postJson("/api/v1/organizations/{$organization->id}/subjects", [
            'name' => 'Mathematics', 'code' => 'MATH',
        ])->assertCreated()->assertJsonPath('data.code', 'MATH');

        $this->getJson("/api/v1/organizations/{$organization->id}/academic-sessions")
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/organizations/{$organization->id}/classes/{$classId}/sections")
            ->assertOk()->assertJsonPath('data.0.academic_session.id', $sessionId);
        $this->getJson("/api/v1/organizations/{$organization->id}/subjects")
            ->assertOk()->assertJsonPath('data.0.name', 'Mathematics');
    }

    public function test_current_academic_session_is_unique_per_organization(): void
    {
        [$user, $organization] = $this->workspace();
        $first = AcademicSession::query()->create([
            'organization_id' => $organization->id,
            'name' => '2025–2026',
            'starts_on' => '2025-06-01',
            'ends_on' => '2026-05-31',
            'is_current' => true,
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/organizations/{$organization->id}/academic-sessions", [
            'name' => '2026–2027', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31', 'is_current' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('academic_sessions', ['id' => $first->id, 'is_current' => false]);
        $this->assertDatabaseCount('academic_sessions', 2);
    }

    public function test_members_cannot_read_or_write_another_tenant_masters(): void
    {
        [$user] = $this->workspace();
        $otherOrganization = $this->organization('Other tenant');
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/organizations/{$otherOrganization->id}/subjects")->assertNotFound();
        $this->postJson("/api/v1/organizations/{$otherOrganization->id}/subjects", ['name' => 'Science'])->assertNotFound();
    }

    public function test_student_cannot_create_organization_masters(): void
    {
        [$user, $organization] = $this->workspace(OrganizationRole::Student);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/organizations/{$organization->id}/classes", ['name' => 'Grade 8'])->assertForbidden();
    }

    private function workspace(OrganizationRole $role = OrganizationRole::Admin): array
    {
        $user = User::factory()->create();
        $organization = $this->organization('Northstar Learning Academy');
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        return [$user, $organization];
    }

    private function organization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name,
            'slug' => str()->slug($name).'-'.str()->random(6),
            'type' => OrganizationType::School,
            'status' => 'active',
            'timezone' => 'Asia/Kolkata',
        ]);
    }
}
