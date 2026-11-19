<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\FeeRecord;
use App\Models\FinanceExpense;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_import_a_balanced_statement_and_match_receipts_and_expenses_once(): void
    {
        [$admin, $organization, $student] = $this->workspace();
        Sanctum::actingAs($admin);
        $today = CarbonImmutable::now('Asia/Kolkata')->toDateString();
        $fee = FeeRecord::query()->create([
            'organization_id' => $organization->id, 'student_id' => $student->id,
            'title' => 'Term fee', 'amount' => '250.00', 'status' => 'unpaid', 'created_by' => $admin->id,
        ]);
        $base = "/api/v1/organizations/{$organization->id}";
        $paymentId = $this->postJson("{$base}/fees/{$fee->id}/payments", ['amount' => '250.00', 'method' => 'bank_transfer'], ['Idempotency-Key' => 'bank-match-receipt-test-0001'])
            ->assertOk()->json('data.payments.0.id');
        $expense = FinanceExpense::query()->create([
            'organization_id' => $organization->id, 'recorded_by' => $admin->id,
            'title' => 'Books', 'category' => 'supplies', 'amount' => '50.00', 'incurred_on' => $today, 'payment_method' => 'bank_transfer',
        ]);
        $statementInput = [
            'account_label' => 'Main operating account', 'period_from' => $today, 'period_to' => $today,
            'opening_balance' => '1000.00', 'closing_balance' => '1200.00',
            'lines' => [
                ['transacted_on' => $today, 'description' => 'Tuition receipt', 'direction' => 'credit', 'amount' => '250.00', 'reference' => 'BANK-1'],
                ['transacted_on' => $today, 'description' => 'Book shop', 'direction' => 'debit', 'amount' => '50.00'],
            ],
        ];
        $statementId = $this->postJson("{$base}/bank-statements", $statementInput)->assertCreated()->json('data.id');
        $this->postJson("{$base}/bank-statements", $statementInput)->assertConflict();

        $lines = $this->getJson("{$base}/bank-statements")->assertOk()->assertJsonPath('data.0.matched_count', 0)
            ->assertJsonCount(2, 'data.0.lines')->json('data.0.lines');
        $this->getJson("{$base}/bank-statement-lines/{$lines[0]['id']}/candidates")->assertOk()
            ->assertJsonPath('data.0.type', 'fee_payment')->assertJsonPath('data.0.id', $paymentId);
        $this->postJson("{$base}/bank-statement-lines/{$lines[0]['id']}/match", ['ledger_type' => 'fee_payment', 'ledger_id' => $paymentId])
            ->assertOk()->assertJsonPath('data.status', 'matched')->assertJsonPath('data.match.type', 'fee_payment');
        $this->getJson("{$base}/bank-statement-lines/{$lines[0]['id']}/candidates")->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("{$base}/bank-statement-lines/{$lines[1]['id']}/match", ['ledger_type' => 'expense', 'ledger_id' => $expense->id])
            ->assertOk()->assertJsonPath('data.status', 'matched')->assertJsonPath('data.match.type', 'expense');
        $this->postJson("{$base}/bank-statement-lines/{$lines[0]['id']}/match", ['ledger_type' => 'fee_payment', 'ledger_id' => $paymentId])->assertConflict();
        $this->deleteJson("{$base}/bank-statement-lines/{$lines[0]['id']}/match")->assertOk()->assertJsonPath('data.status', 'unmatched');
        $this->assertDatabaseHas('bank_statements', ['id' => $statementId, 'line_count' => 2]);
    }

    public function test_import_rejects_unbalanced_statement_without_partial_records(): void
    {
        [$admin, $organization] = $this->workspace();
        Sanctum::actingAs($admin);
        $today = CarbonImmutable::now('Asia/Kolkata')->toDateString();
        $this->postJson("/api/v1/organizations/{$organization->id}/bank-statements", [
            'account_label' => 'Main account', 'period_from' => $today, 'period_to' => $today,
            'opening_balance' => '10.00', 'closing_balance' => '99.00',
            'lines' => [['transacted_on' => $today, 'description' => 'Receipt', 'direction' => 'credit', 'amount' => '20.00']],
        ])->assertUnprocessable()->assertJsonValidationErrors('closing_balance');
        $this->assertDatabaseCount('bank_statements', 0);
        $this->assertDatabaseCount('bank_statement_lines', 0);
    }

    public function test_statement_and_ledger_matching_is_tenant_scoped_and_amount_exact(): void
    {
        [$admin, $organization, $student] = $this->workspace();
        Sanctum::actingAs($admin);
        $today = CarbonImmutable::now('Asia/Kolkata')->toDateString();
        $fee = FeeRecord::query()->create([
            'organization_id' => $organization->id, 'student_id' => $student->id,
            'title' => 'Term fee', 'amount' => '250.00', 'status' => 'unpaid', 'created_by' => $admin->id,
        ]);
        $base = "/api/v1/organizations/{$organization->id}";
        $paymentId = $this->postJson("{$base}/fees/{$fee->id}/payments", ['amount' => '250.00', 'method' => 'cash'], ['Idempotency-Key' => 'tenant-match-receipt-test-01'])->assertOk()->json('data.payments.0.id');
        $lineId = $this->postJson("{$base}/bank-statements", [
            'account_label' => 'Main account', 'period_from' => $today, 'period_to' => $today,
            'opening_balance' => '0.00', 'closing_balance' => '251.00',
            'lines' => [['transacted_on' => $today, 'description' => 'Receipt', 'direction' => 'credit', 'amount' => '251.00']],
        ])->assertCreated()->json('data.id');
        $statementLineId = $this->getJson("{$base}/bank-statements")->json('data.0.lines.0.id');
        $this->postJson("{$base}/bank-statement-lines/{$statementLineId}/match", ['ledger_type' => 'fee_payment', 'ledger_id' => $paymentId])->assertUnprocessable();

        $otherOrg = $this->organization('Other Academy');
        $otherAdmin = User::factory()->create();
        $this->membership($otherOrg, $otherAdmin, OrganizationRole::Admin);
        Sanctum::actingAs($otherAdmin);
        $this->getJson("/api/v1/organizations/{$otherOrg->id}/bank-statements")->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/organizations/{$otherOrg->id}/bank-statement-lines/{$statementLineId}/match", ['ledger_type' => 'fee_payment', 'ledger_id' => $paymentId])->assertNotFound();
    }

    public function test_statement_management_is_denied_to_non_finance_roles(): void
    {
        $organization = $this->organization('Northstar Academy');
        foreach ([OrganizationRole::Teacher, OrganizationRole::Student, OrganizationRole::Parent] as $role) {
            $user = User::factory()->create();
            $this->membership($organization, $user, $role);
            Sanctum::actingAs($user);
            $base = "/api/v1/organizations/{$organization->id}/bank-statements";
            $this->getJson($base)->assertForbidden();
            $this->postJson($base, [])->assertForbidden();
        }
    }

    private function workspace(): array
    {
        $admin = User::factory()->create();
        $organization = $this->organization('Northstar Academy');
        $this->membership($organization, $admin, OrganizationRole::Admin);
        $student = Student::query()->create([
            'organization_id' => $organization->id, 'name' => 'Learner', 'admission_number' => 'S-1', 'status' => 'active',
        ]);

        return [$admin, $organization, $student];
    }

    private function organization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name, 'slug' => str()->slug($name).'-'.str()->random(5),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
    }

    private function membership(Organization $organization, User $user, OrganizationRole $role): void
    {
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role,
            'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
    }
}
