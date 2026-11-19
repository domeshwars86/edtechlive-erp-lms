<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Jobs\SendWorkspaceEmail;
use App\Mail\WorkspaceAlertMail;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\NotificationPreferences;
use App\Services\WorkspaceEmailDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkspaceEmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_is_disabled_by_default_and_requires_verified_recipient_opt_in(): void
    {
        $organization = $this->organization();
        $recipient = User::factory()->create();
        $unverified = User::factory()->unverified()->create();
        $optedOut = User::factory()->create();
        $optedOut->forceFill(['notification_preferences' => ['email' => ['meetings' => false]]])->save();
        $payload = ['organization_id' => $organization->id, 'kind' => 'meeting_scheduled', 'title' => 'Meeting', 'body' => 'A class is scheduled.'];

        Queue::fake();
        app(WorkspaceEmailDispatcher::class)->dispatch([$recipient], $payload);
        $this->assertDatabaseCount('notification_deliveries', 0);

        config()->set('workspace-notifications.email_enabled', true);
        $recipient->forceFill(['notification_preferences' => ['email' => ['meetings' => true]]])->save();
        app(WorkspaceEmailDispatcher::class)->dispatch([$recipient, $unverified, $optedOut], $payload);

        $this->assertDatabaseCount('notification_deliveries', 1);
        $delivery = NotificationDelivery::query()->firstOrFail();
        $this->assertSame('queued', $delivery->status);
        $this->assertSame($recipient->id, $delivery->user_id);
        Queue::assertPushed(SendWorkspaceEmail::class, 1);
    }

    public function test_queued_email_records_sent_and_failed_status_without_exposing_delivery_to_other_users(): void
    {
        config()->set('workspace-notifications.email_enabled', true);
        Mail::fake();
        $organization = $this->organization();
        $recipient = User::factory()->create(['notification_preferences' => ['email' => ['announcements' => true]]]);
        $other = User::factory()->create();
        $this->membership($organization, $recipient);
        $this->membership($organization, $other);
        $delivery = NotificationDelivery::query()->create([
            'organization_id' => $organization->id, 'user_id' => $recipient->id, 'kind' => 'announcement',
            'title' => 'School notice', 'body' => 'Please check the portal.', 'status' => 'queued', 'queued_at' => now(),
        ]);

        (new SendWorkspaceEmail($delivery->id))->handle(app(NotificationPreferences::class));
        $this->assertDatabaseHas('notification_deliveries', ['id' => $delivery->id, 'status' => 'sent', 'attempts' => 1]);
        Mail::assertSent(WorkspaceAlertMail::class, fn ($mail): bool => $mail->hasTo($recipient->email));

        $failed = NotificationDelivery::query()->create([
            'organization_id' => $organization->id, 'user_id' => $recipient->id, 'kind' => 'announcement',
            'title' => 'Second notice', 'body' => 'Review.', 'status' => 'queued', 'queued_at' => now(),
        ]);
        (new SendWorkspaceEmail($failed->id))->failed(new \RuntimeException('provider detail is not retained'));

        $this->assertDatabaseHas('notification_deliveries', ['id' => $failed->id, 'status' => 'failed', 'failure_code' => 'RuntimeException']);
        Sanctum::actingAs($other);
        $this->getJson("/api/v1/organizations/{$organization->id}/email-deliveries")->assertOk()->assertJsonPath('meta.total', 0);
        Sanctum::actingAs($recipient);
        $this->getJson("/api/v1/organizations/{$organization->id}/email-deliveries")->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.status', 'failed');
    }

    public function test_email_opt_out_after_queueing_cancels_delivery(): void
    {
        config()->set('workspace-notifications.email_enabled', true);
        Mail::fake();
        $organization = $this->organization();
        $recipient = User::factory()->create();
        $delivery = NotificationDelivery::query()->create([
            'organization_id' => $organization->id, 'user_id' => $recipient->id, 'kind' => 'fees',
            'title' => 'Fee notice', 'body' => 'Sign in to view.', 'status' => 'queued', 'queued_at' => now(),
        ]);
        $recipient->forceFill(['notification_preferences' => ['email' => ['fees' => false]]])->save();

        (new SendWorkspaceEmail($delivery->id))->handle(app(NotificationPreferences::class));
        $this->assertDatabaseHas('notification_deliveries', ['id' => $delivery->id, 'status' => 'cancelled']);
        Mail::assertNothingSent();
    }

    public function test_email_preferences_are_explicit_and_disabled_when_feature_flag_is_off(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/me/notification-preferences')->assertOk()
            ->assertJsonPath('email_available', false)->assertJsonPath('data.email.meetings', false);
        $this->patchJson('/api/v1/me/notification-preferences', [
            'announcements' => true, 'meetings' => true, 'fees' => true,
            'email' => ['announcements' => true, 'meetings' => false, 'fees' => false],
        ])->assertUnprocessable();
        $this->assertNull($user->fresh()->notification_preferences);
    }

    public function test_announcement_email_requires_a_verified_user_opt_in_and_is_queued(): void
    {
        config()->set('workspace-notifications.email_enabled', true);
        Queue::fake();
        $organization = $this->organization();
        $admin = User::factory()->create();
        $student = User::factory()->create(['notification_preferences' => [
            'announcements' => false,
            'email' => ['announcements' => true],
        ]]);
        foreach ([[$admin, OrganizationRole::Admin], [$student, OrganizationRole::Student]] as [$user, $role]) {
            OrganizationMembership::query()->create([
                'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role,
                'status' => MembershipStatus::Active, 'accepted_at' => now(),
            ]);
        }
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/organizations/{$organization->id}/announcements", [
            'title' => 'Schedule updated', 'body' => 'Check the portal for details.', 'audience' => 'students',
        ])->assertCreated()->assertJsonPath('data.recipient_count', 0);

        $this->assertDatabaseHas('notification_deliveries', [
            'organization_id' => $organization->id, 'user_id' => $student->id,
            'kind' => 'announcement', 'status' => 'queued',
        ]);
        Queue::assertPushed(SendWorkspaceEmail::class, 1);
    }

    private function organization(): Organization
    {
        return Organization::query()->create([
            'name' => 'Northstar Academy', 'slug' => 'northstar-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
    }

    private function membership(Organization $organization, User $user): void
    {
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id,
            'role' => OrganizationRole::Student, 'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
    }
}
