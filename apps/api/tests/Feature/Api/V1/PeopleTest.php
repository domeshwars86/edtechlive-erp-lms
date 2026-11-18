<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\AcademicClass;
use App\Models\AcademicSection;
use App\Models\AcademicSession;
use App\Models\Organization;
use App\Models\OrganizationMembership;
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
