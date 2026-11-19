<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_publish_role_targeted_announcement_to_in_app_feed(): void
    {
        $organization = $this->organization('Northstar Academy');
        $admin = User::factory()->create();
        $student = User::factory()->create();
        $teacher = User::factory()->create();
        $parent = User::factory()->create();
        foreach ([[$admin, OrganizationRole::Admin], [$student, OrganizationRole::Student], [$teacher, OrganizationRole::Teacher], [$parent, OrganizationRole::Parent]] as [$user, $role]) {
            $this->membership($organization, $user, $role);
        }
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/organizations/{$organization->id}/announcements", [
            'title' => 'Science fair', 'body' => 'Please register by Friday.', 'audience' => 'students',
        ])->assertCreated()->assertJsonPath('data.recipient_count', 1)->assertJsonPath('data.audience', 'students');
        $this->getJson("/api/v1/organizations/{$organization->id}/announcements")->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($student);
        $this->getJson("/api/v1/organizations/{$organization->id}/notifications?per_page=100")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'announcement')->assertJsonPath('data.0.title', 'Science fair');
        Sanctum::actingAs($teacher);
        $this->getJson("/api/v1/organizations/{$organization->id}/notifications?per_page=100")->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($parent);
        $this->postJson("/api/v1/organizations/{$organization->id}/announcements", [])->assertForbidden();
    }

    public function test_announcement_creation_is_limited_to_authorized_manager_roles(): void
    {
        $organization = $this->organization('Westfield Academy');
        $teacher = User::factory()->create();
        $this->membership($organization, $teacher, OrganizationRole::Teacher);
        Sanctum::actingAs($teacher);
        $this->postJson("/api/v1/organizations/{$organization->id}/announcements", [
            'title' => 'Notice', 'body' => 'Message', 'audience' => 'all',
        ])->assertForbidden();
    }

    public function test_announcement_delivery_respects_recipient_preferences(): void
    {
        $organization = $this->organization('Preference Academy');
        $admin = User::factory()->create();
        $student = User::factory()->create();
        $student->forceFill(['notification_preferences' => ['announcements' => false]])->save();
        $this->membership($organization, $admin, OrganizationRole::Admin);
        $this->membership($organization, $student, OrganizationRole::Student);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/organizations/{$organization->id}/announcements", [
            'title' => 'Exam schedule', 'body' => 'Check your portal for details.', 'audience' => 'students',
        ])->assertCreated()->assertJsonPath('data.recipient_count', 0);

        Sanctum::actingAs($student);
        $this->getJson("/api/v1/organizations/{$organization->id}/notifications")->assertOk()->assertJsonCount(0, 'data');
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
