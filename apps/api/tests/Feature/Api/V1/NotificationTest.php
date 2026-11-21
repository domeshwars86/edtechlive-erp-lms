<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_read_and_update_in_app_notification_preferences(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/notification-preferences')->assertOk()
            ->assertJsonPath('data.announcements', true)->assertJsonPath('data.meetings', true)->assertJsonPath('data.fees', true)
            ->assertJsonPath('data.learning', true)->assertJsonPath('data.email.learning', false);
        $this->patchJson('/api/v1/me/notification-preferences', [
            'announcements' => false, 'meetings' => true, 'fees' => false, 'learning' => true,
            'email' => ['announcements' => false, 'meetings' => false, 'fees' => false, 'learning' => false],
        ])->assertOk()->assertJsonPath('data.announcements', false)->assertJsonPath('data.fees', false);
        $this->assertSame([
            'announcements' => false, 'meetings' => true, 'fees' => false, 'learning' => true,
            'email' => ['announcements' => false, 'meetings' => false, 'fees' => false, 'learning' => false],
        ], $user->fresh()->notification_preferences);
        $this->patchJson('/api/v1/me/notification-preferences', ['announcements' => 'sometimes'])
            ->assertUnprocessable();
    }

    public function test_notification_list_and_read_receipt_are_user_and_organization_scoped(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create(['email' => 'other@example.test']);
        $organization = Organization::query()->create([
            'name' => 'Northstar Academy', 'slug' => 'northstar-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
        $otherOrganization = Organization::query()->create([
            'name' => 'Westfield School', 'slug' => 'westfield-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
        foreach ([[$user, $organization], [$user, $otherOrganization], [$other, $organization]] as [$member, $org]) {
            OrganizationMembership::query()->create([
                'organization_id' => $org->id, 'user_id' => $member->id, 'role' => OrganizationRole::Admin,
                'status' => MembershipStatus::Active, 'accepted_at' => now(),
            ]);
        }
        Notification::send($user, new WorkspaceNotification([
            'organization_id' => $organization->id, 'kind' => 'meeting_scheduled',
            'title' => 'Class meeting', 'body' => 'Science class · 12 Nov', 'resource_type' => 'meeting', 'resource_id' => 25,
        ]));
        Notification::send($user, new WorkspaceNotification([
            'organization_id' => $otherOrganization->id, 'kind' => 'fee_issued',
            'title' => 'Fee notice', 'body' => 'Term fee', 'resource_type' => 'fee_record', 'resource_id' => 3,
        ]));

        Sanctum::actingAs($user);
        $base = "/api/v1/organizations/{$organization->id}/notifications";
        $response = $this->getJson($base)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.unread', 1)
            ->assertJsonPath('data.0.title', 'Class meeting');
        $id = $response->json('data.0.id');

        Sanctum::actingAs($other);
        $this->postJson("{$base}/{$id}/read")->assertNotFound();
        Sanctum::actingAs($user);
        $this->postJson("{$base}/{$id}/read")->assertOk()->assertJsonPath('data.id', $id);
        $readList = $this->getJson($base)->assertOk()->assertJsonPath('meta.unread', 0);
        $this->assertNotNull($readList->json('data.0.read_at'));
    }

    public function test_marking_a_notification_read_does_not_reorder_paginated_results(): void
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Stable Notices School', 'slug' => 'stable-notices-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => OrganizationRole::Admin,
            'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);

        foreach (['First notice', 'Second notice', 'Third notice'] as $title) {
            Notification::send($user, new WorkspaceNotification([
                'organization_id' => $organization->id, 'kind' => 'announcement', 'title' => $title, 'body' => 'Update',
            ]));
        }

        Sanctum::actingAs($user);
        $base = "/api/v1/organizations/{$organization->id}/notifications";
        $firstPage = $this->getJson("{$base}?per_page=2&page=1")->assertOk();
        $secondPageBefore = $this->getJson("{$base}?per_page=2&page=2")->assertOk()->json('data');
        $notificationId = $firstPage->json('data.0.id');

        $this->postJson("{$base}/{$notificationId}/read")->assertOk();

        $secondPageAfter = $this->getJson("{$base}?per_page=2&page=2")->assertOk()->json('data');
        $this->assertSame(
            array_column($secondPageBefore, 'id'),
            array_column($secondPageAfter, 'id'),
            'Read receipts must not move notifications across pages.',
        );
    }
}
