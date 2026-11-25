<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\AcademicClass;
use App\Models\AcademicSection;
use App\Models\AcademicSession;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TimetableTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_read_and_deactivate_a_timetable_entry(): void
    {
        [$user, $organization, $context] = $this->scheduleContext();
        Sanctum::actingAs($user);
        $endpoint = "/api/v1/organizations/{$organization->id}/timetable";
        $response = $this->postJson($endpoint, $this->entryPayload($context))
            ->assertCreated()->assertJsonPath('data.subject.name', 'Science')->assertJsonPath('data.teacher.name', 'Teacher Example');
        $entryId = $response->json('data.id');

        $this->getJson("{$endpoint}?academic_session_id={$context['session']->id}&weekday=1")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $entryId);
        $this->patchJson("{$endpoint}/{$entryId}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_overlapping_section_teacher_or_room_slots_are_rejected(): void
    {
        [$user, $organization, $context] = $this->scheduleContext();
        Sanctum::actingAs($user);
        $endpoint = "/api/v1/organizations/{$organization->id}/timetable";
        $this->postJson($endpoint, $this->entryPayload($context))->assertCreated();
        $overlap = $this->entryPayload($context);
        $overlap['academic_section_id'] = $context['secondSection']->id;
        $overlap['starts_at'] = '09:30';
        $overlap['ends_at'] = '10:30';

        $this->postJson($endpoint, $overlap)->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    }

    public function test_admin_can_edit_a_timetable_entry_without_conflicting_with_itself(): void
    {
        [$user, $organization, $context] = $this->scheduleContext();
        Sanctum::actingAs($user);
        $endpoint = "/api/v1/organizations/{$organization->id}/timetable";
        $created = $this->postJson($endpoint, $this->entryPayload($context))->assertCreated();
        $entryId = $created->json('data.id');

        $this->patchJson("{$endpoint}/{$entryId}", [
            'weekday' => 2,
            'starts_at' => '10:00',
            'ends_at' => '11:00',
            'room' => 'Room 2',
        ])->assertOk()
            ->assertJsonPath('data.weekday', 2)
            ->assertJsonPath('data.starts_at', '10:00')
            ->assertJsonPath('data.ends_at', '11:00')
            ->assertJsonPath('data.room', 'Room 2');
    }

    public function test_timetable_update_rejects_a_conflicting_slot_and_preserves_existing_entry(): void
    {
        [$user, $organization, $context] = $this->scheduleContext();
        Sanctum::actingAs($user);
        $endpoint = "/api/v1/organizations/{$organization->id}/timetable";
        $first = $this->postJson($endpoint, $this->entryPayload($context))->assertCreated();
        $second = $this->entryPayload($context);
        $second['academic_section_id'] = $context['secondSection']->id;
        $second['starts_at'] = '11:00';
        $second['ends_at'] = '12:00';
        $secondEntry = $this->postJson($endpoint, $second)->assertCreated();

        $this->patchJson("{$endpoint}/{$first->json('data.id')}", [
            'starts_at' => '11:30',
            'ends_at' => '12:30',
        ])->assertUnprocessable()->assertJsonValidationErrors('starts_at');

        $this->getJson("{$endpoint}?academic_session_id={$context['session']->id}&weekday=1")
            ->assertOk()
            ->assertJsonPath('data.0.id', $first->json('data.id'))
            ->assertJsonPath('data.0.starts_at', '09:00')
            ->assertJsonPath('data.1.id', $secondEntry->json('data.id'));
    }

    public function test_meeting_links_must_use_https(): void
    {
        [$user, $organization, $context] = $this->scheduleContext();
        Sanctum::actingAs($user);
        $payload = $this->entryPayload($context);
        $payload['meeting_url'] = 'http://example.test/class';

        $this->postJson("/api/v1/organizations/{$organization->id}/timetable", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('meeting_url');
    }

    public function test_students_cannot_read_staff_timetable_and_other_tenants_are_hidden(): void
    {
        [$user, $organization] = $this->scheduleContext(OrganizationRole::Student);
        Sanctum::actingAs($user);
        $otherOrganization = $this->organization('Other Academy');

        $this->getJson("/api/v1/organizations/{$organization->id}/timetable")->assertForbidden();
        $this->getJson("/api/v1/organizations/{$otherOrganization->id}/timetable")->assertNotFound();
    }

    public function test_section_session_must_match_the_timetable_session(): void
    {
        [$user, $organization, $context] = $this->scheduleContext();
        $otherSession = AcademicSession::query()->create([
            'organization_id' => $organization->id,
            'name' => '2027–2028',
            'starts_on' => '2027-06-01',
            'ends_on' => '2028-05-31',
            'is_current' => false,
        ]);
        Sanctum::actingAs($user);
        $payload = $this->entryPayload($context);
        $payload['academic_session_id'] = $otherSession->id;

        $this->postJson("/api/v1/organizations/{$organization->id}/timetable", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('academic_section_id');
    }

    public function test_teacher_sees_only_timetable_entries_assigned_to_their_linked_profile(): void
    {
        [$user, $organization, $context] = $this->scheduleContext(OrganizationRole::Teacher);
        $context['teacher']->update(['user_id' => $user->id]);
        $otherTeacher = Teacher::query()->create(['organization_id' => $organization->id, 'name' => 'Other Teacher', 'status' => 'active']);
        TeachingAssignment::query()->create([
            'organization_id' => $organization->id,
            'academic_session_id' => $context['session']->id,
            'academic_section_id' => $context['secondSection']->id,
            'subject_id' => $context['subject']->id,
            'teacher_id' => $otherTeacher->id,
        ]);
        $admin = User::factory()->create();
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $admin->id,
            'role' => OrganizationRole::Admin, 'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
        Sanctum::actingAs($admin);
        $endpoint = "/api/v1/organizations/{$organization->id}/timetable";
        $this->postJson($endpoint, $this->entryPayload($context))->assertCreated();
        $otherEntry = $this->entryPayload($context);
        $otherEntry['academic_section_id'] = $context['secondSection']->id;
        $otherEntry['teacher_id'] = $otherTeacher->id;
        $otherEntry['starts_at'] = '11:00';
        $otherEntry['ends_at'] = '12:00';
        $this->postJson($endpoint, $otherEntry)->assertCreated();

        Sanctum::actingAs($user);
        $this->getJson("{$endpoint}?academic_session_id={$context['session']->id}")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.teacher.id', $context['teacher']->id);
    }

    private function scheduleContext(OrganizationRole $role = OrganizationRole::Admin): array
    {
        $user = User::factory()->create();
        $organization = $this->organization('Northstar Academy');
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => MembershipStatus::Active,
            'accepted_at' => now(),
        ]);
        $session = AcademicSession::query()->create([
            'organization_id' => $organization->id,
            'name' => '2026–2027',
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-05-31',
            'is_current' => true,
        ]);
        $class = AcademicClass::query()->create(['organization_id' => $organization->id, 'name' => 'Grade 5']);
        $section = AcademicSection::query()->create([
            'organization_id' => $organization->id,
            'academic_class_id' => $class->id,
            'academic_session_id' => $session->id,
            'name' => 'A',
        ]);
        $secondSection = AcademicSection::query()->create([
            'organization_id' => $organization->id,
            'academic_class_id' => $class->id,
            'academic_session_id' => $session->id,
            'name' => 'B',
        ]);
        $subject = Subject::query()->create(['organization_id' => $organization->id, 'name' => 'Science']);
        $teacher = Teacher::query()->create(['organization_id' => $organization->id, 'name' => 'Teacher Example', 'status' => 'active']);
        TeachingAssignment::query()->create([
            'organization_id' => $organization->id,
            'academic_session_id' => $session->id,
            'academic_section_id' => $section->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
        ]);
        TeachingAssignment::query()->create([
            'organization_id' => $organization->id,
            'academic_session_id' => $session->id,
            'academic_section_id' => $secondSection->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
        ]);

        return [$user, $organization, compact('session', 'class', 'section', 'secondSection', 'subject', 'teacher')];
    }

    private function entryPayload(array $context): array
    {
        return [
            'academic_session_id' => $context['session']->id,
            'academic_section_id' => $context['section']->id,
            'subject_id' => $context['subject']->id,
            'teacher_id' => $context['teacher']->id,
            'weekday' => 1,
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'room' => 'Room 1',
            'meeting_url' => 'https://meet.google.com/abc-defg-hij',
        ];
    }

    private function organization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name,
            'slug' => str()->slug($name).'-'.str()->random(6),
            'type' => OrganizationType::School,
            'status' => 'active',
            'timezone' => 'Asia/Kolkata',
        ]);
    }
}
