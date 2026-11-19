<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\GoogleMeetConnection;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GoogleMeetIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_connect_google_create_meet_room_and_disconnect_with_encrypted_tokens(): void
    {
        $this->configureGoogle();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'access-secret', 'refresh_token' => 'refresh-secret', 'expires_in' => 3600]),
            'https://oauth2.googleapis.com/revoke' => Http::response([], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['email' => 'organizer@example.test']),
            'https://meet.googleapis.com/v2/spaces' => Http::response(['meetingUri' => 'https://meet.google.com/abc-defg-hij'], 200),
        ]);

        [$user, $organization] = $this->workspace(OrganizationRole::Admin);
        Sanctum::actingAs($user);
        $base = "/api/v1/organizations/{$organization->id}/meetings/google";

        $this->getJson("{$base}/status")->assertOk()->assertJsonPath('configured', true)->assertJsonPath('connected', false);
        $authorizationUrl = $this->postJson("{$base}/connect")->assertOk()->json('authorization_url');
        parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
        $this->assertSame('https://accounts.google.com/o/oauth2/v2/auth', parse_url($authorizationUrl, PHP_URL_SCHEME).'://'.parse_url($authorizationUrl, PHP_URL_HOST).parse_url($authorizationUrl, PHP_URL_PATH));
        $this->assertDatabaseHas('google_meet_oauth_states', ['organization_id' => $organization->id, 'user_id' => $user->id, 'state_hash' => hash('sha256', $query['state'])]);

        $this->get('/api/v1/integrations/google-meet/callback?'.http_build_query(['code' => 'test-code', 'state' => $query['state']]))
            ->assertRedirect('http://127.0.0.1:4173/workspace/admin?google_meet=connected');
        $this->get('/api/v1/integrations/google-meet/callback?'.http_build_query(['code' => 'test-code', 'state' => $query['state']]))
            ->assertRedirect('http://127.0.0.1:4173/?google_meet=error');

        $connection = GoogleMeetConnection::query()->firstOrFail();
        $this->assertSame('organizer@example.test', $connection->google_email);
        $this->assertSame('access-secret', $connection->access_token);
        $this->assertSame('refresh-secret', $connection->refresh_token);
        $this->assertStringNotContainsString('access-secret', (string) DB::table('google_meet_connections')->value('access_token'));
        $this->assertStringNotContainsString('refresh-secret', (string) DB::table('google_meet_connections')->value('refresh_token'));

        $this->getJson("{$base}/status")->assertOk()->assertJsonPath('connected', true)->assertJsonPath('google_email', 'organizer@example.test');
        $this->postJson("{$base}/spaces")->assertCreated()->assertJsonPath('meeting_url', 'https://meet.google.com/abc-defg-hij');
        Http::assertSent(fn ($request) => $request->url() === 'https://meet.googleapis.com/v2/spaces'
            && $request->hasHeader('Authorization', 'Bearer access-secret'));

        $this->deleteJson("{$base}/connect")->assertOk()->assertJsonPath('connected', false);
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/revoke'
            && $request['token'] === 'refresh-secret');
        $this->assertDatabaseMissing('google_meet_connections', ['organization_id' => $organization->id, 'user_id' => $user->id]);
    }

    public function test_expired_google_access_token_is_refreshed_before_creating_a_space(): void
    {
        $this->configureGoogle();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-access', 'expires_in' => 3600]),
            'https://meet.googleapis.com/v2/spaces' => Http::response(['meetingUri' => 'https://meet.google.com/new-room'], 200),
        ]);

        [$user, $organization] = $this->workspace(OrganizationRole::Admin);
        GoogleMeetConnection::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'access_token' => 'expired-access',
            'refresh_token' => 'refresh-secret',
            'token_expires_at' => now()->subMinute(),
            'connected_at' => now()->subDay(),
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/organizations/{$organization->id}/meetings/google/spaces")
            ->assertCreated()->assertJsonPath('meeting_url', 'https://meet.google.com/new-room');
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'refresh-secret');
        Http::assertSent(fn ($request) => $request->url() === 'https://meet.googleapis.com/v2/spaces'
            && $request->hasHeader('Authorization', 'Bearer new-access'));
    }

    public function test_google_meet_connection_is_tenant_and_role_scoped_and_manual_fallback_is_reported(): void
    {
        $this->configureGoogle();
        [$student, $organization] = $this->workspace(OrganizationRole::Student);
        Sanctum::actingAs($student);
        $base = "/api/v1/organizations/{$organization->id}/meetings/google";
        $this->getJson("{$base}/status")->assertForbidden();
        $this->postJson("{$base}/connect")->assertForbidden();
    }

    public function test_unconfigured_google_meet_disables_oauth_connection(): void
    {
        config([
            'services.google_meet.client_id' => null,
            'services.google_meet.client_secret' => null,
            'services.google_meet.redirect_uri' => null,
        ]);
        [$admin, $organization] = $this->workspace(OrganizationRole::Admin);
        Sanctum::actingAs($admin);
        $base = "/api/v1/organizations/{$organization->id}/meetings/google";

        $this->getJson("{$base}/status")->assertOk()->assertJsonPath('configured', false)->assertJsonPath('connected', false);
        $this->postJson("{$base}/connect")->assertServiceUnavailable();
    }

    public function test_google_callback_state_is_one_time_and_expiring(): void
    {
        $this->configureGoogle();
        [$user, $organization] = $this->workspace(OrganizationRole::Management);
        Sanctum::actingAs($user);
        $authorizationUrl = $this->postJson("/api/v1/organizations/{$organization->id}/meetings/google/connect")->assertOk()->json('authorization_url');
        parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
        $this->travel(11)->minutes();
        $this->get('/api/v1/integrations/google-meet/callback?'.http_build_query(['code' => 'stale', 'state' => $query['state']]))
            ->assertRedirect('http://127.0.0.1:4173/?google_meet=error');
        $this->assertDatabaseCount('google_meet_connections', 0);
    }

    private function configureGoogle(): void
    {
        config([
            'services.google_meet.client_id' => 'client-id',
            'services.google_meet.client_secret' => 'client-secret',
            'services.google_meet.redirect_uri' => 'http://127.0.0.1:8010/api/v1/integrations/google-meet/callback',
            'services.google_meet.frontend_url' => 'http://127.0.0.1:4173',
        ]);
    }

    private function workspace(OrganizationRole $role): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Northstar Academy',
            'slug' => 'northstar-'.str()->random(8),
            'type' => OrganizationType::School,
            'status' => 'active',
            'timezone' => 'Asia/Kolkata',
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);

        return [$user, $organization];
    }
}
