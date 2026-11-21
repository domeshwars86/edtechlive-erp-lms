<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseLesson;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Student;
use App\Models\Subject;
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

    public function test_publishing_a_lesson_in_a_published_course_notifies_active_enrollees(): void
    {
        [$admin, $organization, , $student] = $this->workspace();
        Sanctum::actingAs($admin);
        $base = "/api/v1/organizations/{$organization->id}";
        $courseId = $this->postJson("{$base}/courses", ['title' => 'Living Systems'])->assertCreated()->json('data.id');
        $this->postJson("{$base}/courses/{$courseId}/lessons", [
            'title' => 'Course introduction', 'content' => 'Start here.', 'status' => 'published',
        ])->assertCreated();
        $this->patchJson("{$base}/courses/{$courseId}", ['status' => 'published'])->assertOk();
        $this->postJson("{$base}/courses/{$courseId}/enrollments", ['student_ids' => [$student->id]])->assertOk();

        $this->postJson("{$base}/courses/{$courseId}/lessons", [
            'title' => 'Cell structure', 'content' => 'Cells form the basis of life.', 'status' => 'published',
        ])->assertCreated();

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $student->user_id,
            'type' => 'App\\Notifications\\WorkspaceNotification',
            'data->kind' => 'learning_lesson_published',
        ]);
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

    public function test_public_listing_is_opt_in_only_after_publication_and_linked_teacher_can_toggle_it(): void
    {
        [$admin, $organization, , , $teacherUser, $teacher] = $this->workspace(withTeacher: true);
        $course = Course::query()->create([
            'organization_id' => $organization->id, 'teacher_id' => $teacher->id, 'created_by' => $admin->id,
            'title' => 'Public foundations', 'status' => 'draft',
        ]);
        $this->assertSame('public-foundations', $course->slug);
        $this->assertFalse($course->refresh()->is_public_listing);
        $duplicateTitle = Course::query()->create([
            'organization_id' => $organization->id, 'teacher_id' => $teacher->id, 'created_by' => $admin->id,
            'title' => 'Public foundations', 'status' => 'draft',
        ]);
        $this->assertSame('public-foundations-2', $duplicateTitle->slug);
        $base = "/api/v1/organizations/{$organization->id}";

        Sanctum::actingAs($teacherUser);
        $this->patchJson("{$base}/courses/{$course->id}", ['is_public_listing' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('is_public_listing');
        $this->postJson("{$base}/courses/{$course->id}/lessons", [
            'title' => 'First lesson', 'content' => 'Private body must not be in the catalog.', 'external_url' => 'https://example.org/private-room', 'status' => 'published',
        ])->assertCreated();
        $this->patchJson("{$base}/courses/{$course->id}", ['status' => 'published'])->assertOk();
        $this->patchJson("{$base}/courses/{$course->id}", ['is_public_listing' => true])
            ->assertOk()->assertJsonPath('data.is_public_listing', true);
        $this->patchJson("{$base}/courses/{$course->id}", ['status' => 'archived'])
            ->assertOk()->assertJsonPath('data.is_public_listing', false);

        $otherTeacherUser = User::factory()->create();
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $otherTeacherUser->id,
            'role' => OrganizationRole::Teacher, 'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
        Teacher::query()->create([
            'organization_id' => $organization->id, 'user_id' => $otherTeacherUser->id,
            'name' => 'Unassigned Teacher', 'status' => 'active',
        ]);
        Sanctum::actingAs($otherTeacherUser);
        $this->patchJson("{$base}/courses/{$course->id}", ['is_public_listing' => true])->assertNotFound();

        Sanctum::actingAs($admin);
        $this->patchJson("{$base}/courses/{$course->id}", ['status' => 'published'])->assertOk()
            ->assertJsonPath('data.is_public_listing', false);
    }

    public function test_public_catalog_filters_paginates_returns_facets_and_detail_omits_private_fields(): void
    {
        [$admin, $organization, , $student] = $this->workspace();
        $science = Subject::query()->create([
            'organization_id' => $organization->id, 'name' => 'Science', 'code' => 'SCI', 'is_active' => true,
        ]);
        $arts = Subject::query()->create([
            'organization_id' => $organization->id, 'name' => 'Arts', 'code' => 'ART', 'is_active' => true,
        ]);
        $first = $this->makePublicCourse($organization, $admin, $science, 'Physics basics', 'Grade 6', 'Matter and energy.');
        CourseLesson::query()->create([
            'organization_id' => $organization->id, 'course_id' => $first->id,
            'title' => 'Second lesson', 'sort_order' => 2, 'content' => 'SECRET LESSON BODY',
            'external_url' => 'https://example.org/private-lesson', 'status' => 'published',
        ]);
        CourseLesson::query()->create([
            'organization_id' => $organization->id, 'course_id' => $first->id,
            'title' => 'First lesson', 'sort_order' => 1, 'content' => 'ANOTHER SECRET BODY', 'status' => 'published',
        ]);
        $second = $this->makePublicCourse($organization, $admin, $arts, 'Creative writing', 'Grade 8', 'A writing course.');
        CourseLesson::query()->create([
            'organization_id' => $organization->id, 'course_id' => $second->id,
            'title' => 'Writing lesson', 'sort_order' => 1, 'content' => 'Writing notes.', 'status' => 'published',
        ]);
        CourseEnrollment::query()->create([
            'organization_id' => $organization->id, 'course_id' => $first->id, 'student_id' => $student->id,
            'enrolled_by' => $admin->id, 'status' => 'active',
        ]);

        $base = '/api/v1/public/courses';
        $this->getJson($base.'?q=physics')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Physics basics')
            ->assertJsonPath('data.0.published_lesson_count', 2)
            ->assertJsonPath('filters.levels.0', 'Grade 6')->assertJsonPath('filters.levels.1', 'Grade 8')
            ->assertJsonPath('filters.subjects.0.name', 'Arts')->assertJsonPath('filters.subjects.1.name', 'Science')
            ->assertJsonMissingPath('data.0.id')->assertJsonMissingPath('data.0.teacher')
            ->assertJsonMissingPath('data.0.status')->assertJsonMissingPath('data.0.organization.id');
        $this->getJson($base.'?level='.urlencode('grade 8').'&subject=arts')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', $second->slug);
        $this->getJson($base.'?per_page=1&page=2')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(1, 'data');

        $detailUrl = '/api/v1/public/organizations/'.$organization->slug.'/courses/'.$first->slug;
        $this->getJson($detailUrl)->assertOk()
            ->assertJsonPath('data.organization.name', $organization->name)
            ->assertJsonPath('data.organization.slug', $organization->slug)
            ->assertJsonPath('data.subject', 'Science')
            ->assertJsonPath('data.lessons.0.title', 'First lesson')
            ->assertJsonPath('data.lessons.0.sort_order', 1)
            ->assertJsonPath('data.lessons.1.title', 'Second lesson')
            ->assertJsonMissingPath('data.lessons.0.id')
            ->assertJsonMissingPath('data.lessons.0.content')
            ->assertJsonMissingPath('data.lessons.1.external_url')
            ->assertJsonMissingPath('data.enrollments')
            ->assertJsonMissing(['SECRET LESSON BODY', 'ANOTHER SECRET BODY', 'https://example.org/private-lesson']);
    }

    public function test_public_catalog_excludes_unlisted_draft_empty_and_suspended_courses(): void
    {
        [$admin, $organization] = $this->workspace();
        $subject = Subject::query()->create([
            'organization_id' => $organization->id, 'name' => 'Science', 'code' => 'SCI', 'is_active' => true,
        ]);
        $visible = $this->makePublicCourse($organization, $admin, $subject, 'Visible course', 'Grade 5', 'Public.');
        CourseLesson::query()->create([
            'organization_id' => $organization->id, 'course_id' => $visible->id,
            'title' => 'Lesson', 'content' => 'Lesson.', 'status' => 'published',
        ]);
        $unlisted = Course::query()->create([
            'organization_id' => $organization->id, 'created_by' => $admin->id, 'subject_id' => $subject->id,
            'title' => 'Unlisted course', 'status' => 'published', 'is_public_listing' => false,
        ]);
        CourseLesson::query()->create([
            'organization_id' => $organization->id, 'course_id' => $unlisted->id,
            'title' => 'Lesson', 'content' => 'Lesson.', 'status' => 'published',
        ]);
        $draft = Course::query()->create([
            'organization_id' => $organization->id, 'created_by' => $admin->id, 'subject_id' => $subject->id,
            'title' => 'Draft course', 'status' => 'draft', 'is_public_listing' => true,
        ]);
        CourseLesson::query()->create([
            'organization_id' => $organization->id, 'course_id' => $draft->id,
            'title' => 'Lesson', 'content' => 'Lesson.', 'status' => 'published',
        ]);
        $empty = $this->makePublicCourse($organization, $admin, $subject, 'No published lessons', 'Grade 5', 'No lesson.');
        CourseLesson::query()->create([
            'organization_id' => $organization->id, 'course_id' => $empty->id,
            'title' => 'Draft lesson', 'content' => 'Hidden.', 'status' => 'draft',
        ]);

        $suspended = Organization::query()->create([
            'name' => 'Suspended Academy', 'slug' => 'suspended-academy', 'type' => OrganizationType::School,
            'status' => 'suspended', 'timezone' => 'Asia/Kolkata',
        ]);
        $suspendedSubject = Subject::query()->create([
            'organization_id' => $suspended->id, 'name' => 'Math', 'code' => 'MATH', 'is_active' => true,
        ]);
        $suspendedCourse = $this->makePublicCourse($suspended, $admin, $suspendedSubject, 'Suspended course', 'Grade 5', 'Hidden.');
        CourseLesson::query()->create([
            'organization_id' => $suspended->id, 'course_id' => $suspendedCourse->id,
            'title' => 'Lesson', 'content' => 'Hidden.', 'status' => 'published',
        ]);

        $this->getJson('/api/v1/public/courses')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', $visible->slug);
        $this->getJson('/api/v1/public/organizations/'.$suspended->slug.'/courses/'.$suspendedCourse->slug)->assertNotFound();
    }

    private function makePublicCourse(Organization $organization, User $creator, Subject $subject, string $title, string $level, string $description): Course
    {
        return Course::query()->create([
            'organization_id' => $organization->id, 'created_by' => $creator->id, 'subject_id' => $subject->id,
            'title' => $title, 'level' => $level, 'description' => $description,
            'status' => 'published', 'published_at' => now(), 'is_public_listing' => true,
        ]);
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
