<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseQuiz;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\QuizAttempt;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssessmentReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_sees_aggregate_published_quiz_results_without_student_identity(): void
    {
        [$organization, $admin, $teacher, $otherTeacher, $student] = $this->workspace();
        $firstCourse = $this->course($organization, $admin, $teacher, 'Algebra');
        $secondCourse = $this->course($organization, $admin, $otherTeacher, 'Biology');
        $quiz = $this->quiz($organization, $admin, $firstCourse, 'Linear equations');
        $this->quiz($organization, $admin, $secondCourse, 'Cell structure');
        $this->quiz($organization, $admin, $firstCourse, 'Draft quiz', 'draft');
        QuizAttempt::query()->create([
            'organization_id' => $organization->id, 'quiz_id' => $quiz->id, 'student_id' => $student->id,
            'attempt_number' => 1, 'answers' => [], 'score' => '8.00', 'max_score' => '10.00',
            'started_at' => now()->subHour(), 'submitted_at' => now(),
        ]);
        QuizAttempt::query()->create([
            'organization_id' => $organization->id, 'quiz_id' => $quiz->id, 'student_id' => $student->id,
            'attempt_number' => 3, 'answers' => [], 'score' => '3.00', 'max_score' => '10.00',
            'review_status' => 'pending', 'started_at' => now()->subHour(), 'submitted_at' => now(),
        ]);
        QuizAttempt::query()->create([
            'organization_id' => $organization->id, 'quiz_id' => $quiz->id, 'student_id' => $student->id,
            'attempt_number' => 2, 'started_at' => now(),
        ]);
        $secondStudent = Student::query()->create([
            'organization_id' => $organization->id, 'name' => 'Learner Two', 'admission_number' => 'S-2', 'status' => 'active',
        ]);
        $assignment = $this->assignment($organization, $admin, $firstCourse, 'Practice set');
        AssignmentSubmission::query()->create([
            'organization_id' => $organization->id, 'assignment_id' => $assignment->id, 'student_id' => $student->id,
            'answer' => 'Submitted answer', 'submitted_at' => now(), 'awarded_points' => '9.00', 'graded_by' => $admin->id, 'graded_at' => now(),
        ]);
        AssignmentSubmission::query()->create([
            'organization_id' => $organization->id, 'assignment_id' => $assignment->id, 'student_id' => $secondStudent->id,
            'answer' => 'Awaiting review', 'submitted_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/organizations/{$organization->id}/reports/assessments")->assertOk()
            ->assertJsonPath('data.summary.published_quizzes', 2)
            ->assertJsonPath('data.summary.submitted_attempts', 2)
            ->assertJsonPath('data.summary.in_progress_attempts', 1)
            ->assertJsonPath('data.summary.awaiting_review', 1)
            ->assertJsonPath('data.summary.learner_quiz_participations', 1)
            ->assertJsonPath('data.summary.average_score_percent', 80)
            ->assertJsonPath('data.quizzes.0.course', 'Algebra')
            ->assertJsonPath('data.quizzes.0.average_score_percent', 80)
            ->assertJsonPath('data.quizzes.0.awaiting_review', 1)
            ->assertJsonPath('data.assignment_summary.published_assignments', 1)
            ->assertJsonPath('data.assignment_summary.submissions', 2)
            ->assertJsonPath('data.assignment_summary.graded_submissions', 1)
            ->assertJsonPath('data.assignment_summary.awaiting_review', 1)
            ->assertJsonPath('data.assignment_summary.learner_submissions', 2)
            ->assertJsonPath('data.assignment_summary.average_score_percent', 90)
            ->assertJsonPath('data.assignments.0.assignment', 'Practice set')
            ->assertJsonMissingPath('data.quizzes.0.student');
    }

    public function test_teacher_sees_only_owned_course_results_and_students_are_denied(): void
    {
        [$organization, $admin, $teacher, $otherTeacher, $student, $teacherUser, $studentUser] = $this->workspace();
        $ownedCourse = $this->course($organization, $admin, $teacher, 'Algebra');
        $otherCourse = $this->course($organization, $admin, $otherTeacher, 'Biology');
        $this->quiz($organization, $admin, $ownedCourse, 'My results');
        $this->quiz($organization, $admin, $otherCourse, 'Other results');
        $this->assignment($organization, $admin, $ownedCourse, 'My assignment');
        $this->assignment($organization, $admin, $otherCourse, 'Other assignment');
        Sanctum::actingAs($teacherUser);
        $this->getJson("/api/v1/organizations/{$organization->id}/reports/assessments")->assertOk()
            ->assertJsonPath('data.summary.published_quizzes', 1)
            ->assertJsonPath('data.quizzes.0.quiz', 'My results')
            ->assertJsonPath('data.assignment_summary.published_assignments', 1)
            ->assertJsonPath('data.assignments.0.assignment', 'My assignment');

        Sanctum::actingAs($studentUser);
        $this->getJson("/api/v1/organizations/{$organization->id}/reports/assessments")->assertForbidden();
    }

    public function test_independent_educator_can_read_aggregate_assessment_results(): void
    {
        $organization = $this->organization('Northstar Academy');
        $educator = User::factory()->create();
        $this->membership($organization, $educator, OrganizationRole::IndependentEducator);
        Sanctum::actingAs($educator);

        $this->getJson("/api/v1/organizations/{$organization->id}/reports/assessments")
            ->assertOk()
            ->assertJsonPath('data.summary.published_quizzes', 0);
    }

    private function workspace(): array
    {
        $organization = $this->organization('Northstar Academy');
        $admin = User::factory()->create();
        $teacherUser = User::factory()->create();
        $studentUser = User::factory()->create();
        $teacher = Teacher::query()->create(['organization_id' => $organization->id, 'user_id' => $teacherUser->id, 'name' => 'Ada Teacher', 'status' => 'active']);
        $otherTeacher = Teacher::query()->create(['organization_id' => $organization->id, 'name' => 'Other Teacher', 'status' => 'active']);
        $student = Student::query()->create(['organization_id' => $organization->id, 'user_id' => $studentUser->id, 'name' => 'Learner', 'admission_number' => 'S-1', 'status' => 'active']);
        $this->membership($organization, $admin, OrganizationRole::Admin);
        $this->membership($organization, $teacherUser, OrganizationRole::Teacher);
        $this->membership($organization, $studentUser, OrganizationRole::Student);

        return [$organization, $admin, $teacher, $otherTeacher, $student, $teacherUser, $studentUser];
    }

    private function organization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name, 'slug' => str()->slug($name).'-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
    }

    private function course(Organization $organization, User $creator, Teacher $teacher, string $title): Course
    {
        return Course::query()->create([
            'organization_id' => $organization->id, 'teacher_id' => $teacher->id, 'created_by' => $creator->id,
            'title' => $title, 'status' => 'published', 'published_at' => now(),
        ]);
    }

    private function quiz(Organization $organization, User $creator, Course $course, string $title, string $status = 'published'): CourseQuiz
    {
        return CourseQuiz::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id, 'created_by' => $creator->id,
            'title' => $title, 'status' => $status, 'max_attempts' => 2,
        ]);
    }

    private function assignment(Organization $organization, User $creator, Course $course, string $title): CourseAssignment
    {
        return CourseAssignment::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id, 'created_by' => $creator->id,
            'title' => $title, 'max_points' => '10.00', 'status' => 'published',
        ]);
    }

    private function membership(Organization $organization, User $user, OrganizationRole $role): void
    {
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role,
            'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
    }
}
