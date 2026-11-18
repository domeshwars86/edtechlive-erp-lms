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
use App\Models\Meeting;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
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

    public function test_teacher_dashboard_summary_is_limited_to_their_assignments_and_course_reviews(): void
    {
        $organization = $this->organization('Northstar Academy');
        $teacherUser = User::factory()->create();
        $this->membership($organization, $teacherUser, OrganizationRole::Teacher);
        $teacher = Teacher::query()->create([
            'organization_id' => $organization->id, 'user_id' => $teacherUser->id,
            'name' => 'Teacher', 'status' => 'active',
        ]);
        $year = Carbon::now('Asia/Kolkata')->year;
        $session = AcademicSession::query()->create([
            'organization_id' => $organization->id, 'name' => 'Current session',
            'starts_on' => "{$year}-01-01", 'ends_on' => ($year + 1).'-12-31', 'is_current' => true, 'is_active' => true,
        ]);
        $class = AcademicClass::query()->create(['organization_id' => $organization->id, 'name' => 'Grade 5']);
        $section = AcademicSection::query()->create([
            'organization_id' => $organization->id, 'academic_class_id' => $class->id,
            'academic_session_id' => $session->id, 'name' => 'A', 'is_active' => true,
        ]);
        $subject = Subject::query()->create(['organization_id' => $organization->id, 'name' => 'Science']);
        TeachingAssignment::query()->create([
            'organization_id' => $organization->id, 'academic_session_id' => $session->id,
            'academic_section_id' => $section->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
        ]);
        $learner = Student::query()->create([
            'organization_id' => $organization->id, 'name' => 'Assigned learner', 'admission_number' => 'S-1',
            'academic_section_id' => $section->id, 'academic_session_id' => $session->id, 'status' => 'active',
        ]);
        Student::query()->create(['organization_id' => $organization->id, 'name' => 'Unassigned learner', 'admission_number' => 'S-2', 'status' => 'active']);
        $course = Course::query()->create([
            'organization_id' => $organization->id, 'teacher_id' => $teacher->id, 'created_by' => $teacherUser->id,
            'title' => 'Science', 'status' => 'published', 'published_at' => now(),
        ]);
        $assignment = CourseAssignment::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id,
            'created_by' => $teacherUser->id, 'title' => 'Review this', 'max_points' => 10, 'status' => 'published',
        ]);
        AssignmentSubmission::query()->create([
            'organization_id' => $organization->id, 'assignment_id' => $assignment->id,
            'student_id' => $learner->id, 'answer' => 'Answer', 'submitted_at' => now(),
        ]);
        TimetableEntry::query()->create([
            'organization_id' => $organization->id, 'academic_session_id' => $session->id,
            'academic_section_id' => $section->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            'weekday' => Carbon::now('Asia/Kolkata')->dayOfWeekIso - 1, 'starts_at' => '09:00', 'ends_at' => '10:00', 'is_active' => true,
        ]);
        Sanctum::actingAs($teacherUser);

        $this->getJson("/api/v1/organizations/{$organization->id}/dashboard/summary")->assertOk()
            ->assertJsonPath('data.kind', 'teacher')
            ->assertJsonPath('data.classes', 1)
            ->assertJsonPath('data.students', 1)
            ->assertJsonPath('data.reviews_pending', 1)
            ->assertJsonPath('data.classes_this_week', 1)
            ->assertJsonPath('data.courses', 1);
    }

    public function test_parent_summary_includes_only_explicitly_linked_children(): void
    {
        $organization = $this->organization('Northstar Academy');
        $parent = User::factory()->create();
        $this->membership($organization, $parent, OrganizationRole::Parent);
        $admin = User::factory()->create();
        $year = Carbon::now('Asia/Kolkata')->year;
        $session = AcademicSession::query()->create([
            'organization_id' => $organization->id, 'name' => 'Current session',
            'starts_on' => "{$year}-01-01", 'ends_on' => ($year + 1).'-12-31', 'is_current' => true, 'is_active' => true,
        ]);
        $class = AcademicClass::query()->create(['organization_id' => $organization->id, 'name' => 'Grade 5']);
        $section = AcademicSection::query()->create([
            'organization_id' => $organization->id, 'academic_class_id' => $class->id,
            'academic_session_id' => $session->id, 'name' => 'A', 'is_active' => true,
        ]);
        $linkedStudent = Student::query()->create([
            'organization_id' => $organization->id, 'name' => 'Linked child', 'admission_number' => 'S-1', 'status' => 'active',
            'academic_section_id' => $section->id, 'academic_session_id' => $session->id,
        ]);
        $unlinkedStudent = Student::query()->create([
            'organization_id' => $organization->id, 'name' => 'Other child', 'admission_number' => 'S-2', 'status' => 'active',
            'academic_section_id' => $section->id, 'academic_session_id' => $session->id,
        ]);
        StudentGuardian::query()->create([
            'organization_id' => $organization->id, 'student_id' => $linkedStudent->id,
            'guardian_user_id' => $parent->id, 'relationship' => 'parent', 'is_primary' => true, 'linked_at' => now(),
        ]);
        $course = Course::query()->create([
            'organization_id' => $organization->id, 'created_by' => $admin->id,
            'title' => 'Science', 'status' => 'published', 'published_at' => now(),
        ]);
        foreach ([$linkedStudent, $unlinkedStudent] as $student) {
            CourseEnrollment::query()->create([
                'organization_id' => $organization->id, 'course_id' => $course->id,
                'student_id' => $student->id, 'enrolled_by' => $admin->id, 'status' => 'active',
            ]);
        }
        $assignment = CourseAssignment::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id,
            'created_by' => $admin->id, 'title' => 'Homework', 'max_points' => 10, 'status' => 'published',
        ]);
        AssignmentSubmission::query()->create([
            'organization_id' => $organization->id, 'assignment_id' => $assignment->id,
            'student_id' => $unlinkedStudent->id, 'answer' => 'Submitted', 'submitted_at' => now(),
        ]);
        AttendanceRecord::query()->create([
            'organization_id' => $organization->id, 'academic_section_id' => $section->id,
            'academic_session_id' => $session->id, 'student_id' => $linkedStudent->id,
            'attendance_date' => Carbon::now('Asia/Kolkata')->toDateString(), 'status' => 'present', 'marked_by' => $admin->id,
        ]);
        AttendanceRecord::query()->create([
            'organization_id' => $organization->id, 'academic_section_id' => $section->id,
            'academic_session_id' => $session->id, 'student_id' => $unlinkedStudent->id,
            'attendance_date' => Carbon::now('Asia/Kolkata')->toDateString(), 'status' => 'absent', 'marked_by' => $admin->id,
        ]);
        Meeting::query()->create([
            'organization_id' => $organization->id, 'created_by' => $admin->id, 'course_id' => $course->id,
            'title' => 'Science class', 'starts_at' => Carbon::now('Asia/Kolkata')->addDay()->utc(),
            'ends_at' => Carbon::now('Asia/Kolkata')->addDay()->addHour()->utc(),
            'meeting_url' => 'https://meet.example.test/science', 'kind' => 'class', 'status' => 'scheduled',
        ]);
        Sanctum::actingAs($parent);

        $this->getJson("/api/v1/organizations/{$organization->id}/dashboard/summary")->assertOk()
            ->assertJsonPath('data.kind', 'parent')
            ->assertJsonPath('data.children', 1)
            ->assertJsonPath('data.assignments_pending', 1)
            ->assertJsonPath('data.attendance_recent.records', 1)
            ->assertJsonPath('data.attendance_recent.rate', 100)
            ->assertJsonPath('data.upcoming_meetings', 1);
    }

    public function test_independent_educator_summary_counts_published_workspace_activity(): void
    {
        $organization = $this->organization('Sana Learning Studio');
        $educator = User::factory()->create();
        $this->membership($organization, $educator, OrganizationRole::IndependentEducator);
        $learner = Student::query()->create([
            'organization_id' => $organization->id, 'name' => 'Learner', 'admission_number' => 'S-1', 'status' => 'active',
        ]);
        $course = Course::query()->create([
            'organization_id' => $organization->id, 'created_by' => $educator->id,
            'title' => 'Published course', 'status' => 'published', 'published_at' => now(),
        ]);
        Course::query()->create([
            'organization_id' => $organization->id, 'created_by' => $educator->id,
            'title' => 'Draft course', 'status' => 'draft',
        ]);
        CourseEnrollment::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id,
            'student_id' => $learner->id, 'enrolled_by' => $educator->id, 'status' => 'active',
        ]);
        $assignment = CourseAssignment::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id,
            'created_by' => $educator->id, 'title' => 'Work', 'max_points' => 10, 'status' => 'published',
        ]);
        AssignmentSubmission::query()->create([
            'organization_id' => $organization->id, 'assignment_id' => $assignment->id,
            'student_id' => $learner->id, 'answer' => 'Answer', 'submitted_at' => now(),
        ]);
        Meeting::query()->create([
            'organization_id' => $organization->id, 'created_by' => $educator->id, 'course_id' => $course->id,
            'title' => 'Live class', 'starts_at' => Carbon::now('Asia/Kolkata')->addDay()->utc(),
            'ends_at' => Carbon::now('Asia/Kolkata')->addDay()->addHour()->utc(),
            'meeting_url' => 'https://meet.example.test/live', 'kind' => 'class', 'status' => 'scheduled',
        ]);
        Sanctum::actingAs($educator);

        $this->getJson("/api/v1/organizations/{$organization->id}/dashboard/summary")->assertOk()
            ->assertJsonPath('data.kind', 'educator')
            ->assertJsonPath('data.courses', 1)
            ->assertJsonPath('data.learners', 1)
            ->assertJsonPath('data.classes_this_week', 1)
            ->assertJsonPath('data.reviews_pending', 1);
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
