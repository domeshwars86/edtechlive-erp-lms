<?php

namespace Tests\Feature\Api\V1;

use App\Models\ContactEnquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ContactEnquiryRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfigured_retention_never_deletes_contact_enquiries(): void
    {
        Config::set('privacy.contact_enquiry_retention_days', null);
        Config::set('privacy.contact_enquiry_retention_enabled', true);
        $enquiry = $this->createEnquiry(400);

        $this->artisan('contact-enquiries:purge')
            ->expectsOutput('Contact enquiry retention is not configured; no records were removed.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('contact_enquiries', ['id' => $enquiry->id]);
    }

    public function test_default_command_is_a_dry_run_and_preserves_all_records(): void
    {
        Config::set('privacy.contact_enquiry_retention_days', 30);
        Config::set('privacy.contact_enquiry_retention_enabled', false);
        $old = $this->createEnquiry(31);
        $recent = $this->createEnquiry(29);

        $this->artisan('contact-enquiries:purge')
            ->expectsOutputToContain('Found 1 contact enquiries')
            ->expectsOutput('Dry run only; rerun with --force to remove eligible records.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('contact_enquiries', ['id' => $old->id]);
        $this->assertDatabaseHas('contact_enquiries', ['id' => $recent->id]);
    }

    public function test_force_requires_explicit_retention_enablement(): void
    {
        Config::set('privacy.contact_enquiry_retention_days', 30);
        Config::set('privacy.contact_enquiry_retention_enabled', false);
        $enquiry = $this->createEnquiry(31);

        $this->artisan('contact-enquiries:purge --force')
            ->expectsOutput('Set CONTACT_ENQUIRY_RETENTION_ENABLED=true after approving the retention policy before deleting records.')
            ->assertExitCode(1);

        $this->assertDatabaseHas('contact_enquiries', ['id' => $enquiry->id]);
    }

    public function test_enabled_purge_removes_only_records_past_the_configured_period(): void
    {
        Config::set('privacy.contact_enquiry_retention_days', 30);
        Config::set('privacy.contact_enquiry_retention_enabled', true);
        $old = $this->createEnquiry(31);
        $recent = $this->createEnquiry(29);

        $this->artisan('contact-enquiries:purge --force')
            ->expectsOutputToContain('Found 1 contact enquiries')
            ->expectsOutput('Purged 1 contact enquiries.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('contact_enquiries', ['id' => $old->id]);
        $this->assertDatabaseHas('contact_enquiries', ['id' => $recent->id]);
    }

    public function test_invalid_retention_value_fails_without_deleting_data(): void
    {
        Config::set('privacy.contact_enquiry_retention_days', '0');
        Config::set('privacy.contact_enquiry_retention_enabled', true);
        $enquiry = $this->createEnquiry(400);

        $this->artisan('contact-enquiries:purge --force')
            ->expectsOutput('CONTACT_ENQUIRY_RETENTION_DAYS must be a whole number between 1 and 36500.')
            ->assertExitCode(1);

        $this->assertDatabaseHas('contact_enquiries', ['id' => $enquiry->id]);
    }

    private function createEnquiry(int $daysAgo): ContactEnquiry
    {
        $createdAt = now()->subDays($daysAgo);

        $enquiry = ContactEnquiry::query()->create([
            'name' => 'Retention Test Contact',
            'email' => "retention-{$daysAgo}-".uniqid().'@example.test',
            'role' => 'School or institution',
            'category' => 'Platform overview',
            'message' => 'Retention command test payload.',
            'consented_at' => $createdAt,
        ]);

        $enquiry->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $enquiry;
    }
}
