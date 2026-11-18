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
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeetingTest extends TestCase
{
    use RefreshDatabase;

    public function test_class_meeting_is_scoped_to_section_learners_guardians_and_assigned_teacher(): void
    {
        [$admin, $teacherUser, $studentUser, $parentUser, $otherUser, $organization, $session, $section] = $this->workspace();
        $base = "/api/v1/organizations/{$organization->id}";
        $meetingId = $this->postAs($admin, "{$base}/meetings", $this->meetingPayload($session, $section))
            ->assertCreated()->assertJsonPath('data.section.name', 'A')->json('data.id');

        $this->getAs($studentUser, "{$base}/meetings")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $meetingId);
        $this->getAs($parentUser, "{$base}/meetings")->assertOk()->assertJsonPath('data.0.id', $meetingId);
        $this->getAs($teacherUser, "{$base}/meetings")->assertOk()->assertJsonPath('data.0.id', $meetingId);
        $this->getAs($otherUser, "{$base}/meetings")->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_meetings_require_secure_urls_prevent_overlap_and_allow_cancellation(): void
    {
        [$admin, , $studentUser, , , $organization, $session, $section] = $this->workspace();
        $base = "/api/v1/organizations/{$organization->id}";
        $payload = $this->meetingPayload($session, $section);
        $this->postAs($admin, "{$base}/meetings", array_replace($payload, ['meeting_url' => 'http://meet.example.test/room']))
            ->assertUnprocessable()->assertJsonValidationErrors('meeting_url');
        $meetingId = $this->postAs($admin, "{$base}/meetings", $payload)->assertCreated()->json('data.id');
        $this->postAs($admin, "{$base}/meetings", array_replace($payload, [
            'starts_at' => now()->addMinutes(90)->toISOString(), 'ends_at' => now()->addHours(2)->addMinutes(30)->toISOString(),
        ]))->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->postAs($admin, "{$base}/meetings", array_replace($payload, [
            'kind' => 'management', 'academic_section_id' => null, 'academic_session_id' => null,
            'starts_at' => now()->addHours(2)->toISOString(), 'ends_at' => now()->addHours(3)->toISOString(),
        ]))->assertCreated();
        $this->getAs($studentUser, "{$base}/meetings")->assertOk()->assertJsonPath('meta.total', 1);
        $this->patchAs($studentUser, "{$base}/meetings/{$meetingId}", ['status' => 'cancelled'])->assertForbidden();
        $this->patchAs($admin, "{$base}/meetings/{$meetingId}", ['status' => 'cancelled'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('meetings', ['id' => $meetingId, 'status' => 'cancelled']);
    }

    private function workspace(): array
    {
        $users = [
            'admin' => User::factory()->create(),
            'teacher' => User::factory()->create(['email' => 'teacher@example.test']),
            'student' => User::factory()->create(['email' => 'student@example.test']),
            'parent' => User::factory()->create(['email' => 'parent@example.test']),
            'other' => User::factory()->create(['email' => 'other@example.test']),
        ];
        $organization = Organization::query()->create([
            'name' => 'Northstar Academy', 'slug' => 'northstar-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
        foreach ([
            'admin' => OrganizationRole::Admin,
            'teacher' => OrganizationRole::Teacher,
            'student' => OrganizationRole::Student,
            'parent' => OrganizationRole::Parent,
            'other' => OrganizationRole::Student,
        ] as $key => $role) {
            OrganizationMembership::query()->create([
                'organization_id' => $organization->id, 'user_id' => $users[$key]->id,
                'role' => $role, 'status' => MembershipStatus::Active, 'accepted_at' => now(),
            ]);
        }
        $session = AcademicSession::query()->create([
            'organization_id' => $organization->id, 'name' => '2026-27',
            'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonths(8)->toDateString(),
            'is_current' => true, 'is_active' => true,
        ]);
        $class = AcademicClass::query()->create(['organization_id' => $organization->id, 'name' => 'Grade 8', 'is_active' => true]);
        $section = AcademicSection::query()->create([
            'organization_id' => $organization->id, 'academic_class_id' => $class->id,
            'academic_session_id' => $session->id, 'name' => 'A', 'is_active' => true,
        ]);
        $teacher = Teacher::query()->create([
            'organization_id' => $organization->id, 'user_id' => $users['teacher']->id,
            'name' => 'Teacher One', 'email' => $users['teacher']->email, 'status' => 'active',
        ]);
        $subject = Subject::query()->create(['organization_id' => $organization->id, 'name' => 'Science', 'is_active' => true]);
        TeachingAssignment::query()->create([
            'organization_id' => $organization->id, 'academic_session_id' => $session->id,
            'academic_section_id' => $section->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
        ]);
        $student = Student::query()->create([
            'organization_id' => $organization->id, 'user_id' => $users['student']->id,
            'name' => 'Learner One', 'admission_number' => 'S-100', 'academic_class_id' => $class->id,
            'academic_section_id' => $section->id, 'academic_session_id' => $session->id, 'status' => 'active',
        ]);
        Student::query()->create([
            'organization_id' => $organization->id, 'user_id' => $users['other']->id,
            'name' => 'Other Learner', 'admission_number' => 'S-200', 'status' => 'active',
        ]);
        StudentGuardian::query()->create([
            'organization_id' => $organization->id, 'student_id' => $student->id,
            'guardian_user_id' => $users['parent']->id, 'linked_by' => $users['admin']->id,
            'relationship' => 'parent', 'is_primary' => true, 'linked_at' => now(),
        ]);

        return [$users['admin'], $users['teacher'], $users['student'], $users['parent'], $users['other'], $organization, $session, $section];
    }

    private function meetingPayload(AcademicSession $session, AcademicSection $section): array
    {
        return [
            'kind' => 'class', 'academic_session_id' => $session->id, 'academic_section_id' => $section->id,
            'title' => 'Science live class', 'description' => 'Join with your class.',
            'starts_at' => now()->addHours(1)->toISOString(), 'ends_at' => now()->addHours(2)->toISOString(),
            'meeting_url' => 'https://meet.google.com/abc-defg-hij',
        ];
    }

    private function postAs(User $user, string $uri, array $data)
    {
        Sanctum::actingAs($user);

        return $this->postJson($uri, $data);
    }

    private function getAs(User $user, string $uri)
    {
        Sanctum::actingAs($user);

        return $this->getJson($uri);
    }

    private function patchAs(User $user, string $uri, array $data)
    {
        Sanctum::actingAs($user);

        return $this->patchJson($uri, $data);
    }
}
