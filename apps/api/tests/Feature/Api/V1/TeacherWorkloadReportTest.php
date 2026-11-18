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
use App\Models\TimetableEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherWorkloadReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_sees_active_teacher_workload_for_current_sessions_only(): void
    {
        $organization = $this->organization('Northstar Academy');
        $admin = User::factory()->create();
        $this->membership($organization, $admin, OrganizationRole::Management);
        $today = now($organization->timezone);
        $session = AcademicSession::query()->create([
            'organization_id' => $organization->id, 'name' => 'Current',
            'starts_on' => $today->copy()->subMonth()->toDateString(), 'ends_on' => $today->copy()->addMonth()->toDateString(),
            'is_current' => true, 'is_active' => true,
        ]);
        $class = AcademicClass::query()->create(['organization_id' => $organization->id, 'name' => 'Grade 8']);
        $section = AcademicSection::query()->create([
            'organization_id' => $organization->id, 'academic_class_id' => $class->id,
            'academic_session_id' => $session->id, 'name' => 'A', 'is_active' => true,
        ]);
        $teacher = Teacher::query()->create(['organization_id' => $organization->id, 'name' => 'Ada Teacher', 'status' => 'active']);
        Teacher::query()->create(['organization_id' => $organization->id, 'name' => 'Inactive Teacher', 'status' => 'inactive']);
        $firstSubject = Subject::query()->create(['organization_id' => $organization->id, 'name' => 'Math']);
        $secondSubject = Subject::query()->create(['organization_id' => $organization->id, 'name' => 'Science']);
        foreach ([$firstSubject, $secondSubject] as $subject) {
            TeachingAssignment::query()->create([
                'organization_id' => $organization->id, 'academic_session_id' => $session->id,
                'academic_section_id' => $section->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            ]);
        }
        $futureSession = AcademicSession::query()->create([
            'organization_id' => $organization->id, 'name' => 'Future',
            'starts_on' => $today->copy()->addMonth()->toDateString(), 'ends_on' => $today->copy()->addYear()->toDateString(),
            'is_current' => false, 'is_active' => true,
        ]);
        $futureSection = AcademicSection::query()->create([
            'organization_id' => $organization->id, 'academic_class_id' => $class->id,
            'academic_session_id' => $futureSession->id, 'name' => 'Future A', 'is_active' => true,
        ]);
        TeachingAssignment::query()->create([
            'organization_id' => $organization->id, 'academic_session_id' => $futureSession->id,
            'academic_section_id' => $futureSection->id, 'subject_id' => $firstSubject->id, 'teacher_id' => $teacher->id,
        ]);
        foreach (['A-1', 'A-2'] as $admissionNumber) {
            Student::query()->create([
                'organization_id' => $organization->id, 'name' => $admissionNumber, 'admission_number' => $admissionNumber,
                'academic_class_id' => $class->id, 'academic_section_id' => $section->id,
                'academic_session_id' => $session->id, 'status' => 'active',
            ]);
        }
        TimetableEntry::query()->create([
            'organization_id' => $organization->id, 'academic_session_id' => $session->id,
            'academic_section_id' => $section->id, 'subject_id' => $firstSubject->id, 'teacher_id' => $teacher->id,
            'weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '09:45', 'is_active' => true,
        ]);
        TimetableEntry::query()->create([
            'organization_id' => $organization->id, 'academic_session_id' => $session->id,
            'academic_section_id' => $section->id, 'subject_id' => $secondSubject->id, 'teacher_id' => $teacher->id,
            'weekday' => 2, 'starts_at' => '10:00', 'ends_at' => '11:30', 'is_active' => true,
        ]);
        TimetableEntry::query()->create([
            'organization_id' => $organization->id, 'academic_session_id' => $session->id,
            'academic_section_id' => $section->id, 'subject_id' => $firstSubject->id, 'teacher_id' => $teacher->id,
            'weekday' => 3, 'starts_at' => '12:00', 'ends_at' => '12:30', 'is_active' => false,
        ]);
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/organizations/{$organization->id}/reports/workload")->assertOk()
            ->assertJsonPath('data.summary.active_teachers', 1)
            ->assertJsonPath('data.summary.teachers_with_assignments', 1)
            ->assertJsonPath('data.summary.section_assignments', 1)
            ->assertJsonPath('data.summary.teacher_learner_assignments', 2)
            ->assertJsonPath('data.summary.weekly_timetable_slots', 2)
            ->assertJsonPath('data.summary.weekly_scheduled_hours', 2.25)
            ->assertJsonPath('data.teachers.0.teacher', 'Ada Teacher')
            ->assertJsonPath('data.teachers.0.section_assignments', 1)
            ->assertJsonPath('data.teachers.0.active_learners', 2);
    }

    public function test_teacher_cannot_read_organization_wide_workload(): void
    {
        $organization = $this->organization('Northstar Academy');
        $teacher = User::factory()->create();
        $this->membership($organization, $teacher, OrganizationRole::Teacher);
        Sanctum::actingAs($teacher);

        $this->getJson("/api/v1/organizations/{$organization->id}/reports/workload")->assertForbidden();
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
