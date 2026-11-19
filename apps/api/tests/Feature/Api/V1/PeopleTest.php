<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\AcademicClass;
use App\Models\AcademicSection;
use App\Models\AcademicSession;
use App\Models\Organization;
use App\Models\OrganizationBranch;
use App\Models\OrganizationMembership;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PeopleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_search_students_and_teachers(): void
    {
        [$user, $organization] = $this->workspace();
        $session = AcademicSession::query()->create([
            'organization_id' => $organization->id,
            'name' => '2026–2027',
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-05-31',
            'is_current' => true,
        ]);
        $class = AcademicClass::query()->create(['organization_id' => $organization->id, 'name' => 'Grade 8']);
        $section = AcademicSection::query()->create([
            'organization_id' => $organization->id,
            'academic_class_id' => $class->id,
            'academic_session_id' => $session->id,
            'name' => 'A',
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/organizations/{$organization->id}/students", [
            'name' => 'Student Example', 'admission_number' => 'ST-001', 'academic_section_id' => $section->id,
        ])->assertCreated()->assertJsonPath('data.academic_class_id', $class->id)->assertJsonPath('data.academic_session_id', $session->id);

        $this->postJson("/api/v1/organizations/{$organization->id}/teachers", [
            'name' => 'Teacher Example', 'employee_number' => 'TE-001', 'specialization' => 'Mathematics',
        ])->assertCreated();

        $this->getJson("/api/v1/organizations/{$organization->id}/students?search=ST-001")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Student Example');
        $this->getJson("/api/v1/organizations/{$organization->id}/teachers?search=TE-001")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Teacher Example');
    }

    public function test_student_members_cannot_read_a_tenant_roster(): void
    {
        [$user, $organization] = $this->workspace(OrganizationRole::Student);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/organizations/{$organization->id}/students")->assertForbidden();
        $this->getJson("/api/v1/organizations/{$organization->id}/teachers")->assertForbidden();
    }

    public function test_staff_can_browse_paginated_student_and_teacher_rosters(): void
    {
        [$user, $organization] = $this->workspace(OrganizationRole::IndependentEducator);
        Sanctum::actingAs($user);
        for ($index = 1; $index <= 25; $index++) {
            Student::query()->create([
                'organization_id' => $organization->id,
                'name' => sprintf('Student %02d', $index),
                'admission_number' => sprintf('ST-%03d', $index),
            ]);
            Teacher::query()->create([
                'organization_id' => $organization->id,
                'name' => sprintf('Teacher %02d', $index),
                'employee_number' => sprintf('TE-%03d', $index),
            ]);
        }
        $base = "/api/v1/organizations/{$organization->id}";

        $this->getJson("{$base}/students?status=active&per_page=20&page=2")
            ->assertOk()->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 25)->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.name', 'Student 21')->assertJsonPath('data.4.name', 'Student 25');
        $this->getJson("{$base}/teachers?status=active&per_page=20&page=2")
            ->assertOk()->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 25)->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.name', 'Teacher 21')->assertJsonPath('data.4.name', 'Teacher 25');
    }

    public function test_management_members_can_read_student_and_teacher_rosters(): void
    {
        [$user, $organization] = $this->workspace(OrganizationRole::Management);
        Sanctum::actingAs($user);
        $base = "/api/v1/organizations/{$organization->id}";

        $this->getJson("{$base}/students?status=active&per_page=20&page=1")->assertOk()->assertJsonPath('data', []);
        $this->getJson("{$base}/teachers?status=active&per_page=20&page=1")->assertOk()->assertJsonPath('data', []);
    }

    public function test_roster_references_cannot_cross_tenant_boundaries(): void
    {
        [$user, $organization] = $this->workspace();
        $otherOrganization = $this->organization('Other Academy');
        $otherClass = AcademicClass::query()->create(['organization_id' => $otherOrganization->id, 'name' => 'Grade 8']);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/organizations/{$otherOrganization->id}/students")->assertNotFound();
        $this->postJson("/api/v1/organizations/{$organization->id}/students", [
            'name' => 'Student Example', 'admission_number' => 'ST-002', 'academic_class_id' => $otherClass->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('academic_class_id');
    }

    public function test_duplicate_student_numbers_and_teacher_numbers_are_rejected(): void
    {
        [$user, $organization] = $this->workspace();
        Sanctum::actingAs($user);
        $studentEndpoint = "/api/v1/organizations/{$organization->id}/students";
        $teacherEndpoint = "/api/v1/organizations/{$organization->id}/teachers";
        $this->postJson($studentEndpoint, ['name' => 'One', 'admission_number' => 'ST-003'])->assertCreated();
        $this->postJson($studentEndpoint, ['name' => 'Two', 'admission_number' => 'ST-003'])->assertUnprocessable()->assertJsonValidationErrors('admission_number');
        $this->postJson($teacherEndpoint, ['name' => 'One', 'employee_number' => 'TE-003'])->assertCreated();
        $this->postJson($teacherEndpoint, ['name' => 'Two', 'employee_number' => 'TE-003'])->assertUnprocessable()->assertJsonValidationErrors('employee_number');
    }

    public function test_class_branch_is_inherited_by_students_and_rosters_can_be_filtered_by_branch(): void
    {
        [$user, $organization] = $this->workspace();
        $branch = OrganizationBranch::query()->create(['organization_id' => $organization->id, 'name' => 'East campus', 'code' => 'EAST']);
        $class = AcademicClass::query()->create([
            'organization_id' => $organization->id, 'organization_branch_id' => $branch->id, 'name' => 'Grade 8',
        ]);
        Sanctum::actingAs($user);
        $base = "/api/v1/organizations/{$organization->id}";

        $studentId = $this->postJson("{$base}/students", [
            'name' => 'Branch Student', 'admission_number' => 'BR-001', 'academic_class_id' => $class->id,
        ])->assertCreated()->assertJsonPath('data.organization_branch_id', $branch->id)
            ->assertJsonPath('data.branch.name', 'East campus')->json('data.id');
        $this->postJson("{$base}/teachers", [
            'name' => 'Branch Teacher', 'employee_number' => 'BR-T1', 'organization_branch_id' => $branch->id,
        ])->assertCreated()->assertJsonPath('data.branch.name', 'East campus');
        $this->getJson("{$base}/students?organization_branch_id={$branch->id}")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $studentId);

        $otherBranch = OrganizationBranch::query()->create(['organization_id' => $organization->id, 'name' => 'West campus', 'code' => 'WEST']);
        $this->patchJson("{$base}/students/{$studentId}", ['organization_branch_id' => $otherBranch->id])
            ->assertUnprocessable()->assertJsonValidationErrors('organization_branch_id');
        $otherClass = AcademicClass::query()->create([
            'organization_id' => $organization->id, 'organization_branch_id' => $otherBranch->id, 'name' => 'Grade 9',
        ]);
        $this->patchJson("{$base}/students/{$studentId}", ['academic_class_id' => $otherClass->id])
            ->assertOk()->assertJsonPath('data.organization_branch_id', $otherBranch->id);
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
