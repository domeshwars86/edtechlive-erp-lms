<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Course;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_modules_are_ordered_and_only_published_student_modules_are_visible(): void
    {
        [$admin, $organization, $studentUser, $student] = $this->workspace();
        Sanctum::actingAs($admin);
        $base = "/api/v1/organizations/{$organization->id}";
        $courseId = $this->postJson("{$base}/courses", ['title' => 'Science'])->assertCreated()->json('data.id');
        $moduleId = $this->postJson("{$base}/courses/{$courseId}/modules", ['title' => 'Foundations'])
            ->assertCreated()->assertJsonPath('data.sort_order', 1)->json('data.id');
        $emptyModuleId = $this->postJson("{$base}/courses/{$courseId}/modules", ['title' => 'Advanced topics'])
            ->assertCreated()->json('data.id');
        $otherCourseId = $this->postJson("{$base}/courses", ['title' => 'Other course'])->assertCreated()->json('data.id');
        $otherModuleId = $this->postJson("{$base}/courses/{$otherCourseId}/modules", ['title' => 'Foreign module'])->assertCreated()->json('data.id');

        $this->patchJson("{$base}/courses/{$courseId}/modules/{$moduleId}", ['title' => 'Getting started', 'sort_order' => 2])
            ->assertOk()->assertJsonPath('data.title', 'Getting started')->assertJsonPath('data.sort_order', 2);
        $this->postJson("{$base}/courses/{$courseId}/lessons", [
            'title' => 'Welcome', 'module_id' => $otherModuleId, 'content' => 'Study notes', 'status' => 'published',
        ])->assertUnprocessable()->assertJsonValidationErrors('module_id');
        $this->postJson("{$base}/courses/{$courseId}/lessons", [
            'title' => 'Welcome', 'module_id' => $moduleId, 'content' => 'Study notes', 'status' => 'published',
        ])->assertCreated();
        $this->patchJson("{$base}/courses/{$courseId}", ['status' => 'published'])->assertOk();
        $this->postJson("{$base}/courses/{$courseId}/enrollments", ['student_ids' => [$student->id]])->assertOk();

        Sanctum::actingAs($studentUser);
        $this->getJson("{$base}/courses/{$courseId}/modules")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Getting started')
            ->assertJsonMissing(['title' => 'Advanced topics']);
        $this->getJson("{$base}/courses/{$courseId}/lessons")->assertOk()
            ->assertJsonPath('data.0.module.title', 'Getting started');
        $this->assertDatabaseHas('course_modules', ['id' => $emptyModuleId, 'course_id' => $courseId]);
    }

    public function test_manager_can_publish_course_after_publishing_lesson_and_enroll_student(): void
    {
        [$admin, $organization, $studentUser, $student] = $this->workspace();
        Sanctum::actingAs($admin);
        $base = "/api/v1/organizations/{$organization->id}";
        $courseId = $this->postJson("{$base}/courses", [
            'title' => 'Foundations of Science', 'level' => 'Grade 6', 'description' => 'First unit',
        ])->assertCreated()->json('data.id');
        $this->postJson("{$base}/courses/{$courseId}/lessons", [
            'title' => 'Unsafe resource', 'external_url' => 'http://example.org/lesson', 'status' => 'published',
        ])->assertUnprocessable()->assertJsonValidationErrors('external_url');
        $this->patchJson("{$base}/courses/{$courseId}", ['status' => 'published'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $lessonId = $this->postJson("{$base}/courses/{$courseId}/lessons", [
            'title' => 'Observing matter', 'content' => 'Read and take notes.', 'status' => 'published',
        ])->assertCreated()->json('data.id');
        $this->patchJson("{$base}/courses/{$courseId}", ['status' => 'published'])->assertOk()
            ->assertJsonPath('data.status', 'published');
        $this->postJson("{$base}/courses/{$courseId}/enrollments", ['student_ids' => [$student->id]])->assertOk();

        Sanctum::actingAs($studentUser);
        $this->getJson("{$base}/courses")->assertOk()->assertJsonPath('data.0.id', $courseId);
        $this->getJson("{$base}/courses/{$courseId}/lessons")->assertOk()
            ->assertJsonPath('data.0.id', $lessonId)->assertJsonPath('data.0.content', 'Read and take notes.');
        $otherStudent = Student::query()->create([
            'organization_id' => $organization->id, 'name' => 'Other Learner', 'admission_number' => 'S-200', 'status' => 'active',
        ]);
        $otherUser = User::factory()->create(['email' => 'otherlearner@example.com']);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $otherUser->id,
            'role' => OrganizationRole::Student, 'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
        $otherStudent->update(['user_id' => $otherUser->id]);
        Sanctum::actingAs($otherUser);
        $this->getJson("{$base}/courses")->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("{$base}/courses/{$courseId}/lessons")->assertNotFound();
    }

    public function test_teacher_can_edit_only_assigned_courses(): void
    {
        [$admin, $organization, , , $teacherUser, $teacher] = $this->workspace(withTeacher: true);
        $course = Course::query()->create([
            'organization_id' => $organization->id, 'teacher_id' => $teacher->id, 'created_by' => $admin->id,
            'title' => 'My course', 'status' => 'draft',
        ]);
        Sanctum::actingAs($teacherUser);
        $base = "/api/v1/organizations/{$organization->id}";
        $this->getJson("{$base}/courses")->assertOk()->assertJsonPath('data.0.id', $course->id);
        $this->postJson("{$base}/courses/{$course->id}/lessons", ['title' => 'Welcome', 'content' => 'Start here'])
            ->assertCreated();
        $this->postJson("{$base}/courses/{$course->id}/modules", ['title' => 'My module'])->assertCreated();
        $this->patchJson("{$base}/courses/{$course->id}", ['title' => 'Updated course'])->assertOk();

        $otherTeacher = Teacher::query()->create([
            'organization_id' => $organization->id, 'name' => 'Other Teacher', 'email' => 'otherteacher@example.com', 'status' => 'active',
        ]);
        $otherCourse = Course::query()->create([
            'organization_id' => $organization->id, 'teacher_id' => $otherTeacher->id, 'created_by' => $admin->id,
            'title' => 'Private course', 'status' => 'draft',
        ]);
        $this->patchJson("{$base}/courses/{$otherCourse->id}", ['title' => 'Stolen'])->assertNotFound();
        $this->getJson("{$base}/courses/{$otherCourse->id}/modules")->assertNotFound();
        $this->postJson("{$base}/courses/{$otherCourse->id}/modules", ['title' => 'Unauthorized'])->assertNotFound();
    }

    private function workspace(bool $withTeacher = false): array
    {
        $admin = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Northstar Academy', 'slug' => 'northstar-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $admin->id, 'role' => OrganizationRole::Admin,
            'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
        $studentUser = User::factory()->create(['email' => 'learner@example.com']);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $studentUser->id, 'role' => OrganizationRole::Student,
            'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
        $student = Student::query()->create([
            'organization_id' => $organization->id, 'user_id' => $studentUser->id,
            'name' => 'Learner', 'admission_number' => 'S-100', 'status' => 'active',
        ]);
        if (! $withTeacher) {
            return [$admin, $organization, $studentUser, $student];
        }
        $teacherUser = User::factory()->create(['email' => 'teacher@example.com']);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $teacherUser->id, 'role' => OrganizationRole::Teacher,
            'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
        $teacher = Teacher::query()->create([
            'organization_id' => $organization->id, 'user_id' => $teacherUser->id,
            'name' => 'Course Teacher', 'email' => $teacherUser->email, 'status' => 'active',
        ]);

        return [$admin, $organization, $studentUser, $student, $teacherUser, $teacher];
    }
}
