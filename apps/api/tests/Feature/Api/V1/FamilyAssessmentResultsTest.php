<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseEnrollment;
use App\Models\CourseLesson;
use App\Models\CourseLessonProgress;
use App\Models\CourseQuiz;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\QuizAttempt;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FamilyAssessmentResultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_can_see_only_linked_child_completed_results_without_submission_content(): void
    {
        [$parent, $organization, $child, $unlinked] = $this->family();
        $course = Course::query()->create(['organization_id' => $organization->id, 'created_by' => $parent->id, 'title' => 'Biology', 'status' => 'published']);
        $quiz = CourseQuiz::query()->create(['organization_id' => $organization->id, 'course_id' => $course->id, 'created_by' => $parent->id, 'title' => 'Cells quiz', 'status' => 'published']);
        QuizAttempt::query()->create(['organization_id' => $organization->id, 'quiz_id' => $quiz->id, 'student_id' => $child->id, 'attempt_number' => 1, 'answers' => [['secret' => 'answer key detail']], 'score' => 8, 'max_score' => 10, 'started_at' => now(), 'submitted_at' => now()]);
        $assignment = CourseAssignment::query()->create(['organization_id' => $organization->id, 'course_id' => $course->id, 'created_by' => $parent->id, 'title' => 'Cell report', 'max_points' => 20, 'status' => 'published']);
        AssignmentSubmission::query()->create(['organization_id' => $organization->id, 'assignment_id' => $assignment->id, 'student_id' => $child->id, 'answer' => 'Private submitted work', 'submitted_at' => now(), 'awarded_points' => 18, 'feedback' => 'Clear explanation', 'graded_by' => $parent->id, 'graded_at' => now()]);
        $unlinkedQuiz = CourseQuiz::query()->create(['organization_id' => $organization->id, 'course_id' => $course->id, 'created_by' => $parent->id, 'title' => 'Unlinked result', 'status' => 'published']);
        QuizAttempt::query()->create(['organization_id' => $organization->id, 'quiz_id' => $unlinkedQuiz->id, 'student_id' => $unlinked->id, 'attempt_number' => 1, 'answers' => [], 'score' => 1, 'max_score' => 1, 'started_at' => now(), 'submitted_at' => now()]);

        Sanctum::actingAs($parent);
        $this->getJson("/api/v1/organizations/{$organization->id}/my/children/{$child->id}/results")
            ->assertOk()->assertJsonCount(1, 'data.quizzes')->assertJsonCount(1, 'data.assignments')
            ->assertJsonPath('data.quizzes.0.title', 'Cells quiz')
            ->assertJsonPath('data.assignments.0.feedback', 'Clear explanation')
            ->assertJsonMissing(['Private submitted work', 'answer key detail', 'Unlinked result']);
        $this->getJson("/api/v1/organizations/{$organization->id}/my/children/{$unlinked->id}/results")->assertNotFound();
    }

    public function test_student_can_view_own_results_but_parent_role_is_required_for_child_route(): void
    {
        [$parent, $organization, $child] = $this->family();
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $child->user_id, 'role' => OrganizationRole::Student, 'status' => MembershipStatus::Active, 'accepted_at' => now()]);
        Sanctum::actingAs($child->user);
        $this->getJson("/api/v1/organizations/{$organization->id}/my/student/results")->assertOk()->assertJsonPath('data.quizzes', [])->assertJsonPath('data.assignments', []);
        $this->getJson("/api/v1/organizations/{$organization->id}/my/student/progress")->assertOk()->assertJsonPath('data', []);
        $this->getJson("/api/v1/organizations/{$organization->id}/my/children/{$child->id}/results")->assertForbidden();
    }

    public function test_parent_sees_published_course_progress_for_only_an_explicitly_linked_child(): void
    {
        [$parent, $organization, $child, $unlinked] = $this->family();
        $course = Course::query()->create(['organization_id' => $organization->id, 'created_by' => $parent->id, 'title' => 'Earth Science', 'status' => 'published']);
        CourseEnrollment::query()->create(['organization_id' => $organization->id, 'course_id' => $course->id, 'student_id' => $child->id, 'enrolled_by' => $parent->id, 'status' => 'active']);
        $lessons = collect(['Water cycle', 'Climate'])->map(fn (string $title, int $index): CourseLesson => CourseLesson::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id,
            'title' => $title, 'sort_order' => $index + 1, 'content' => 'Lesson notes', 'status' => 'published',
        ]));
        CourseLessonProgress::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id,
            'lesson_id' => $lessons->first()->id, 'student_id' => $child->id, 'completed_at' => now(),
        ]);

        Sanctum::actingAs($parent);
        $this->getJson("/api/v1/organizations/{$organization->id}/my/children/{$child->id}/progress")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Earth Science')
            ->assertJsonPath('data.0.completed_lessons', 1)->assertJsonPath('data.0.completion_percent', 50)
            ->assertJsonPath('summary.completion_percent', 50);
        $this->getJson("/api/v1/organizations/{$organization->id}/my/children/{$unlinked->id}/progress")->assertNotFound();
    }

    private function family(): array
    {
        $parent = User::factory()->create();
        $childUser = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'Family Academy', 'slug' => 'family-'.str()->random(7), 'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $parent->id, 'role' => OrganizationRole::Parent, 'status' => MembershipStatus::Active, 'accepted_at' => now()]);
        $child = Student::query()->create(['organization_id' => $organization->id, 'user_id' => $childUser->id, 'name' => 'Learner', 'admission_number' => 'F-001', 'status' => 'active']);
        $unlinked = Student::query()->create(['organization_id' => $organization->id, 'name' => 'Other learner', 'admission_number' => 'F-002', 'status' => 'active']);
        StudentGuardian::query()->create(['organization_id' => $organization->id, 'student_id' => $child->id, 'guardian_user_id' => $parent->id, 'linked_by' => $parent->id, 'relationship' => 'parent', 'linked_at' => now()]);

        return [$parent, $organization, $child, $unlinked];
    }
}
