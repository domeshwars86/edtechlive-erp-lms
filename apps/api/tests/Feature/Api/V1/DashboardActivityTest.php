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
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_activity_is_aggregated_by_organization_local_day(): void
    {
        $organization = $this->organization();
        $admin = User::factory()->create();
        $this->membership($organization, $admin, OrganizationRole::Admin);
        $learner = Student::query()->create([
            'organization_id' => $organization->id, 'name' => 'Learner', 'admission_number' => 'S-1', 'status' => 'active',
        ]);
        $course = $this->publishedCourse($organization, $admin);
        CourseEnrollment::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id,
            'student_id' => $learner->id, 'enrolled_by' => $admin->id, 'status' => 'active',
        ]);
        $lesson = CourseLesson::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id,
            'title' => 'Completed lesson', 'sort_order' => 1, 'content' => 'Notes', 'status' => 'published',
        ]);
        $assignment = CourseAssignment::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id,
            'created_by' => $admin->id, 'title' => 'Submitted assignment', 'max_points' => 10, 'status' => 'published',
        ]);
        $localDay = Carbon::now('Asia/Kolkata')->setTime(23, 45);
        CourseLessonProgress::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id,
            'lesson_id' => $lesson->id, 'student_id' => $learner->id, 'completed_at' => $localDay->copy()->utc(),
        ]);
        AssignmentSubmission::query()->create([
            'organization_id' => $organization->id, 'assignment_id' => $assignment->id,
            'student_id' => $learner->id, 'answer' => 'Done', 'submitted_at' => $localDay->copy()->utc(),
        ]);
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/organizations/{$organization->id}/dashboard/activity")->assertOk()
            ->assertJsonPath('data.days.6.date', Carbon::now('Asia/Kolkata')->toDateString())
            ->assertJsonPath('data.days.6.lessons_completed', 1)
            ->assertJsonPath('data.days.6.assignments_submitted', 1)
            ->assertJsonCount(7, 'data.days');
    }

    public function test_student_activity_counts_only_their_active_enrolled_courses(): void
    {
        $organization = $this->organization();
        $studentUser = User::factory()->create();
        $this->membership($organization, $studentUser, OrganizationRole::Student);
        $otherUser = User::factory()->create();
        $profile = Student::query()->create([
            'organization_id' => $organization->id, 'user_id' => $studentUser->id,
            'name' => 'Signed-in learner', 'admission_number' => 'S-1', 'status' => 'active',
        ]);
        $otherProfile = Student::query()->create([
            'organization_id' => $organization->id, 'name' => 'Other learner', 'admission_number' => 'S-2', 'status' => 'active',
        ]);
        $course = $this->publishedCourse($organization, $otherUser);
        foreach ([$profile, $otherProfile] as $learner) {
            CourseEnrollment::query()->create([
                'organization_id' => $organization->id, 'course_id' => $course->id,
                'student_id' => $learner->id, 'enrolled_by' => $otherUser->id, 'status' => 'active',
            ]);
        }
        $lesson = CourseLesson::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id,
            'title' => 'Completed lesson', 'sort_order' => 1, 'content' => 'Notes', 'status' => 'published',
        ]);
        $assignment = CourseAssignment::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id,
            'created_by' => $otherUser->id, 'title' => 'Submitted assignment', 'max_points' => 10, 'status' => 'published',
        ]);
        foreach ([$profile, $otherProfile] as $learner) {
            CourseLessonProgress::query()->create([
                'organization_id' => $organization->id, 'course_id' => $course->id,
                'lesson_id' => $lesson->id, 'student_id' => $learner->id, 'completed_at' => now(),
            ]);
            AssignmentSubmission::query()->create([
                'organization_id' => $organization->id, 'assignment_id' => $assignment->id,
                'student_id' => $learner->id, 'answer' => 'Done', 'submitted_at' => now(),
            ]);
        }
        Sanctum::actingAs($studentUser);

        $this->getJson("/api/v1/organizations/{$organization->id}/dashboard/activity")->assertOk()
            ->assertJsonPath('data.days.6.lessons_completed', 1)
            ->assertJsonPath('data.days.6.assignments_submitted', 1)
            ->assertJsonMissingPath('data.days.6.student_names');
    }

    private function publishedCourse(Organization $organization, User $creator): Course
    {
        return Course::query()->create([
            'organization_id' => $organization->id, 'created_by' => $creator->id,
            'title' => 'Published course', 'status' => 'published', 'published_at' => now(),
        ]);
    }

    private function membership(Organization $organization, User $user, OrganizationRole $role): void
    {
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role,
            'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
    }

    private function organization(): Organization
    {
        return Organization::query()->create([
            'name' => 'Northstar Academy', 'slug' => 'northstar-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
    }
}
