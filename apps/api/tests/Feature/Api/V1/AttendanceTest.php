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
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_mark_and_update_attendance_for_enrolled_students(): void
    {
        [$user, $organization, $section, $student] = $this->classroom();
        Sanctum::actingAs($user);
        $endpoint = "/api/v1/organizations/{$organization->id}/attendance";
        $payload = [
            'section_id' => $section->id,
            'date' => '2026-11-18',
            'records' => [['student_id' => $student->id, 'status' => 'present', 'note' => 'On time']],
        ];

        $this->putJson($endpoint, $payload)->assertOk()->assertJsonPath('saved', 1);
        $this->assertDatabaseHas('attendance_records', [
            'organization_id' => $organization->id,
            'student_id' => $student->id,
            'academic_section_id' => $section->id,
            'status' => 'present',
            'marked_by' => $user->id,
        ]);

        $this->putJson($endpoint, array_replace_recursive($payload, ['records' => [['student_id' => $student->id, 'status' => 'late', 'note' => null, 'correction_reason' => 'Teacher confirmed the original mark was incorrect.']]]))
            ->assertOk()->assertJsonPath('saved', 1);
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('attendance_corrections', [
            'organization_id' => $organization->id,
            'actor_user_id' => $user->id,
            'previous_status' => 'present',
            'status' => 'late',
            'reason' => 'Teacher confirmed the original mark was incorrect.',
        ]);
        $this->getJson("{$endpoint}?section_id={$section->id}&date=2026-11-18")
            ->assertOk()->assertJsonPath('data.0.status', 'late')->assertJsonPath('data.0.note', null)
            ->assertJsonPath('context.session.id', $section->academic_session_id);
        $this->getJson("{$endpoint}/corrections?section_id={$section->id}&date=2026-11-18")
            ->assertOk()->assertJsonPath('data.0.student.name', $student->name)
            ->assertJsonPath('data.0.actor', $user->name)
            ->assertJsonPath('data.0.previous_status', 'present')->assertJsonPath('data.0.reason', 'Teacher confirmed the original mark was incorrect.');
    }

    public function test_saved_attendance_cannot_be_changed_without_a_correction_reason(): void
    {
        [$user, $organization, $section, $student] = $this->classroom();
        Sanctum::actingAs($user);
        $endpoint = "/api/v1/organizations/{$organization->id}/attendance";
        $this->putJson($endpoint, [
            'section_id' => $section->id,
            'date' => '2026-11-18',
            'records' => [['student_id' => $student->id, 'status' => 'present', 'note' => 'On time']],
        ])->assertOk();

        $this->putJson($endpoint, [
            'section_id' => $section->id,
            'date' => '2026-11-18',
            'records' => [['student_id' => $student->id, 'status' => 'absent', 'note' => null]],
        ])->assertUnprocessable()->assertJsonValidationErrors('records.0.correction_reason');

        $this->assertDatabaseHas('attendance_records', ['student_id' => $student->id, 'status' => 'present', 'note' => 'On time']);
        $this->assertDatabaseCount('attendance_corrections', 0);

        $this->putJson($endpoint, [
            'section_id' => $section->id,
            'date' => '2026-11-18',
            'records' => [['student_id' => $student->id, 'status' => 'present', 'note' => 'On time']],
        ])->assertOk();
        $this->assertDatabaseCount('attendance_corrections', 0);
    }

    public function test_user_cannot_access_another_tenants_attendance(): void
    {
        [$user] = $this->classroom();
        $otherOrganization = $this->organization('Other Learning Center');
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/organizations/{$otherOrganization->id}/attendance?section_id=1&date=2026-11-18")->assertNotFound();
    }

    public function test_unlinked_teacher_membership_cannot_edit_attendance(): void
    {
        [$user, $organization, $section, $student] = $this->classroom(OrganizationRole::Teacher);
        Sanctum::actingAs($user);

        $this->putJson("/api/v1/organizations/{$organization->id}/attendance", [
            'section_id' => $section->id,
            'date' => '2026-11-18',
            'records' => [['student_id' => $student->id, 'status' => 'present']],
        ])->assertForbidden();
    }

    public function test_linked_teacher_can_mark_attendance_only_in_an_assigned_section(): void
    {
        [$user, $organization, $section, $student] = $this->classroom(OrganizationRole::Teacher);
        $teacher = Teacher::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id,
            'name' => 'Teacher Example', 'email' => $user->email, 'status' => 'active',
        ]);
        $subject = Subject::query()->create(['organization_id' => $organization->id, 'name' => 'Science']);
        TeachingAssignment::query()->create([
            'organization_id' => $organization->id, 'academic_session_id' => $section->academic_session_id,
            'academic_section_id' => $section->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
        ]);
        Sanctum::actingAs($user);
        $endpoint = "/api/v1/organizations/{$organization->id}/attendance";

        $this->getJson("{$endpoint}?section_id={$section->id}&date=2026-11-18")->assertOk();
        $this->putJson($endpoint, [
            'section_id' => $section->id,
            'date' => '2026-11-18',
            'records' => [['student_id' => $student->id, 'status' => 'present']],
        ])->assertOk();
        $this->getJson("{$endpoint}/corrections?section_id={$section->id}&date=2026-11-18")->assertOk();

        $unassignedSection = AcademicSection::query()->create([
            'organization_id' => $organization->id,
            'academic_class_id' => $section->academic_class_id,
            'academic_session_id' => $section->academic_session_id,
            'name' => 'B',
        ]);
        $this->getJson("{$endpoint}?section_id={$unassignedSection->id}&date=2026-11-18")->assertForbidden();
        $this->getJson("{$endpoint}/corrections?section_id={$unassignedSection->id}&date=2026-11-18")->assertForbidden();
    }

    public function test_attendance_requires_students_enrolled_in_the_selected_section_and_session(): void
    {
        [$user, $organization, $section] = $this->classroom();
        $student = Student::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Unassigned Student',
            'admission_number' => 'ST-UNASSIGNED',
            'status' => 'active',
        ]);
        Sanctum::actingAs($user);

        $this->putJson("/api/v1/organizations/{$organization->id}/attendance", [
            'section_id' => $section->id,
            'date' => '2026-11-18',
            'records' => [['student_id' => $student->id, 'status' => 'absent']],
        ])->assertUnprocessable()->assertJsonValidationErrors('records');
    }

    public function test_attendance_date_must_be_inside_the_academic_session(): void
    {
        [$user, $organization, $section, $student] = $this->classroom();
        Sanctum::actingAs($user);

        $this->putJson("/api/v1/organizations/{$organization->id}/attendance", [
            'section_id' => $section->id,
            'date' => '2028-01-01',
            'records' => [['student_id' => $student->id, 'status' => 'absent']],
        ])->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public function test_admin_can_read_date_filtered_attendance_summary_by_section(): void
    {
        [$user, $organization, $section, $student] = $this->classroom();
        $endpoint = "/api/v1/organizations/{$organization->id}/attendance";
        Sanctum::actingAs($user);
        $this->putJson($endpoint, [
            'section_id' => $section->id,
            'date' => '2026-11-18',
            'records' => [['student_id' => $student->id, 'status' => 'late']],
        ])->assertOk();
        $this->putJson($endpoint, [
            'section_id' => $section->id,
            'date' => '2026-11-19',
            'records' => [['student_id' => $student->id, 'status' => 'absent']],
        ])->assertOk();

        $this->getJson("/api/v1/organizations/{$organization->id}/reports/attendance?from=2026-11-18&to=2026-11-18")
            ->assertOk()->assertJsonPath('data.totals.records', 1)
            ->assertJsonPath('data.totals.attendance_rate', 100)
            ->assertJsonPath('data.sections.0.late', 1)
            ->assertJsonPath('data.sections.0.class', 'Grade 5');
        $this->getJson("/api/v1/organizations/{$organization->id}/reports/attendance?from=2026-11-20&to=2026-11-18")
            ->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_independent_educator_can_read_date_filtered_attendance_summary(): void
    {
        [$user, $organization] = $this->classroom(OrganizationRole::IndependentEducator);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/organizations/{$organization->id}/reports/attendance?from=2026-11-18&to=2026-11-24")
            ->assertOk()
            ->assertJsonPath('data.from', '2026-11-18')
            ->assertJsonPath('data.to', '2026-11-24')
            ->assertJsonPath('data.totals.records', 0)
            ->assertJsonPath('data.sections', []);
    }

    private function classroom(OrganizationRole $role = OrganizationRole::Admin): array
    {
        $user = User::factory()->create();
        $organization = $this->organization('Northstar Academy');
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);
        $session = AcademicSession::query()->create([
            'organization_id' => $organization->id,
            'name' => '2026–2027',
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-05-31',
            'is_current' => true,
        ]);
        $class = AcademicClass::query()->create(['organization_id' => $organization->id, 'name' => 'Grade 5']);
        $section = AcademicSection::query()->create([
            'organization_id' => $organization->id,
            'academic_class_id' => $class->id,
            'academic_session_id' => $session->id,
            'name' => 'A',
        ]);
        $student = Student::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Learner Example',
            'admission_number' => 'ST-100',
            'academic_class_id' => $class->id,
            'academic_section_id' => $section->id,
            'academic_session_id' => $session->id,
            'status' => 'active',
        ]);

        return [$user, $organization, $section, $student];
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
