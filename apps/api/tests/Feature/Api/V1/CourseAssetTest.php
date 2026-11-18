<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Course;
use App\Models\CourseAsset;
use App\Models\CourseEnrollment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_material_is_private_until_published_and_download_is_enrollment_scoped(): void
    {
        Storage::fake('private');
        [$admin, $studentUser, $organization, $course, $student] = $this->workspace();
        $base = "/api/v1/organizations/{$organization->id}";

        Sanctum::actingAs($admin);
        $response = $this->post("{$base}/courses/{$course->id}/assets", [
            'title' => 'Water cycle notes',
            'file' => UploadedFile::fake()->createWithContent('water-cycle.pdf', "%PDF-1.4\nPrivate study notes"),
        ])->assertCreated();
        $assetId = $response->json('data.id');
        $asset = CourseAsset::query()->findOrFail($assetId);
        Storage::disk('private')->assertExists($asset->storage_path);
        $this->assertDatabaseHas('course_assets', ['id' => $assetId, 'is_published' => false]);

        Sanctum::actingAs($studentUser);
        $this->getJson("{$base}/courses/{$course->id}/assets")->assertOk()->assertJsonPath('data', []);
        $this->get("{$base}/assets/{$assetId}/download")->assertNotFound();

        Sanctum::actingAs($admin);
        $this->patchJson("{$base}/assets/{$assetId}", ['is_published' => true])->assertOk();
        Sanctum::actingAs($studentUser);
        $this->getJson("{$base}/courses/{$course->id}/assets")->assertOk()
            ->assertJsonPath('data.0.title', 'Water cycle notes');
        $this->get("{$base}/assets/{$assetId}/download")->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('%PDF-1.4', Storage::disk('private')->get($asset->storage_path));
        $this->assertDatabaseHas('course_enrollments', ['course_id' => $course->id, 'student_id' => $student->id, 'status' => 'active']);
    }

    public function test_unenrolled_student_cannot_view_course_materials(): void
    {
        Storage::fake('private');
        [, , $organization, $course] = $this->workspace();
        $otherUser = User::factory()->create(['email' => 'other@example.com']);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $otherUser->id,
            'role' => OrganizationRole::Student, 'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
        Student::query()->create([
            'organization_id' => $organization->id, 'user_id' => $otherUser->id,
            'name' => 'Other Learner', 'admission_number' => 'S-200', 'status' => 'active',
        ]);
        Sanctum::actingAs($otherUser);
        $this->getJson("/api/v1/organizations/{$organization->id}/courses/{$course->id}/assets")->assertNotFound();
    }

    private function workspace(): array
    {
        $admin = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Northstar Academy', 'slug' => 'northstar-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
        $studentUser = User::factory()->create(['email' => 'learner@example.com']);
        foreach ([[$admin, OrganizationRole::Admin], [$studentUser, OrganizationRole::Student]] as [$user, $role]) {
            OrganizationMembership::query()->create([
                'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role,
                'status' => MembershipStatus::Active, 'accepted_at' => now(),
            ]);
        }
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

        return [$admin, $studentUser, $organization, $course, $student];
    }
}
