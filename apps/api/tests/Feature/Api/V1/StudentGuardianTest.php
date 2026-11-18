<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentGuardianTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_manage_only_active_verified_parent_links_inside_their_organization(): void
    {
        [$admin, $parent, $otherParent, $student, $organization] = $this->workspace();
        $unverifiedParent = User::factory()->unverified()->create(['email' => 'unverified-parent@example.test']);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $unverifiedParent->id,
            'role' => OrganizationRole::Parent, 'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
        Sanctum::actingAs($admin);
        $base = "/api/v1/organizations/{$organization->id}";

        $this->getJson("{$base}/parent-accounts")->assertOk()->assertJsonPath('data.0.id', $parent->id)
            ->assertJsonMissing(['id' => $unverifiedParent->id]);
        $this->postJson("{$base}/students/{$student->id}/guardians", [
            'guardian_user_id' => $parent->id, 'relationship' => 'parent', 'is_primary' => true,
        ])->assertCreated()->assertJsonPath('data.is_primary', true);
        $this->postJson("{$base}/students/{$student->id}/guardians", [
            'guardian_user_id' => $otherParent->id, 'relationship' => 'guardian', 'is_primary' => true,
        ])->assertCreated();
        $this->assertDatabaseHas('student_guardians', ['student_id' => $student->id, 'guardian_user_id' => $parent->id, 'is_primary' => false]);
        $this->assertDatabaseHas('student_guardians', ['student_id' => $student->id, 'guardian_user_id' => $otherParent->id, 'is_primary' => true]);
        $this->getJson("{$base}/students/{$student->id}/guardians")->assertOk()->assertJsonCount(2, 'data');
        $this->deleteJson("{$base}/students/{$student->id}/guardians/{$otherParent->id}")->assertOk();
        $this->assertDatabaseMissing('student_guardians', ['student_id' => $student->id, 'guardian_user_id' => $otherParent->id]);
    }

    public function test_students_cannot_manage_guardian_links_and_other_tenant_parents_cannot_be_linked(): void
    {
        [$admin, $parent, , $student, $organization, $foreignParent] = $this->workspace();
        Sanctum::actingAs($admin);
        $base = "/api/v1/organizations/{$organization->id}";
        $this->postJson("{$base}/students/{$student->id}/guardians", ['guardian_user_id' => $foreignParent->id])->assertUnprocessable();
        $this->assertDatabaseCount('student_guardians', 0);

        Sanctum::actingAs($student->user);
        $this->getJson("{$base}/parent-accounts")->assertForbidden();
        $this->postJson("{$base}/students/{$student->id}/guardians", ['guardian_user_id' => $parent->id])->assertForbidden();
    }

    private function workspace(): array
    {
        $organization = Organization::query()->create([
            'name' => 'Northstar Academy', 'slug' => 'northstar-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
        $admin = User::factory()->create();
        $parent = User::factory()->create(['email' => 'parent@example.test']);
        $otherParent = User::factory()->create(['email' => 'parent2@example.test']);
        $studentUser = User::factory()->create(['email' => 'student@example.test']);
        foreach ([[$admin, OrganizationRole::Admin], [$parent, OrganizationRole::Parent], [$otherParent, OrganizationRole::Parent], [$studentUser, OrganizationRole::Student]] as [$user, $role]) {
            OrganizationMembership::query()->create([
                'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role,
                'status' => MembershipStatus::Active, 'accepted_at' => now(),
            ]);
        }
        $student = Student::query()->create([
            'organization_id' => $organization->id, 'user_id' => $studentUser->id,
            'name' => 'Learner One', 'admission_number' => 'S-100', 'status' => 'active',
        ]);
        $foreignParent = User::factory()->create(['email' => 'foreign-parent@example.test']);
        $foreignOrganization = Organization::query()->create([
            'name' => 'Westfield School', 'slug' => 'westfield-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $foreignOrganization->id, 'user_id' => $foreignParent->id,
            'role' => OrganizationRole::Parent, 'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);

        return [$admin, $parent, $otherParent, $student, $organization, $foreignParent];
    }
}
