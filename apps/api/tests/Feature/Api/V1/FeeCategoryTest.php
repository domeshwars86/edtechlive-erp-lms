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

class FeeCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_managers_can_manage_tenant_fee_categories_and_assign_them_to_records(): void
    {
        [$manager, $organization, $student] = $this->workspace(OrganizationRole::Management);
        Sanctum::actingAs($manager);
        $base = "/api/v1/organizations/{$organization->id}";
        $categoryId = $this->postJson("{$base}/fee-categories", [
            'name' => 'Tuition', 'code' => 'TUITION', 'description' => 'Term tuition fees',
        ])->assertCreated()->assertJsonPath('data.is_active', true)->json('data.id');
        $this->postJson("{$base}/fee-categories", ['name' => 'Transport', 'code' => 'BUS'])->assertCreated();

        $this->getJson("{$base}/fee-categories?search=tuit&is_active=1")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $categoryId);
        $this->patchJson("{$base}/fee-categories/{$categoryId}", ['name' => 'Annual tuition', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.name', 'Annual tuition')->assertJsonPath('data.is_active', false);
        $this->getJson("{$base}/fee-categories?is_active=0")->assertOk()->assertJsonPath('meta.total', 1);
        $this->patchJson("{$base}/fee-categories/{$categoryId}", ['is_active' => true])->assertOk();

        $feeId = $this->postJson("{$base}/fees", [
            'student_id' => $student->id, 'fee_category_id' => $categoryId,
            'title' => 'Quarter one', 'amount' => '2500.00',
        ])->assertCreated()->assertJsonPath('data.category.id', $categoryId)
            ->assertJsonPath('data.category.name', 'Annual tuition')->json('data.id');
        $this->assertDatabaseHas('fee_records', ['id' => $feeId, 'fee_category_id' => $categoryId]);

        $otherOrganization = $this->organization('Other fee tenant');
        $this->getJson("/api/v1/organizations/{$otherOrganization->id}/fee-categories")->assertNotFound();
        $this->postJson("{$base}/fees", [
            'student_id' => $student->id, 'fee_category_id' => 999999,
            'title' => 'Invalid category', 'amount' => '100.00',
        ])->assertUnprocessable()->assertJsonValidationErrors('fee_category_id');
    }

    public function test_student_can_read_categories_but_cannot_manage_them(): void
    {
        [$studentUser, $organization] = $this->workspace(OrganizationRole::Student);
        Sanctum::actingAs($studentUser);
        $base = "/api/v1/organizations/{$organization->id}/fee-categories";

        $this->getJson($base)->assertOk();
        $this->postJson($base, ['name' => 'Books'])->assertForbidden();
    }

    private function workspace(OrganizationRole $role): array
    {
        $user = User::factory()->create();
        $organization = $this->organization('Northstar Academy');
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id,
            'role' => $role, 'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
        $student = Student::query()->create([
            'organization_id' => $organization->id, 'name' => 'Learner One',
            'admission_number' => 'S-100', 'status' => 'active',
        ]);

        return [$user, $organization, $student];
    }

    private function organization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name, 'slug' => str()->slug($name).'-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
    }
}
