<?php

namespace Tests\Feature\Api\V1;

use App\Models\ContactEnquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactEnquiryInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowlisted_platform_operator_can_filter_and_search_enquiries(): void
    {
        config(['services.enquiries.admin_emails' => ['ops@example.test']]);
        $operator = User::factory()->create(['email' => 'ops@example.test', 'email_verified_at' => now()]);
        $match = ContactEnquiry::query()->create($this->payload(['name' => 'Asha Rao', 'status' => 'new']));
        ContactEnquiry::query()->create($this->payload(['name' => 'Other Person', 'status' => 'resolved']));

        $this->actingAs($operator)->getJson('/api/v1/enquiries?status=new&q=asha')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $match->id)
            ->assertJsonPath('data.0.name', 'Asha Rao');
    }

    public function test_non_allowlisted_user_cannot_access_platform_enquiries(): void
    {
        config(['services.enquiries.admin_emails' => ['ops@example.test']]);
        $user = User::factory()->create(['email' => 'teacher@example.test', 'email_verified_at' => now()]);

        $this->actingAs($user)->getJson('/api/v1/enquiries')->assertForbidden();
    }

    public function test_allowlisted_operator_can_update_status_with_reviewer_audit_fields(): void
    {
        config(['services.enquiries.admin_emails' => ['ops@example.test']]);
        $operator = User::factory()->create(['email' => 'ops@example.test', 'email_verified_at' => now()]);
        $enquiry = ContactEnquiry::query()->create($this->payload());

        $this->actingAs($operator)->patchJson("/api/v1/enquiries/{$enquiry->id}", ['status' => 'in_progress'])
            ->assertOk()->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.reviewer.id', $operator->id);

        $this->assertDatabaseHas('contact_enquiries', [
            'id' => $enquiry->id,
            'status' => 'in_progress',
            'reviewed_by' => $operator->id,
        ]);
        $this->assertNotNull($enquiry->fresh()->reviewed_at);
    }

    public function test_inbox_rejects_invalid_filters_and_status_transitions(): void
    {
        config(['services.enquiries.admin_emails' => ['ops@example.test']]);
        $operator = User::factory()->create(['email' => 'ops@example.test', 'email_verified_at' => now()]);
        $enquiry = ContactEnquiry::query()->create($this->payload());

        $this->actingAs($operator)->getJson('/api/v1/enquiries?status=unknown')->assertUnprocessable();
        $this->actingAs($operator)->patchJson("/api/v1/enquiries/{$enquiry->id}", ['status' => 'deleted'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Asha Rao',
            'email' => 'asha@example.test',
            'phone' => null,
            'organization_name' => 'Northstar Academy',
            'role' => 'School or institution',
            'category' => 'Platform overview',
            'message' => 'Please share details about the platform.',
            'consented_at' => now(),
            'status' => 'new',
        ], $overrides);
    }
}
