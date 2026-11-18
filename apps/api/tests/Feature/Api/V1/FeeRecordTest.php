<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\FeeRecord;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeeRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_create_a_fee_and_record_partial_and_final_payments_without_overpayment(): void
    {
        [$admin, $organization, , $student] = $this->workspace();
        Sanctum::actingAs($admin);
        $base = "/api/v1/organizations/{$organization->id}";
        $feeId = $this->postJson("{$base}/fees", [
            'student_id' => $student->id,
            'title' => 'Term 1 tuition',
            'amount' => '10000.00',
            'due_on' => now()->addWeek()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.status', 'unpaid')->json('data.id');

        $this->postJson("{$base}/fees/{$feeId}/payments", ['amount' => '4000.00', 'method' => 'upi', 'reference' => 'UPI-1'])
            ->assertOk()->assertJsonPath('data.status', 'partial')->assertJsonPath('data.balance', '6000.00');
        $this->postJson("{$base}/fees/{$feeId}/payments", ['amount' => '6000.01', 'method' => 'cash'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('fee_payments', 1);

        $this->postJson("{$base}/fees/{$feeId}/payments", ['amount' => '6000.00', 'method' => 'bank_transfer'])
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.balance', '0.00');
        $this->assertDatabaseHas('fee_records', ['id' => $feeId, 'status' => 'paid']);
    }

    public function test_student_and_parent_only_see_their_authorized_fee_records(): void
    {
        [$admin, $organization, $studentUser, $student, $parentUser, $otherStudent] = $this->workspace(withOtherStudent: true);
        Sanctum::actingAs($admin);
        $base = "/api/v1/organizations/{$organization->id}";
        $mine = FeeRecord::query()->create([
            'organization_id' => $organization->id, 'student_id' => $student->id,
            'title' => 'Books', 'amount' => '2500.00', 'status' => 'unpaid', 'created_by' => $admin->id,
        ]);
        FeeRecord::query()->create([
            'organization_id' => $organization->id, 'student_id' => $otherStudent->id,
            'title' => 'Other student fee', 'amount' => '900.00', 'status' => 'unpaid', 'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($studentUser);
        $this->getJson("{$base}/fees")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine->id);
        $this->postJson("{$base}/fees", ['student_id' => $student->id, 'title' => 'No access', 'amount' => '10.00'])
            ->assertForbidden();

        Sanctum::actingAs($parentUser);
        $this->getJson("{$base}/fees")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine->id);
    }

    private function workspace(bool $withOtherStudent = false): array
    {
        $admin = User::factory()->create();
        $studentUser = User::factory()->create(['email' => 'learner@example.test']);
        $parentUser = User::factory()->create(['email' => 'parent@example.test']);
        $organization = Organization::query()->create([
            'name' => 'Northstar Academy', 'slug' => 'northstar-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
        foreach ([[$admin, OrganizationRole::Admin], [$studentUser, OrganizationRole::Student], [$parentUser, OrganizationRole::Parent]] as [$user, $role]) {
            OrganizationMembership::query()->create([
                'organization_id' => $organization->id, 'user_id' => $user->id,
                'role' => $role, 'status' => MembershipStatus::Active, 'accepted_at' => now(),
            ]);
        }
        $student = Student::query()->create([
            'organization_id' => $organization->id, 'user_id' => $studentUser->id,
            'name' => 'Learner One', 'admission_number' => 'S-100', 'status' => 'active',
        ]);
        StudentGuardian::query()->create([
            'organization_id' => $organization->id, 'student_id' => $student->id,
            'guardian_user_id' => $parentUser->id, 'linked_by' => $admin->id,
            'relationship' => 'parent', 'is_primary' => true, 'linked_at' => now(),
        ]);
        $otherStudent = null;
        if ($withOtherStudent) {
            $otherStudent = Student::query()->create([
                'organization_id' => $organization->id, 'name' => 'Other Learner',
                'admission_number' => 'S-200', 'status' => 'active',
            ]);
        }

        return [$admin, $organization, $studentUser, $student, $parentUser, $otherStudent];
    }
}
