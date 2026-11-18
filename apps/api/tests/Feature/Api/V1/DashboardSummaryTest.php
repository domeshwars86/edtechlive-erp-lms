<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\AcademicClass;
use App\Models\AcademicSection;
use App\Models\AcademicSession;
use App\Models\AssignmentSubmission;
use App\Models\AttendanceRecord;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseEnrollment;
use App\Models\CourseLesson;
use App\Models\CourseLessonProgress;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_summary_uses_live_tenant_scoped_counts_and_today_metrics(): void
    {
        $organization = $this->organization('Northstar Academy');
        $admin = User::factory()->create();
        $this->membership($organization, $admin, OrganizationRole::Admin);
        $activeStudents = collect(['S-1', 'S-2'])->map(fn (string $number): Student => Student::query()->create([
            'organization_id' => $organization->id, 'name' => "Learner {$number}",
            'admission_number' => $number, 'status' => 'active',
        ]));
        Student::query()->create(['organization_id' => $organization->id, 'name' => 'Inactive learner', 'admission_number' => 'S-3', 'status' => 'inactive']);
        Teacher::query()->create(['organization_id' => $organization->id, 'name' => 'Active teacher', 'status' => 'active']);
        Teacher::query()->create(['organization_id' => $organization->id, 'name' => 'Inactive teacher', 'status' => 'inactive']);

        $year = Carbon::now('Asia/Kolkata')->year;
        $session = AcademicSession::query()->create([
            'organization_id' => $organization->id, 'name' => 'Current session',
            'starts_on' => "{$year}-01-01", 'ends_on' => ($year + 1).'-12-31', 'is_current' => true, 'is_active' => true,
        ]);
        $class = AcademicClass::query()->create(['organization_id' => $organization->id, 'name' => 'Grade 5']);
        $section = AcademicSection::query()->create([
            'organization_id' => $organization->id, 'academic_class_id' => $class->id,
            'academic_session_id' => $session->id, 'name' => 'A',
        ]);
        $subject = Subject::query()->create(['organization_id' => $organization->id, 'name' => 'Science']);
        $weekday = Carbon::now('Asia/Kolkata')->dayOfWeekIso - 1;
        foreach ([true, false] as $active) {
            TimetableEntry::query()->create([
                'organization_id' => $organization->id, 'academic_session_id' => $session->id,
                'academic_section_id' => $section->id, 'subject_id' => $subject->id,
                'weekday' => $weekday, 'starts_at' => '09:00', 'ends_at' => '10:00', 'is_active' => $active,
            ]);
        }
        foreach (['present', 'late'] as $index => $status) {
            AttendanceRecord::query()->create([
                'organization_id' => $organization->id, 'academic_section_id' => $section->id,
                'academic_session_id' => $session->id, 'student_id' => $activeStudents[$index]->id,
                'attendance_date' => Carbon::now('Asia/Kolkata')->toDateString(), 'status' => $status, 'marked_by' => $admin->id,
            ]);
        }

        $foreignOrganization = $this->organization('Westfield School');
        Student::query()->create(['organization_id' => $foreignOrganization->id, 'name' => 'Foreign learner', 'admission_number' => 'F-1', 'status' => 'active']);

        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/organizations/{$organization->id}/dashboard/summary")->assertOk()
            ->assertJsonPath('data.students', 2)
            ->assertJsonPath('data.teachers', 1)
            ->assertJsonPath('data.classes_today', 1)
            ->assertJsonPath('data.attendance_today.records', 2)
            ->assertJsonPath('data.attendance_today.rate', 100);
        AttendanceRecord::query()->where('organization_id', $organization->id)->delete();
        $this->getJson("/api/v1/organizations/{$organization->id}/dashboard/summary")->assertOk()
            ->assertJsonPath('data.attendance_today.rate', null);
    }

    public function test_student_dashboard_summary_reports_only_their_own_learning_activity(): void
    {
        $organization = $this->organization('Northstar Academy');
        $student = User::factory()->create();
        $this->membership($organization, $student, OrganizationRole::Student);
        $profile = Student::query()->create([
            'organization_id' => $organization->id, 'user_id' => $student->id,
            'name' => 'Learner', 'admission_number' => 'S-1', 'status' => 'active',
        ]);
        $course = Course::query()->create([
            'organization_id' => $organization->id, 'created_by' => $student->id,
            'title' => 'Science', 'status' => 'published', 'published_at' => now(),
        ]);
        CourseEnrollment::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id,
            'student_id' => $profile->id, 'enrolled_by' => $student->id, 'status' => 'active',
        ]);
        $lesson = CourseLesson::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id,
            'title' => 'First lesson', 'sort_order' => 1, 'content' => 'Notes', 'status' => 'published',
        ]);
        CourseLessonProgress::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id,
            'lesson_id' => $lesson->id, 'student_id' => $profile->id, 'completed_at' => now(),
        ]);
        $assignment = CourseAssignment::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id,
            'created_by' => $student->id, 'title' => 'Submitted work', 'max_points' => 10, 'status' => 'published',
        ]);
        AssignmentSubmission::query()->create([
            'organization_id' => $organization->id, 'assignment_id' => $assignment->id,
            'student_id' => $profile->id, 'answer' => 'Done', 'submitted_at' => now(),
        ]);
        CourseAssignment::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id,
            'created_by' => $student->id, 'title' => 'Still to submit', 'max_points' => 10, 'status' => 'published',
        ]);
        Sanctum::actingAs($student);

        $this->getJson("/api/v1/organizations/{$organization->id}/dashboard/summary")->assertOk()
            ->assertJsonPath('data.kind', 'student')
            ->assertJsonPath('data.active_courses', 1)
            ->assertJsonPath('data.lessons_completed_week', 1)
            ->assertJsonPath('data.assignments_pending', 1)
            ->assertJsonPath('data.attendance_recent.rate', null);
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
