<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseEnrollment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrolled_student_submits_and_assigned_teacher_grades_response(): void
    {
        [$admin, $teacherUser, $studentUser, $organization, $course, $assignment] = $this->classroom();
        $base = "/api/v1/organizations/{$organization->id}";

        Sanctum::actingAs($studentUser);
        $this->getJson("{$base}/courses/{$course->id}/assignments")->assertOk()
            ->assertJsonPath('data.0.id', $assignment->id)->assertJsonPath('data.0.submission', null);
        $submissionId = $this->postJson("{$base}/assignments/{$assignment->id}/submissions", ['answer' => 'The water cycle moves water through the environment.'])
            ->assertCreated()->json('data.id');

        Sanctum::actingAs($teacherUser);
        $this->getJson("{$base}/assignments/{$assignment->id}/submissions")->assertOk()
            ->assertJsonPath('data.0.student.name', 'Learner One')->assertJsonPath('data.0.answer', 'The water cycle moves water through the environment.');
        $this->patchJson("{$base}/submissions/{$submissionId}/grade", ['awarded_points' => 21, 'feedback' => 'Good work'])
            ->assertUnprocessable()->assertJsonValidationErrors('awarded_points');
        $this->patchJson("{$base}/submissions/{$submissionId}/grade", ['awarded_points' => 18.5, 'feedback' => 'Clear explanation.'])
            ->assertOk()->assertJsonPath('data.awarded_points', '18.50')->assertJsonPath('data.feedback', 'Clear explanation.');

        Sanctum::actingAs($studentUser);
        $this->postJson("{$base}/assignments/{$assignment->id}/submissions", ['answer' => 'Changed after grading'])
            ->assertStatus(409);
        $this->assertDatabaseHas('assignment_submissions', [
            'id' => $submissionId, 'student_id' => Student::query()->where('user_id', $studentUser->id)->value('id'), 'awarded_points' => '18.50',
        ]);
    }

    public function test_student_cannot_access_or_submit_assignment_without_enrollment(): void
    {
        [, , , $organization, $course, $assignment] = $this->classroom();
        $otherUser = User::factory()->create(['email' => 'other@example.com']);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $otherUser->id, 'role' => OrganizationRole::Student,
            'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
        $otherStudent = Student::query()->create([
            'organization_id' => $organization->id, 'user_id' => $otherUser->id,
            'name' => 'Other Learner', 'admission_number' => 'S-200', 'status' => 'active',
        ]);
        Sanctum::actingAs($otherUser);
        $base = "/api/v1/organizations/{$organization->id}";

        $this->getJson("{$base}/courses/{$course->id}/assignments")->assertNotFound();
        $this->postJson("{$base}/assignments/{$assignment->id}/submissions", ['answer' => 'Unauthorized'])
            ->assertNotFound();
        $this->assertDatabaseMissing('assignment_submissions', ['student_id' => $otherStudent->id, 'assignment_id' => $assignment->id]);
    }

    public function test_assignments_cannot_be_published_before_the_course(): void
    {
        [$admin, , , $organization] = $this->classroom();
        $draft = Course::query()->create([
            'organization_id' => $organization->id, 'created_by' => $admin->id,
            'title' => 'Draft course', 'status' => 'draft',
        ]);
        Sanctum::actingAs($admin);
        $base = "/api/v1/organizations/{$organization->id}";
        $this->postJson("{$base}/courses/{$draft->id}/assignments", [
            'title' => 'Unpublished work', 'status' => 'published',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("{$base}/courses/{$draft->id}/assignments", ['title' => 'Draft work'])
            ->assertCreated()->assertJsonPath('data.status', 'draft');
    }

    private function classroom(): array
    {
        $admin = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Northstar Academy', 'slug' => 'northstar-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
        $teacherUser = User::factory()->create(['email' => 'teacher@example.com']);
        $studentUser = User::factory()->create(['email' => 'learner@example.com']);
        foreach ([[$admin, OrganizationRole::Admin], [$teacherUser, OrganizationRole::Teacher], [$studentUser, OrganizationRole::Student]] as [$user, $role]) {
            OrganizationMembership::query()->create([
                'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role,
                'status' => MembershipStatus::Active, 'accepted_at' => now(),
            ]);
        }
        $teacher = Teacher::query()->create([
            'organization_id' => $organization->id, 'user_id' => $teacherUser->id,
            'name' => 'Teacher One', 'email' => $teacherUser->email, 'status' => 'active',
        ]);
        $student = Student::query()->create([
            'organization_id' => $organization->id, 'user_id' => $studentUser->id,
            'name' => 'Learner One', 'admission_number' => 'S-100', 'status' => 'active',
        ]);
        $course = Course::query()->create([
            'organization_id' => $organization->id, 'teacher_id' => $teacher->id, 'created_by' => $admin->id,
            'title' => 'Earth Systems', 'status' => 'published', 'published_at' => now(),
        ]);
        CourseEnrollment::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id, 'student_id' => $student->id,
            'enrolled_by' => $admin->id, 'status' => 'active',
        ]);
        $assignment = CourseAssignment::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id, 'created_by' => $teacherUser->id,
            'title' => 'Explain the water cycle', 'instructions' => 'Use your own words.', 'max_points' => 20, 'status' => 'published',
        ]);

        return [$admin, $teacherUser, $studentUser, $organization, $course, $assignment];
    }
}
