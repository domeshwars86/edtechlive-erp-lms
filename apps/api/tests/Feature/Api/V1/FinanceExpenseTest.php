<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\FinanceExpense;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceExpenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_record_and_list_tenant_scoped_expenses(): void
    {
        $organization = $this->organization('Westfield Academy');
        $admin = User::factory()->create();
        $this->membership($organization, $admin, OrganizationRole::Admin);
        $foreignOrganization = $this->organization('Other Academy');
        $foreignAdmin = User::factory()->create();
        FinanceExpense::query()->create([
            'organization_id' => $foreignOrganization->id, 'recorded_by' => $foreignAdmin->id,
            'title' => 'Foreign expense', 'category' => 'other', 'amount' => '999.00',
            'incurred_on' => CarbonImmutable::now('Asia/Kolkata')->toDateString(), 'payment_method' => 'cash',
        ]);
        Sanctum::actingAs($admin);
        $base = "/api/v1/organizations/{$organization->id}/expenses";
        $this->postJson($base, [
            'title' => 'Library books', 'category' => 'supplies', 'amount' => '1250.50',
            'incurred_on' => CarbonImmutable::now('Asia/Kolkata')->toDateString(), 'payment_method' => 'bank_transfer',
            'reference' => 'INV-42', 'notes' => 'Science reference books',
        ])->assertCreated()->assertJsonPath('data.title', 'Library books')->assertJsonPath('data.amount', '1250.50');
        $this->postJson($base, [
            'title' => 'Future payment', 'category' => 'utilities', 'amount' => '100',
            'incurred_on' => CarbonImmutable::now('Asia/Kolkata')->addDay()->toDateString(), 'payment_method' => 'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors('incurred_on');
        $this->getJson($base.'?per_page=100')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'INV-42')->assertJsonMissing(['Foreign expense', '999.00']);
    }

    public function test_expense_management_is_denied_to_teachers_and_parents(): void
    {
        $organization = $this->organization('Westfield Academy');
        foreach ([OrganizationRole::Teacher, OrganizationRole::Parent] as $role) {
            $user = User::factory()->create();
            $this->membership($organization, $user, $role);
            Sanctum::actingAs($user);
            $this->getJson("/api/v1/organizations/{$organization->id}/expenses")->assertForbidden();
            $this->postJson("/api/v1/organizations/{$organization->id}/expenses", [])->assertForbidden();
        }
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
