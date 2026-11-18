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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrolled_student_can_mark_lessons_complete_and_read_course_progress(): void
    {
        [$studentUser, $organization, $course, $lessons] = $this->workspace();
        $studentId = Student::query()->where('user_id', $studentUser->id)->value('id');
        Sanctum::actingAs($studentUser);
        $base = "/api/v1/organizations/{$organization->id}";

        $this->getJson("{$base}/courses/{$course->id}/progress")->assertOk()
            ->assertJsonPath('summary.completed', 0)
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.percent', 0);
        $draft = CourseLesson::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id,
            'title' => 'Not yet released', 'sort_order' => 3, 'content' => 'Private draft', 'status' => 'draft',
        ]);
        $this->putJson("{$base}/courses/{$course->id}/lessons/{$draft->id}/progress", ['completed' => true])->assertNotFound();

        $this->putJson("{$base}/courses/{$course->id}/lessons/{$lessons[0]->id}/progress", ['completed' => true])
            ->assertOk()->assertJsonPath('data.lesson_id', $lessons[0]->id);
        $this->getJson("{$base}/courses/{$course->id}/progress")->assertOk()
            ->assertJsonPath('summary.completed', 1)
            ->assertJsonPath('summary.percent', 50)
            ->assertJsonPath('data.0.lesson_id', $lessons[0]->id);

        $this->putJson("{$base}/courses/{$course->id}/lessons/{$lessons[0]->id}/progress", ['completed' => false])->assertOk();
        $this->assertDatabaseHas('course_lesson_progress', ['lesson_id' => $lessons[0]->id, 'student_id' => $studentId, 'completed_at' => null]);
    }

    public function test_progress_is_hidden_from_unenrolled_users_and_unpublished_lessons(): void
    {
        [$studentUser, $organization, $course, $lessons] = $this->workspace();
        $course->enrollments()->delete();
        Sanctum::actingAs($studentUser);
        $base = "/api/v1/organizations/{$organization->id}";

        $this->getJson("{$base}/courses/{$course->id}/progress")->assertNotFound();
        $this->putJson("{$base}/courses/{$course->id}/lessons/{$lessons[0]->id}/progress", ['completed' => true])->assertNotFound();
    }

    private function workspace(): array
    {
        $admin = User::factory()->create();
        $studentUser = User::factory()->create(['email' => 'learner@example.test']);
        $organization = Organization::query()->create([
            'name' => 'Northstar Academy', 'slug' => 'northstar-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $studentUser->id,
            'role' => OrganizationRole::Student, 'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
        $student = Student::query()->create([
            'organization_id' => $organization->id, 'user_id' => $studentUser->id,
            'name' => 'Learner One', 'admission_number' => 'S-100', 'status' => 'active',
        ]);
        $course = Course::query()->create([
            'organization_id' => $organization->id, 'created_by' => $admin->id,
            'title' => 'Earth Systems', 'status' => 'published', 'published_at' => now(),
        ]);
        CourseEnrollment::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id, 'student_id' => $student->id,
            'enrolled_by' => $admin->id, 'status' => 'active',
        ]);
        $lessons = collect(['Water cycle', 'Climate patterns'])->map(fn (string $title, int $index): CourseLesson => CourseLesson::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id,
            'title' => $title, 'sort_order' => $index + 1, 'content' => 'Study notes', 'status' => 'published',
        ]))->all();

        return [$studentUser, $organization, $course, $lessons];
    }
}
