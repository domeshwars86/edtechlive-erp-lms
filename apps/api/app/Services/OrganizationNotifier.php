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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class OrganizationNotifier
{
    public function feeIssued(FeeRecord $fee, int $actorId): void
    {
        $fee->loadMissing('student:id,user_id,name');
        $guardianIds = StudentGuardian::query()->where('organization_id', $fee->organization_id)->where('student_id', $fee->student_id)->pluck('guardian_user_id');
        $userIds = collect([$fee->student?->user_id])->merge($guardianIds);

        $this->notify($userIds, [
            'organization_id' => $fee->organization_id,
            'kind' => 'fee_issued',
            'title' => 'New fee record',
            'body' => "{$fee->title} · INR {$fee->amount}".($fee->due_on ? " · Due {$fee->due_on->format('d M Y')}" : ''),
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
            'body' => "INR {$amount} recorded for {$fee->title}.",
            'resource_type' => 'fee_record',
            'resource_id' => $fee->id,
        ], $actorId);
    }

    public function meetingScheduled(Meeting $meeting, int $actorId): void
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

        $this->notify($userIds, [
            'organization_id' => $meeting->organization_id,
            'kind' => 'meeting_scheduled',
            'title' => 'New meeting scheduled',
            'body' => "{$meeting->title} · {$meeting->starts_at->format('d M, g:i A')}",
            'resource_type' => 'meeting',
            'resource_id' => $meeting->id,
        ], $actorId);
    }

    private function notify(Collection|array $userIds, array $payload, int $actorId): void
    {
        $users = User::query()->whereIn('id', collect($userIds)->filter()->unique()->reject(fn (int $id): bool => $id === $actorId))->get();
        Notification::send($users, new WorkspaceNotification($payload));
    }
}
