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
use App\Services\OrganizationNotifier;
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

        $firstPayment = ['amount' => '4000.00', 'method' => 'upi', 'reference' => 'UPI-1'];
        $firstKey = 'fee-first-payment-test-0001';
        $firstResponse = $this->postJson("{$base}/fees/{$feeId}/payments", $firstPayment, ['Idempotency-Key' => $firstKey])
            ->assertOk()->assertJsonPath('data.status', 'partial')->assertJsonPath('data.balance', '6000.00');
        $firstPaymentId = $firstResponse->json('data.payments.0.id');
        $this->postJson("{$base}/fees/{$feeId}/payments", $firstPayment, ['Idempotency-Key' => $firstKey])
            ->assertOk()->assertJsonPath('data.payments.0.id', $firstPaymentId)->assertJsonPath('data.balance', '6000.00');
        $this->assertDatabaseCount('fee_payments', 1);

        $this->postJson("{$base}/fees/{$feeId}/payments", ['amount' => '6000.01', 'method' => 'cash'], ['Idempotency-Key' => 'fee-overpayment-test-0001'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('fee_payments', 1);

        $this->postJson("{$base}/fees/{$feeId}/payments", ['amount' => '6000.00', 'method' => 'bank_transfer'], ['Idempotency-Key' => 'fee-final-payment-test-0001'])
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.balance', '0.00');
        $this->assertDatabaseHas('fee_records', ['id' => $feeId, 'status' => 'paid']);
    }

    public function test_payment_retry_returns_the_original_receipt_without_duplicate_notification(): void
    {
        [$admin, $organization, , $student] = $this->workspace();
        $fee = FeeRecord::query()->create([
            'organization_id' => $organization->id, 'student_id' => $student->id,
            'title' => 'Term fee', 'amount' => '100.00', 'status' => 'unpaid', 'created_by' => $admin->id,
        ]);
        $notifier = \Mockery::mock(OrganizationNotifier::class);
        $notifier->shouldReceive('feePaymentRecorded')->once();
        $this->app->instance(OrganizationNotifier::class, $notifier);
        Sanctum::actingAs($admin);
        $base = "/api/v1/organizations/{$organization->id}/fees/{$fee->id}/payments";
        $key = 'fee-retry-test-key-000001';
        $payload = ['amount' => '25.00', 'method' => 'cash', 'reference' => 'RECEIPT-25'];

        $this->postJson($base, $payload)->assertUnprocessable()->assertJsonValidationErrors('Idempotency-Key');
        $created = $this->postJson($base, $payload, ['Idempotency-Key' => $key])
            ->assertOk()->assertJsonPath('data.paid_amount', '25.00');
        $paymentId = $created->json('data.payments.0.id');
        $this->postJson($base, $payload, ['Idempotency-Key' => $key])
            ->assertOk()->assertJsonPath('data.payments.0.id', $paymentId)->assertJsonPath('data.paid_amount', '25.00');
        $this->postJson($base, ['amount' => '26.00', 'method' => 'cash', 'reference' => 'RECEIPT-25'], ['Idempotency-Key' => $key])
            ->assertStatus(409)->assertJsonPath('message', 'This Idempotency-Key was already used with different payment details.');

        $otherFee = FeeRecord::query()->create([
            'organization_id' => $organization->id, 'student_id' => $student->id,
            'title' => 'Other fee', 'amount' => '100.00', 'status' => 'unpaid', 'created_by' => $admin->id,
        ]);
        $this->postJson("/api/v1/organizations/{$organization->id}/fees/{$otherFee->id}/payments", $payload, ['Idempotency-Key' => $key])
            ->assertStatus(409);
        $this->assertDatabaseCount('fee_payments', 1);
    }

    public function test_payment_idempotency_keys_are_scoped_to_the_organization(): void
    {
        [$admin, $organization, , $student] = $this->workspace();
        $otherOrganization = Organization::query()->create([
            'name' => 'Second Academy', 'slug' => 'second-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $otherOrganization->id, 'user_id' => $admin->id,
            'role' => OrganizationRole::Admin, 'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
        $otherStudent = Student::query()->create([
            'organization_id' => $otherOrganization->id, 'name' => 'Second Learner',
            'admission_number' => 'S-SECOND', 'status' => 'active',
        ]);
        $fee = FeeRecord::query()->create([
            'organization_id' => $organization->id, 'student_id' => $student->id,
            'title' => 'Term fee', 'amount' => '100.00', 'status' => 'unpaid', 'created_by' => $admin->id,
        ]);
        $otherFee = FeeRecord::query()->create([
            'organization_id' => $otherOrganization->id, 'student_id' => $otherStudent->id,
            'title' => 'Term fee', 'amount' => '100.00', 'status' => 'unpaid', 'created_by' => $admin->id,
        ]);
        Sanctum::actingAs($admin);
        $payload = ['amount' => '25.00', 'method' => 'cash'];
        $headers = ['Idempotency-Key' => 'fee-tenant-scope-test-0001'];

        $this->postJson("/api/v1/organizations/{$organization->id}/fees/{$fee->id}/payments", $payload, $headers)->assertOk();
        $this->postJson("/api/v1/organizations/{$otherOrganization->id}/fees/{$otherFee->id}/payments", $payload, $headers)->assertOk();
        $this->assertDatabaseCount('fee_payments', 2);
        $this->assertDatabaseHas('fee_payments', ['organization_id' => $organization->id, 'fee_record_id' => $fee->id]);
        $this->assertDatabaseHas('fee_payments', ['organization_id' => $otherOrganization->id, 'fee_record_id' => $otherFee->id]);
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
