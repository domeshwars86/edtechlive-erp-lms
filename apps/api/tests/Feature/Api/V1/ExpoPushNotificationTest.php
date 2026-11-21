<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\SendExpoPush;
use App\Models\ExpoPushDevice;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use App\Services\ExpoPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpoPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_account_can_register_and_remove_only_its_encrypted_push_device(): void
    {
        config(['services.expo_push.enabled' => true, 'services.expo_push.project_id' => 'project-test']);
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user);
        $pushToken = 'ExponentPushToken[device-token-123]';

        $this->postJson('/api/v1/me/push-devices', ['token' => $pushToken, 'platform' => 'android'])
            ->assertCreated()->assertJsonPath('data.registered', true);
        $device = ExpoPushDevice::query()->sole();
        $this->assertSame($pushToken, $device->push_token);
        $this->assertNotSame($pushToken, $device->getRawOriginal('push_token'));
        $this->assertSame(hash('sha256', $pushToken), $device->token_hash);

        Sanctum::actingAs($other);
        $this->postJson('/api/v1/me/push-devices', ['token' => $pushToken, 'platform' => 'ios'])->assertConflict();
        $this->deleteJson('/api/v1/me/push-devices', ['token' => $pushToken])->assertOk()->assertJsonPath('data.removed', false);
        $this->assertDatabaseHas('expo_push_devices', ['id' => $device->id, 'user_id' => $user->id]);

        Sanctum::actingAs($user);
        $this->deleteJson('/api/v1/me/push-devices', ['token' => $pushToken])->assertOk()->assertJsonPath('data.removed', true);
        $this->assertDatabaseMissing('expo_push_devices', ['id' => $device->id]);
    }

    public function test_push_registration_is_disabled_without_a_configured_expo_project(): void
    {
        config(['services.expo_push.enabled' => true, 'services.expo_push.project_id' => null]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/me/push-devices', ['token' => 'ExponentPushToken[device-token-123]', 'platform' => 'ios'])
            ->assertServiceUnavailable();
    }

    public function test_queued_push_is_skipped_if_the_account_is_unverified_or_suspended(): void
    {
        config(['services.expo_push.enabled' => true, 'services.expo_push.project_id' => 'project-test']);
        Http::fake();
        $users = [
            User::factory()->unverified()->create(),
            User::factory()->create(['platform_status' => 'suspended']),
        ];
        foreach ($users as $index => $user) {
            $token = "ExponentPushToken[device-token-{$index}]";
            $device = ExpoPushDevice::query()->create([
                'user_id' => $user->id, 'push_token' => $token,
                'token_hash' => hash('sha256', $token), 'platform' => 'ios',
            ]);
            (new SendExpoPush($device->id, ['organization_id' => 14]))->handle(app(ExpoPushService::class));
        }

        Http::assertNothingSent();
    }

    public function test_workspace_notification_queues_private_push_payload_and_prunes_unregistered_devices(): void
    {
        config(['services.expo_push.enabled' => true, 'services.expo_push.project_id' => 'project-test']);
        Queue::fake();
        $user = User::factory()->create();
        $device = ExpoPushDevice::query()->create([
            'user_id' => $user->id, 'push_token' => 'ExponentPushToken[device-token-456]',
            'token_hash' => hash('sha256', 'ExponentPushToken[device-token-456]'), 'platform' => 'ios',
        ]);

        Notification::send($user, new WorkspaceNotification([
            'organization_id' => 14, 'kind' => 'meeting_scheduled', 'title' => 'Private algebra meeting',
            'body' => 'Specific private meeting details', 'resource_type' => 'meeting', 'resource_id' => 52,
        ]));

        Queue::assertPushed(SendExpoPush::class, fn (SendExpoPush $job): bool => $job->deviceId === $device->id
            && $job->data === ['organization_id' => 14, 'kind' => 'meeting_scheduled', 'resource_type' => 'meeting', 'resource_id' => 52]);

        Http::fake(['https://exp.host/--/api/v2/push/send' => Http::response([
            'data' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
        ])]);
        app(SendExpoPush::class, ['deviceId' => $device->id, 'data' => ['organization_id' => 14]])
            ->handle(app(ExpoPushService::class));
        Http::assertSent(fn ($request): bool => $request['to'] === 'ExponentPushToken[device-token-456]'
            && $request['title'] === 'New edTechLive update'
            && $request['body'] === 'Sign in to view your latest update.'
            && ! str_contains(json_encode($request->data()), 'Private algebra meeting'));
        $this->assertSame('error', Http::recorded()->first()[1]->json('data.status'));
        $this->assertSame('DeviceNotRegistered', Http::recorded()->first()[1]->json('data.details.error'));
        $this->assertDatabaseMissing('expo_push_devices', ['id' => $device->id]);
    }
}
