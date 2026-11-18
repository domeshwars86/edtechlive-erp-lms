<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\FeePayment;
use App\Models\FeeRecord;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_sees_tenant_scoped_ledger_totals_and_overdue_balances(): void
    {
        $organization = $this->organization('Northstar Academy');
        $admin = User::factory()->create();
        $this->membership($organization, $admin, OrganizationRole::Admin);
        $student = Student::query()->create([
            'organization_id' => $organization->id, 'name' => 'Learner', 'admission_number' => 'S-1', 'status' => 'active',
        ]);
        $partial = FeeRecord::query()->create([
            'organization_id' => $organization->id, 'student_id' => $student->id,
            'title' => 'Past due', 'amount' => '100.00', 'due_on' => now($organization->timezone)->subDay()->toDateString(),
            'status' => 'partial', 'created_by' => $admin->id,
        ]);
        $paid = FeeRecord::query()->create([
            'organization_id' => $organization->id, 'student_id' => $student->id,
            'title' => 'Paid fee', 'amount' => '50.00', 'status' => 'paid', 'created_by' => $admin->id,
        ]);
        FeeRecord::query()->create([
            'organization_id' => $organization->id, 'student_id' => $student->id,
            'title' => 'Upcoming fee', 'amount' => '25.00', 'due_on' => now($organization->timezone)->addDay()->toDateString(),
            'status' => 'unpaid', 'created_by' => $admin->id,
        ]);
        foreach ([[$partial, '30.00'], [$paid, '50.00']] as [$record, $amount]) {
            FeePayment::query()->create([
                'organization_id' => $organization->id, 'fee_record_id' => $record->id,
                'amount' => $amount, 'method' => 'cash', 'received_by' => $admin->id, 'received_at' => now(),
            ]);
        }

        $foreignOrganization = $this->organization('Westfield School');
        $foreignAdmin = User::factory()->create();
        $this->membership($foreignOrganization, $foreignAdmin, OrganizationRole::Admin);
        $foreignStudent = Student::query()->create([
            'organization_id' => $foreignOrganization->id, 'name' => 'Other', 'admission_number' => 'F-1', 'status' => 'active',
        ]);
        FeeRecord::query()->create([
            'organization_id' => $foreignOrganization->id, 'student_id' => $foreignStudent->id,
            'title' => 'Foreign fee', 'amount' => '999.00', 'status' => 'unpaid', 'created_by' => $foreignAdmin->id,
        ]);

        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/organizations/{$organization->id}/reports/finance")->assertOk()
            ->assertJsonPath('data.currency', 'INR')
            ->assertJsonPath('data.summary.fee_records', 3)
            ->assertJsonPath('data.summary.unpaid_records', 1)
            ->assertJsonPath('data.summary.partial_records', 1)
            ->assertJsonPath('data.summary.paid_records', 1)
            ->assertJsonPath('data.summary.issued_amount', '175.00')
            ->assertJsonPath('data.summary.received_amount', '80.00')
            ->assertJsonPath('data.summary.outstanding_amount', '95.00')
            ->assertJsonPath('data.summary.overdue_records', 1)
            ->assertJsonPath('data.summary.overdue_balance', '70.00');
    }

    public function test_teacher_cannot_read_organization_wide_financial_totals(): void
    {
        $organization = $this->organization('Northstar Academy');
        $teacher = User::factory()->create();
        $this->membership($organization, $teacher, OrganizationRole::Teacher);
        Sanctum::actingAs($teacher);

        $this->getJson("/api/v1/organizations/{$organization->id}/reports/finance")->assertForbidden();
    }

    private function membership(Organization $organization, User $user, OrganizationRole $role): void
    {
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role,
            'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
    }

    private function organization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name, 'slug' => str()->slug($name).'-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
    }
}
