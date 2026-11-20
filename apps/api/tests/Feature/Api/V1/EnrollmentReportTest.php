<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\AcademicClass;
use App\Models\AcademicSection;
use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnrollmentReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_sees_tenant_scoped_student_placement_and_course_enrollment_totals(): void
    {
        $organization = $this->organization('Northstar Academy');
        $admin = User::factory()->create();
        $this->membership($organization, $admin, OrganizationRole::Admin);
        $session = AcademicSession::query()->create([
            'organization_id' => $organization->id, 'name' => '2026–27',
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'is_current' => true, 'is_active' => true,
        ]);
        $class = AcademicClass::query()->create(['organization_id' => $organization->id, 'name' => 'Grade 8']);
        $section = AcademicSection::query()->create([
            'organization_id' => $organization->id, 'academic_class_id' => $class->id,
            'academic_session_id' => $session->id, 'name' => 'A', 'capacity' => 30, 'is_active' => true,
        ]);
        $assigned = Student::query()->create([
            'organization_id' => $organization->id, 'name' => 'Learner A', 'admission_number' => 'A-1',
            'academic_class_id' => $class->id, 'academic_section_id' => $section->id,
            'academic_session_id' => $session->id, 'status' => 'active',
        ]);
        Student::query()->create([
            'organization_id' => $organization->id, 'name' => 'Unassigned learner', 'admission_number' => 'A-2', 'status' => 'active',
        ]);
        $course = Course::query()->create([
            'organization_id' => $organization->id, 'created_by' => $admin->id,
            'title' => 'Published course', 'status' => 'published', 'published_at' => now(),
        ]);
        CourseEnrollment::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id,
            'student_id' => $assigned->id, 'enrolled_by' => $admin->id, 'status' => 'active',
        ]);
        $foreignOrganization = $this->organization('Westfield School');
        Student::query()->create([
            'organization_id' => $foreignOrganization->id, 'name' => 'Foreign learner', 'admission_number' => 'F-1', 'status' => 'active',
        ]);
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/organizations/{$organization->id}/reports/enrollment")->assertOk()
            ->assertJsonPath('data.summary.active_students', 2)
            ->assertJsonPath('data.summary.unassigned_students', 1)
            ->assertJsonPath('data.summary.students_with_published_courses', 1)
            ->assertJsonPath('data.summary.active_course_enrollments', 1)
            ->assertJsonPath('data.sessions.0.session', '2026–27')
            ->assertJsonPath('data.sessions.0.active_students', 1)
            ->assertJsonPath('data.sessions.0.sections.0.class', 'Grade 8')
            ->assertJsonPath('data.sessions.0.sections.0.active_students', 1)
            ->assertJsonPath('data.sessions.0.sections.0.active_course_enrollments', 1);
    }

    public function test_teacher_cannot_read_organization_wide_enrollment_totals(): void
    {
        $organization = $this->organization('Northstar Academy');
        $teacher = User::factory()->create();
        $this->membership($organization, $teacher, OrganizationRole::Teacher);
        Sanctum::actingAs($teacher);

        $this->getJson("/api/v1/organizations/{$organization->id}/reports/enrollment")->assertForbidden();
    }

    public function test_independent_educator_can_read_enrollment_totals(): void
    {
        $organization = $this->organization('Northstar Academy');
        $educator = User::factory()->create();
        $this->membership($organization, $educator, OrganizationRole::IndependentEducator);
        Sanctum::actingAs($educator);

        $this->getJson("/api/v1/organizations/{$organization->id}/reports/enrollment")
            ->assertOk()
            ->assertJsonPath('data.summary.active_students', 0);
    }

    private function membership(Organization $organization, User $user, OrganizationRole $role): void
    {
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role,
            'status' => MembershipStatus::Active, 'accepted_at' => now(),
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
