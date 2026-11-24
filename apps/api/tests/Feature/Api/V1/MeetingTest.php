<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\AcademicClass;
use App\Models\AcademicSection;
use App\Models\AcademicSession;
use App\Models\Meeting;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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

    public function test_meeting_reminders_notify_scoped_participants_once_within_the_lead_window(): void
    {
        Notification::fake();
        [$admin, $teacherUser, $studentUser, $parentUser, $otherUser, $organization, $session, $section] = $this->workspace();
        $base = "/api/v1/organizations/{$organization->id}";
        $startsAt = now()->addMinutes(10);
        $meetingId = $this->postAs($admin, "{$base}/meetings", array_replace($this->meetingPayload($session, $section), [
            'starts_at' => $startsAt->toISOString(), 'ends_at' => $startsAt->copy()->addHour()->toISOString(),
        ]))->assertCreated()->json('data.id');

        Notification::fake();
        $this->artisan('meetings:send-reminders')->expectsOutput('Sent 1 meeting reminders for meetings starting within 15 minutes.')->assertSuccessful();
        $this->artisan('meetings:send-reminders')->expectsOutput('Sent 0 meeting reminders for meetings starting within 15 minutes.')->assertSuccessful();

        foreach ([$teacherUser, $studentUser, $parentUser] as $recipient) {
            $sent = Notification::sent($recipient, WorkspaceNotification::class);
            $this->assertCount(1, $sent);
            $this->assertSame('meeting_reminder', $sent->first()->toDatabase($recipient)['kind']);
            $this->assertSame($meetingId, $sent->first()->toDatabase($recipient)['resource_id']);
        }
        Notification::assertNotSentTo($otherUser, WorkspaceNotification::class);
        $this->assertDatabaseHas('meetings', ['id' => $meetingId]);
        $this->assertNotNull(Meeting::query()->findOrFail($meetingId)->reminder_sent_at);
    }

    public function test_cancelled_meetings_are_not_reminded_and_invalid_lead_time_is_rejected(): void
    {
        Notification::fake();
        [$admin, , $studentUser, , , $organization, $session, $section] = $this->workspace();
        $base = "/api/v1/organizations/{$organization->id}";
        $startsAt = now()->addMinutes(10);
        $meetingId = $this->postAs($admin, "{$base}/meetings", array_replace($this->meetingPayload($session, $section), [
            'starts_at' => $startsAt->toISOString(), 'ends_at' => $startsAt->copy()->addHour()->toISOString(),
        ]))->assertCreated()->json('data.id');
        $this->patchAs($admin, "{$base}/meetings/{$meetingId}", ['status' => 'cancelled'])->assertOk();

        Notification::fake();
        $this->artisan('meetings:send-reminders')->expectsOutput('Sent 0 meeting reminders for meetings starting within 15 minutes.')->assertSuccessful();
        $this->artisan('meetings:send-reminders --minutes=0')->expectsOutput('Reminder lead time must be a whole number between 1 and 1440 minutes.')->assertFailed();

        Notification::assertNotSentTo($studentUser, WorkspaceNotification::class);
        $this->assertDatabaseHas('meetings', ['id' => $meetingId, 'status' => 'cancelled', 'reminder_sent_at' => null]);
    }

    public function test_meeting_reminder_respects_a_participants_meeting_preference(): void
    {
        Notification::fake();
        [$admin, $teacherUser, $studentUser, $parentUser, , $organization, $session, $section] = $this->workspace();
        $studentUser->forceFill(['notification_preferences' => ['meetings' => false]])->save();
        $base = "/api/v1/organizations/{$organization->id}";
        $startsAt = now()->addMinutes(10);
        $this->postAs($admin, "{$base}/meetings", array_replace($this->meetingPayload($session, $section), [
            'starts_at' => $startsAt->toISOString(), 'ends_at' => $startsAt->copy()->addHour()->toISOString(),
        ]))->assertCreated();

        Notification::fake();
        $this->artisan('meetings:send-reminders')->assertSuccessful();

        Notification::assertNotSentTo($studentUser, WorkspaceNotification::class);
        Notification::assertSentTo($teacherUser, WorkspaceNotification::class);
        Notification::assertSentTo($parentUser, WorkspaceNotification::class);
    }

    public function test_private_recordings_require_consent_and_are_visible_to_enrolled_learners_only_after_publication(): void
    {
        Storage::fake('private');
        [$admin, , $studentUser, $parentUser, $otherUser, $organization, $session, $section] = $this->workspace();
        $base = "/api/v1/organizations/{$organization->id}";
        $meetingId = $this->postAs($admin, "{$base}/meetings", $this->meetingPayload($session, $section))->assertCreated()->json('data.id');
        $recordingPath = "{$base}/meetings/{$meetingId}/recordings";
        $this->postFormAs($admin, $recordingPath, [
            'title' => 'Science lesson recording', 'file' => UploadedFile::fake()->create('science.mp4', 12, 'video/mp4'),
            'consent_confirmed' => '0',
        ])->assertUnprocessable()->assertJsonValidationErrors('consent_confirmed');
        $recordingId = $this->postFormAs($admin, $recordingPath, [
            'title' => 'Science lesson recording', 'description' => 'Week one review',
            'file' => UploadedFile::fake()->create('science.mp4', 12, 'video/mp4'), 'consent_confirmed' => '1',
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->getAs($studentUser, $recordingPath)->assertOk()->assertJsonCount(0, 'data');
        $this->getAs($otherUser, $recordingPath)->assertNotFound();
        $this->patchAs($studentUser, "{$recordingPath}/{$recordingId}", ['status' => 'published'])->assertForbidden();
        $this->patchAs($admin, "{$recordingPath}/{$recordingId}", ['status' => 'published'])->assertOk();
        $this->getAs($studentUser, $recordingPath)->assertOk()->assertJsonPath('data.0.id', $recordingId);
        $signedPath = $this->postAs($studentUser, "{$recordingPath}/{$recordingId}/download-link", [])
            ->assertOk()->json('data.path');
        $this->get($signedPath)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getAs($parentUser, "{$recordingPath}/{$recordingId}/download")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->patchAs($admin, "{$recordingPath}/{$recordingId}", ['status' => 'withdrawn'])->assertOk();
        $this->getAs($studentUser, $recordingPath)->assertOk()->assertJsonCount(0, 'data');
        $this->get($signedPath)->assertNotFound();
        $this->patchAs($admin, "{$recordingPath}/{$recordingId}", ['status' => 'published'])->assertStatus(409);
    }

    public function test_recording_links_must_be_https_and_cannot_be_created_without_notice_attestation(): void
    {
        [$admin, , , , , $organization, $session, $section] = $this->workspace();
        $base = "/api/v1/organizations/{$organization->id}";
        $meetingId = $this->postAs($admin, "{$base}/meetings", $this->meetingPayload($session, $section))->assertCreated()->json('data.id');
        $recordingPath = "{$base}/meetings/{$meetingId}/recordings";
        $payload = ['title' => 'Recording link', 'external_url' => 'http://video.example.test/watch', 'consent_confirmed' => '1'];
        $this->postAs($admin, $recordingPath, $payload)->assertUnprocessable()->assertJsonValidationErrors('external_url');
        $this->postAs($admin, $recordingPath, ['title' => 'Recording link', 'external_url' => 'https://video.example.test/watch'])
            ->assertUnprocessable()->assertJsonValidationErrors('consent_confirmed');
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

    private function postFormAs(User $user, string $uri, array $data)
    {
        Sanctum::actingAs($user);

        return $this->withHeader('Accept', 'application/json')->post($uri, $data);
    }
}
