<?php

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\FeeRecord;
use App\Models\Meeting;
use App\Models\OrganizationMembership;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class OrganizationNotifier
{
    public function __construct(private readonly NotificationPreferences $preferences, private readonly WorkspaceEmailDispatcher $emailDispatcher) {}

    public function feeIssued(FeeRecord $fee, int $actorId): void
    {
        $fee->loadMissing('student:id,user_id,name');
        $guardianIds = StudentGuardian::query()->where('organization_id', $fee->organization_id)->where('student_id', $fee->student_id)->pluck('guardian_user_id');
        $userIds = collect([$fee->student?->user_id])->merge($guardianIds);

        $this->notify($userIds, [
            'organization_id' => $fee->organization_id,
            'kind' => 'fee_issued',
            'title' => 'New fee record',
            'body' => 'A new fee record is available. Sign in to view details.',
            'resource_type' => 'fee_record',
            'resource_id' => $fee->id,
        ], $actorId);
    }

    public function feePaymentRecorded(FeeRecord $fee, int $actorId, string $amount): void
    {
        $fee->loadMissing('student:id,user_id');
        $guardianIds = StudentGuardian::query()->where('organization_id', $fee->organization_id)->where('student_id', $fee->student_id)->pluck('guardian_user_id');
        $userIds = collect([$fee->student?->user_id])->merge($guardianIds);

        $this->notify($userIds, [
            'organization_id' => $fee->organization_id,
            'kind' => 'fee_payment',
            'title' => 'Payment receipt recorded',
            'body' => 'A fee payment receipt is available. Sign in to view details.',
            'resource_type' => 'fee_record',
            'resource_id' => $fee->id,
        ], $actorId);
    }

    public function meetingScheduled(Meeting $meeting, int $actorId): void
    {
        $this->notify($this->meetingRecipientIds($meeting), [
            'organization_id' => $meeting->organization_id,
            'kind' => 'meeting_scheduled',
            'title' => 'New meeting scheduled',
            'body' => "{$meeting->title} · {$this->meetingStartsAtLabel($meeting)}",
            'resource_type' => 'meeting',
            'resource_id' => $meeting->id,
        ], $actorId);
    }

    public function meetingReminder(Meeting $meeting, int $minutes, int $actorId = 0): void
    {
        $this->notify($this->meetingRecipientIds($meeting), [
            'organization_id' => $meeting->organization_id,
            'kind' => 'meeting_reminder',
            'title' => 'Meeting starting soon',
            'body' => "{$meeting->title} starts in about {$minutes} minutes · {$this->meetingStartsAtLabel($meeting)}",
            'resource_type' => 'meeting',
            'resource_id' => $meeting->id,
        ], $actorId);
    }

    private function meetingRecipientIds(Meeting $meeting): Collection
    {
        if ($meeting->kind === 'management') {
            $userIds = OrganizationMembership::query()->where('organization_id', $meeting->organization_id)->where('status', MembershipStatus::Active)
                ->whereIn('role', [OrganizationRole::Admin, OrganizationRole::Management, OrganizationRole::IndependentEducator])->pluck('user_id');
        } elseif ($meeting->academic_section_id) {
            $students = Student::query()->where('organization_id', $meeting->organization_id)->where('academic_section_id', $meeting->academic_section_id)
                ->where('academic_session_id', $meeting->academic_session_id)->where('status', 'active')->get(['id', 'user_id']);
            $studentIds = $students->pluck('id');
            $guardianUserIds = StudentGuardian::query()->where('organization_id', $meeting->organization_id)->whereIn('student_id', $studentIds)->pluck('guardian_user_id');
            $teacherUserIds = TeachingAssignment::query()->where('organization_id', $meeting->organization_id)
                ->where('academic_section_id', $meeting->academic_section_id)->where('academic_session_id', $meeting->academic_session_id)
                ->with('teacher:id,user_id')->get()->pluck('teacher.user_id');
            $userIds = $students->pluck('user_id')->merge($guardianUserIds)->merge($teacherUserIds);
        } else {
            $courseTeacherId = Course::query()->where('organization_id', $meeting->organization_id)->whereKey($meeting->course_id)->value('teacher_id');
            $teacherUserId = $courseTeacherId ? Teacher::query()->whereKey($courseTeacherId)->value('user_id') : null;
            $students = CourseEnrollment::query()->where('organization_id', $meeting->organization_id)->where('course_id', $meeting->course_id)
                ->where('status', 'active')->get(['student_id']);
            $studentIds = $students->pluck('student_id');
            $studentUserIds = Student::query()->whereIn('id', $studentIds)->pluck('user_id');
            $guardianUserIds = StudentGuardian::query()->where('organization_id', $meeting->organization_id)->whereIn('student_id', $studentIds)->pluck('guardian_user_id');
            $userIds = $studentUserIds->merge($guardianUserIds)->push($teacherUserId);
        }

        return collect($userIds)->filter()->unique()->values();
    }

    private function meetingStartsAtLabel(Meeting $meeting): string
    {
        return Carbon::parse($meeting->starts_at)->format('d M, g:i A');
    }

    public function learningActivity(Course $course, int $actorId, string $kind, string $resourceType, int $resourceId, string $title, string $body): void
    {
        if ($course->status !== 'published') {
            return;
        }

        $students = Student::query()->where('organization_id', $course->organization_id)->where('status', 'active')
            ->whereIn('id', CourseEnrollment::query()->where('organization_id', $course->organization_id)
                ->where('course_id', $course->id)->where('status', 'active')->select('student_id'))
            ->get(['id', 'user_id']);
        $userIds = $students->pluck('user_id')->merge(StudentGuardian::query()
            ->where('organization_id', $course->organization_id)->whereIn('student_id', $students->pluck('id'))
            ->pluck('guardian_user_id'));
        $activeRecipients = OrganizationMembership::query()->where('organization_id', $course->organization_id)
            ->where('status', MembershipStatus::Active)->whereIn('role', [OrganizationRole::Student, OrganizationRole::Parent])
            ->whereIn('user_id', $userIds->filter())->pluck('user_id');

        $this->notify($activeRecipients, [
            'organization_id' => $course->organization_id,
            'course_id' => $course->id,
            'kind' => $kind,
            'title' => $title,
            'body' => $body,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ], $actorId);
    }

    private function notify(Collection|array $userIds, array $payload, int $actorId): void
    {
        $recipients = User::query()->whereIn('id', collect($userIds)->filter()->unique()->reject(fn (int $id): bool => $id === $actorId))->get();
        Notification::send($recipients->filter(fn (User $user): bool => $this->preferences->allows($user, $payload['kind'])), new WorkspaceNotification($payload));
        $this->emailDispatcher->dispatch($recipients, $payload);
    }
}
