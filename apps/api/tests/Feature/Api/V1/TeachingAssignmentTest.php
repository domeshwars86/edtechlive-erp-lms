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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeachingAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_assign_list_and_remove_a_teacher(): void
    {
        [$user, $organization, $context] = $this->assignmentContext();
        Sanctum::actingAs($user);
        $endpoint = "/api/v1/organizations/{$organization->id}/teaching-assignments";
        $payload = [
            'academic_session_id' => $context['session']->id,
            'academic_section_id' => $context['section']->id,
            'subject_id' => $context['subject']->id,
            'teacher_id' => $context['teacher']->id,
        ];

        $response = $this->postJson($endpoint, $payload)->assertCreated()->assertJsonPath('data.teacher.name', 'Teacher Example');
        $id = $response->json('data.id');
        $this->postJson($endpoint, $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson("{$endpoint}?academic_session_id={$context['session']->id}")->assertOk()->assertJsonPath('meta.total', 1);
        $this->deleteJson("{$endpoint}/{$id}")->assertOk();
        $this->getJson("{$endpoint}?academic_session_id={$context['session']->id}")->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_assignment_section_must_belong_to_the_selected_session(): void
    {
        [$user, $organization, $context] = $this->assignmentContext();
        $otherSession = AcademicSession::query()->create([
            'organization_id' => $organization->id,
            'name' => '2027–2028',
            'starts_on' => '2027-06-01',
            'ends_on' => '2028-05-31',
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/organizations/{$organization->id}/teaching-assignments", [
            'academic_session_id' => $otherSession->id,
            'academic_section_id' => $context['section']->id,
            'subject_id' => $context['subject']->id,
            'teacher_id' => $context['teacher']->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('academic_section_id');
    }

    public function test_student_members_cannot_read_or_create_assignments(): void
    {
        [$user, $organization, $context] = $this->assignmentContext(OrganizationRole::Student);
        Sanctum::actingAs($user);
        $endpoint = "/api/v1/organizations/{$organization->id}/teaching-assignments";

        $this->getJson($endpoint)->assertForbidden();
        $this->postJson($endpoint, [
            'academic_session_id' => $context['session']->id,
            'academic_section_id' => $context['section']->id,
            'subject_id' => $context['subject']->id,
            'teacher_id' => $context['teacher']->id,
        ])->assertForbidden();
    }

    private function assignmentContext(OrganizationRole $role = OrganizationRole::Admin): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Northstar Academy', 'slug' => 'northstar-'.str()->random(6), 'type' => OrganizationType::School,
            'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role,
            'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
        $session = AcademicSession::query()->create([
            'organization_id' => $organization->id, 'name' => '2026–2027',
            'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31', 'is_current' => true,
        ]);
        $class = AcademicClass::query()->create(['organization_id' => $organization->id, 'name' => 'Grade 5']);
        $section = AcademicSection::query()->create([
            'organization_id' => $organization->id, 'academic_class_id' => $class->id,
            'academic_session_id' => $session->id, 'name' => 'A',
        ]);
        $subject = Subject::query()->create(['organization_id' => $organization->id, 'name' => 'Science']);
        $teacher = Teacher::query()->create(['organization_id' => $organization->id, 'name' => 'Teacher Example', 'status' => 'active']);

        return [$user, $organization, compact('session', 'section', 'subject', 'teacher')];
    }
}
