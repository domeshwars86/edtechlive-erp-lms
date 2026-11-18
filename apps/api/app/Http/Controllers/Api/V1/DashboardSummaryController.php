<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\AssignmentSubmission;
use App\Models\AttendanceRecord;
use App\Models\CourseAssignment;
use App\Models\CourseEnrollment;
use App\Models\CourseLessonProgress;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TimetableEntry;
use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardSummaryController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        if ($membership->role === OrganizationRole::Student) {
            return $this->studentSummary($request, $organization, $membership->organization->timezone ?: 'UTC');
        }

        abort_unless(in_array($membership->role, [
            OrganizationRole::Admin,
            OrganizationRole::Management,
            OrganizationRole::IndependentEducator,
        ], true), 403);

        $timezone = $membership->organization->timezone ?: 'UTC';
        $now = Carbon::now($timezone);
        $today = $now->toDateString();
        $weekday = $now->dayOfWeekIso - 1;
        $attendanceRecords = AttendanceRecord::query()
            ->where('organization_id', $organization)
            ->whereDate('attendance_date', $today)
            ->whereHas('student', function ($query): void {
                $query->where('status', 'active');
            })
            ->get(['status']);
        $attendance = $attendanceRecords->countBy('status');
        $records = $attendance->sum();

        return response()->json(['data' => [
            'date' => $today,
            'students' => Student::query()->where('organization_id', $organization)->where('status', 'active')->count(),
            'teachers' => Teacher::query()->where('organization_id', $organization)->where('status', 'active')->count(),
            'classes_today' => TimetableEntry::query()
                ->where('organization_id', $organization)
                ->where('weekday', $weekday)
                ->where('is_active', true)
                ->whereHas('academicSession', function ($query) use ($today): void {
                    $query->where('is_active', true)
                        ->whereDate('starts_on', '<=', $today)
                        ->whereDate('ends_on', '>=', $today);
                })
                ->count(),
            'attendance_today' => [
                'records' => $records,
                'present' => $attendance->get('present', 0),
                'late' => $attendance->get('late', 0),
                'absent' => $attendance->get('absent', 0),
                'excused' => $attendance->get('excused', 0),
                'rate' => $records > 0 ? round(($attendance->get('present', 0) + $attendance->get('late', 0)) / $records * 100, 1) : null,
            ],
        ]]);
    }

    private function studentSummary(Request $request, int $organization, string $timezone): JsonResponse
    {
        $student = Student::query()->where('organization_id', $organization)
            ->where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail();
        $now = Carbon::now($timezone);
        $courseIds = CourseEnrollment::query()->where('organization_id', $organization)
            ->where('student_id', $student->id)->where('status', 'active')
            ->whereHas('course', fn ($query) => $query->where('status', 'published'))->pluck('course_id');
        $submittedIds = AssignmentSubmission::query()->where('organization_id', $organization)
            ->where('student_id', $student->id)->pluck('assignment_id');
        $pendingAssignments = CourseAssignment::query()->where('organization_id', $organization)
            ->whereIn('course_id', $courseIds)->where('status', 'published')
            ->whereNotIn('id', $submittedIds)->count();
        $lessonsCompleted = CourseLessonProgress::query()->where('organization_id', $organization)
            ->where('student_id', $student->id)->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$now->copy()->subDays(6)->startOfDay()->utc(), $now->copy()->endOfDay()->utc()])
            ->whereHas('lesson', fn ($query) => $query->where('status', 'published'))->count();
        $attendance = AttendanceRecord::query()->where('organization_id', $organization)->where('student_id', $student->id)
            ->whereBetween('attendance_date', [$now->copy()->subDays(29)->toDateString(), $now->toDateString()])
            ->get(['status'])->countBy('status');
        $records = $attendance->sum();

        return response()->json(['data' => [
            'kind' => 'student',
            'date' => $now->toDateString(),
            'active_courses' => $courseIds->count(),
            'lessons_completed_week' => $lessonsCompleted,
            'assignments_pending' => $pendingAssignments,
            'attendance_recent' => [
                'records' => $records,
                'rate' => $records > 0 ? round(($attendance->get('present', 0) + $attendance->get('late', 0)) / $records * 100, 1) : null,
            ],
        ]]);
    }
}
