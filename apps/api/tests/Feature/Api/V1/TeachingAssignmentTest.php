<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\AcademicClass;
use App\Models\AcademicSection;
use App\Models\AcademicSession;
use App\Models\Organization;
use App\Models\OrganizationBranch;
use App\Models\OrganizationMembership;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use App\Models\TimetableEntry;
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
        $schedule = TimetableEntry::query()->create([
            'organization_id' => $organization->id,
            'academic_session_id' => $context['session']->id,
            'academic_section_id' => $context['section']->id,
            'subject_id' => $context['subject']->id,
            'teacher_id' => $context['teacher']->id,
            'weekday' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
        ]);
        $this->deleteJson("{$endpoint}/{$id}")->assertOk();
        $this->getJson("{$endpoint}?academic_session_id={$context['session']->id}")->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertDatabaseHas('timetable_entries', ['id' => $schedule->id, 'teacher_id' => null]);
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

    public function test_teacher_can_read_only_their_own_assignments(): void
    {
        [$user, $organization, $context] = $this->assignmentContext(OrganizationRole::Teacher);
        $context['teacher']->update(['user_id' => $user->id]);
        TeachingAssignment::query()->create([
            'organization_id' => $organization->id,
            'academic_session_id' => $context['session']->id,
            'academic_section_id' => $context['section']->id,
            'subject_id' => $context['subject']->id,
            'teacher_id' => $context['teacher']->id,
        ]);
        $otherTeacher = Teacher::query()->create([
            'organization_id' => $organization->id, 'name' => 'Other Teacher', 'status' => 'active',
        ]);
        TeachingAssignment::query()->create([
            'organization_id' => $organization->id,
            'academic_session_id' => $context['session']->id,
            'academic_section_id' => $context['section']->id,
            'subject_id' => $context['subject']->id,
            'teacher_id' => $otherTeacher->id,
        ]);
        Sanctum::actingAs($user);
        $endpoint = "/api/v1/organizations/{$organization->id}/teaching-assignments";

        $this->getJson($endpoint)->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.teacher.id', $context['teacher']->id);
        $this->getJson("{$endpoint}?teacher_id={$otherTeacher->id}")->assertForbidden();
    }

    public function test_teacher_assignment_must_match_the_class_branch(): void
    {
        [$user, $organization, $context] = $this->assignmentContext();
        $branch = OrganizationBranch::query()->create(['organization_id' => $organization->id, 'name' => 'North campus']);
        $otherBranch = OrganizationBranch::query()->create(['organization_id' => $organization->id, 'name' => 'South campus']);
        $context['section']->academicClass->update(['organization_branch_id' => $branch->id]);
        $context['teacher']->update(['organization_branch_id' => $otherBranch->id]);
        Sanctum::actingAs($user);
        $endpoint = "/api/v1/organizations/{$organization->id}/teaching-assignments";
        $payload = [
            'academic_session_id' => $context['session']->id, 'academic_section_id' => $context['section']->id,
            'subject_id' => $context['subject']->id, 'teacher_id' => $context['teacher']->id,
        ];

        $this->postJson($endpoint, $payload)->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
        $context['teacher']->update(['organization_branch_id' => $branch->id]);
        $this->postJson($endpoint, $payload)->assertCreated()->assertJsonPath('data.teacher.branch.name', 'North campus');
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
