<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\AcademicClass;
use App\Models\AcademicSession;
use App\Models\Organization;
use App\Models\OrganizationBranch;
use App\Models\OrganizationMembership;
use App\Models\Student;
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

    public function test_managers_can_create_search_filter_edit_and_deactivate_batches(): void
    {
        [$user, $organization] = $this->workspace(OrganizationRole::Management);
        Sanctum::actingAs($user);
        $base = "/api/v1/organizations/{$organization->id}";
        $sessionId = $this->postJson("{$base}/academic-sessions", [
            'name' => '2026–2027', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31',
        ])->assertCreated()->json('data.id');
        $classId = $this->postJson("{$base}/classes", ['name' => 'Grade 8'])->assertCreated()->json('data.id');
        $sectionId = $this->postJson("{$base}/classes/{$classId}/sections", [
            'name' => 'A', 'academic_session_id' => $sessionId,
        ])->assertCreated()->json('data.id');

        $batchId = $this->postJson("{$base}/batches", [
            'academic_session_id' => $sessionId, 'academic_class_id' => $classId,
            'academic_section_id' => $sectionId, 'name' => 'Morning batch', 'code' => 'MORN-8',
        ])->assertCreated()->assertJsonPath('data.academic_class.name', 'Grade 8')
            ->assertJsonPath('data.academic_section.name', 'A')->json('data.id');
        $this->postJson("{$base}/batches", [
            'academic_session_id' => $sessionId, 'academic_class_id' => $classId,
            'name' => 'Evening batch', 'code' => 'EVE-8',
        ])->assertCreated();

        $this->getJson("{$base}/batches?search=mOrN&academic_session_id={$sessionId}&academic_class_id={$classId}&is_active=1")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $batchId);
        $this->patchJson("{$base}/batches/{$batchId}", ['name' => 'Morning cohort', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.name', 'Morning cohort')->assertJsonPath('data.is_active', false);
        $this->getJson("{$base}/batches?is_active=0")->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Morning cohort');
        $this->patchJson("{$base}/batches/{$batchId}", ['is_active' => true])->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_managers_can_manage_searchable_tenant_branches(): void
    {
        [$user, $organization] = $this->workspace(OrganizationRole::Management);
        Sanctum::actingAs($user);
        $base = "/api/v1/organizations/{$organization->id}/branches";
        $branchId = $this->postJson($base, [
            'name' => 'Central campus', 'code' => 'CENTRAL', 'address' => '12 North Road',
            'contact_email' => 'central@example.test', 'contact_phone' => '+91 1234567890',
        ])->assertCreated()->assertJsonPath('data.organization_id', $organization->id)
            ->assertJsonPath('data.is_active', true)->json('data.id');
        $this->postJson($base, ['name' => 'West campus', 'code' => 'WEST'])->assertCreated();

        $this->getJson("{$base}?search=cent&is_active=1")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $branchId);
        $this->patchJson("{$base}/{$branchId}", ['name' => 'Central branch', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.name', 'Central branch')->assertJsonPath('data.is_active', false);
        $this->getJson("{$base}?is_active=0")->assertOk()->assertJsonPath('meta.total', 1);
        $this->patchJson("{$base}/{$branchId}", ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
        $this->postJson($base, ['name' => 'Central branch', 'code' => 'CENTRAL'])->assertUnprocessable();

        $otherOrganization = $this->organization('Other branch tenant');
        $this->getJson("/api/v1/organizations/{$otherOrganization->id}/branches")->assertNotFound();
        $this->patchJson("/api/v1/organizations/{$otherOrganization->id}/branches/{$branchId}", ['name' => 'Foreign'])->assertNotFound();
    }

    public function test_students_cannot_manage_organization_branches(): void
    {
        [$user, $organization] = $this->workspace(OrganizationRole::Student);
        Sanctum::actingAs($user);
        $base = "/api/v1/organizations/{$organization->id}/branches";

        $this->getJson($base)->assertOk();
        $this->postJson($base, ['name' => 'Main campus'])->assertForbidden();
    }

    public function test_class_branch_cannot_be_changed_until_students_are_reassigned(): void
    {
        [$user, $organization] = $this->workspace();
        $one = OrganizationBranch::query()->create(['organization_id' => $organization->id, 'name' => 'One']);
        $two = OrganizationBranch::query()->create(['organization_id' => $organization->id, 'name' => 'Two']);
        $class = AcademicClass::query()->create([
            'organization_id' => $organization->id, 'organization_branch_id' => $one->id, 'name' => 'Grade 7',
        ]);
        Student::query()->create([
            'organization_id' => $organization->id, 'organization_branch_id' => $one->id,
            'academic_class_id' => $class->id, 'name' => 'Student', 'admission_number' => 'A-1',
        ]);
        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/organizations/{$organization->id}/classes/{$class->id}", ['organization_branch_id' => $two->id])
            ->assertUnprocessable()->assertJsonValidationErrors('organization_branch_id');
    }

    public function test_batch_creation_rejects_inconsistent_or_cross_tenant_academic_placement(): void
    {
        [$user, $organization] = $this->workspace();
        Sanctum::actingAs($user);
        $base = "/api/v1/organizations/{$organization->id}";
        $sessionId = $this->postJson("{$base}/academic-sessions", [
            'name' => '2026–2027', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31',
        ])->assertCreated()->json('data.id');
        $classId = $this->postJson("{$base}/classes", ['name' => 'Grade 8'])->assertCreated()->json('data.id');
        $otherClassId = $this->postJson("{$base}/classes", ['name' => 'Grade 9'])->assertCreated()->json('data.id');
        $sectionId = $this->postJson("{$base}/classes/{$otherClassId}/sections", [
            'name' => 'A', 'academic_session_id' => $sessionId,
        ])->assertCreated()->json('data.id');

        $payload = ['academic_session_id' => $sessionId, 'academic_class_id' => $classId,
            'academic_section_id' => $sectionId, 'name' => 'Invalid placement'];
        $this->postJson("{$base}/batches", $payload)->assertUnprocessable()->assertJsonValidationErrors('academic_section_id');

        $otherOrganization = $this->organization('Other institution');
        $this->postJson("/api/v1/organizations/{$otherOrganization->id}/classes", ['name' => 'Foreign class'])->assertNotFound();
        $this->getJson("/api/v1/organizations/{$otherOrganization->id}/batches")->assertNotFound();
        $this->postJson("/api/v1/organizations/{$otherOrganization->id}/batches", [])->assertNotFound();
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
