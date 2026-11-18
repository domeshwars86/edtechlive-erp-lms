<?php

namespace Tests\Feature\Api\V1;

use App\Models\ContactEnquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactEnquiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_consenting_visitor_can_save_an_enquiry_without_mail_configuration(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/enquiries', $this->payload())
            ->assertAccepted()
            ->assertJsonPath('data.notification_sent', false)
            ->assertJsonPath('message', 'Your enquiry has been saved. Email follow-up is not configured yet.');

        $this->assertDatabaseHas('contact_enquiries', [
            'name' => 'Asha Rao',
            'email' => 'asha@example.test',
            'organization_name' => 'Northstar Academy',
            'status' => 'new',
        ]);
        $this->assertNotNull(ContactEnquiry::query()->firstOrFail()->consented_at);
        Mail::assertNothingOutgoing();
    }

    public function test_enquiry_requires_valid_contact_details_and_explicit_consent(): void
    {
        $this->postJson('/api/v1/enquiries', array_merge($this->payload(), [
            'email' => 'not-an-email',
            'consent' => false,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'consent']);

        $this->assertDatabaseCount('contact_enquiries', 0);
    }

    public function test_honeypot_submission_is_accepted_without_persisting_personal_data(): void
    {
        $this->postJson('/api/v1/enquiries', array_merge($this->payload(), ['website' => 'spam.example']))
            ->assertAccepted()
            ->assertJsonPath('data.notification_sent', false);

        $this->assertDatabaseCount('contact_enquiries', 0);
    }

    private function payload(): array
    {
        return [
            'name' => 'Asha Rao',
            'email' => 'asha@example.test',
            'phone' => '+91 98765 43210',
            'institution' => 'Northstar Academy',
            'role' => 'School or institution',
            'category' => 'Platform overview',
            'message' => 'Please share details about the platform.',
            'consent' => true,
        ];
    }
}
