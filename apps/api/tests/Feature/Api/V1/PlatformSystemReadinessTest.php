<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformSystemReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowlisted_operator_can_review_provider_readiness_without_credentials(): void
    {
        Config::set('services.platform.admin_emails', ['operator@example.com']);
        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', 'smtp.internal.example');
        Config::set('mail.mailers.smtp.username', 'mailer-user');
        Config::set('mail.mailers.smtp.password', 'mail-secret-value');
        Config::set('services.google_meet.client_id', 'google-client-id');
        Config::set('services.google_meet.client_secret', 'google-secret-value');
        Config::set('services.google_meet.redirect_uri', 'https://example.test/google/callback');
        Config::set('filesystems.disks.private.driver', 's3');
        Config::set('filesystems.disks.private.bucket', 'private-bucket');
        Config::set('filesystems.disks.private.key', 'storage-key');
        Config::set('filesystems.disks.private.secret', 'storage-secret-value');
        Config::set('services.razorpay.enabled', true);
        Config::set('services.expo_push.enabled', true);
        Config::set('services.expo_push.project_id', 'eas-project');
        Config::set('services.ai.driver', 'ollama');
        Config::set('services.ai.model', 'local-model');
        Sanctum::actingAs(User::factory()->create(['email' => 'operator@example.com']));

        $response = $this->getJson('/api/v1/platform/system-readiness')
            ->assertOk()
            ->assertJsonPath('data.security_note', 'This view reports configuration signals only. It never returns credentials and does not change provider settings.')
            ->assertJsonPath('data.services.0.key', 'email')
            ->assertJsonPath('data.services.0.status', 'configured')
            ->assertJsonPath('data.services.1.key', 'google_meet')
            ->assertJsonPath('data.services.1.status', 'configured')
            ->assertJsonPath('data.services.2.key', 'private_storage')
            ->assertJsonPath('data.services.2.status', 'configured')
            ->assertJsonPath('data.services.3.key', 'razorpay_test')
            ->assertJsonPath('data.services.3.status', 'configured')
            ->assertJsonPath('data.services.4.key', 'expo_push')
            ->assertJsonPath('data.services.4.status', 'configured')
            ->assertJsonPath('data.services.5.key', 'ai')
            ->assertJsonPath('data.services.5.status', 'configured')
            ->assertJsonMissing([
                'mail-secret-value', 'google-secret-value', 'storage-secret-value',
            ]);

        $this->assertStringNotContainsString('smtp.internal.example', $response->getContent());
        $this->assertStringNotContainsString('private-bucket', $response->getContent());
    }

    public function test_system_readiness_is_denied_to_non_allowlisted_users(): void
    {
        Config::set('services.platform.admin_emails', ['operator@example.com']);
        Sanctum::actingAs(User::factory()->create(['email' => 'school-admin@example.com']));

        $this->getJson('/api/v1/platform/system-readiness')->assertForbidden();
    }

    public function test_local_defaults_are_reported_as_local_or_optional(): void
    {
        Config::set('services.platform.admin_emails', ['operator@example.com']);
        Config::set('mail.default', 'log');
        Config::set('filesystems.disks.private.driver', 'local');
        Config::set('services.razorpay.enabled', false);
        Config::set('services.expo_push.enabled', false);
        Config::set('services.ai.driver', 'disabled');
        Sanctum::actingAs(User::factory()->create(['email' => 'operator@example.com']));

        $this->getJson('/api/v1/platform/system-readiness')
            ->assertOk()
            ->assertJsonPath('data.services.0.status', 'local_only')
            ->assertJsonPath('data.services.2.status', 'local_only')
            ->assertJsonPath('data.services.3.status', 'optional')
            ->assertJsonPath('data.services.4.status', 'optional')
            ->assertJsonPath('data.services.5.status', 'optional');
    }
}
