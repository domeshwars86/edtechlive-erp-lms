<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\AssignmentSubmission;
use App\Models\AttendanceRecord;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseEnrollment;
use App\Models\CourseLessonProgress;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
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
        if ($membership->role === OrganizationRole::Teacher) {
            return $this->teacherSummary($request, $organization, $membership->organization->timezone ?: 'UTC');
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
            ->where('student_id', $student->id)->whereIn('course_id', $courseIds)->whereNotNull('completed_at')
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

    private function teacherSummary(Request $request, int $organization, string $timezone): JsonResponse
    {
        $teacher = Teacher::query()->where('organization_id', $organization)
            ->where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail();
        $now = Carbon::now($timezone);
        $weekStart = $now->copy()->startOfWeek();
        $weekEnd = $now->copy()->endOfWeek();
        $weekdays = collect(range(0, 6))->map(fn (int $offset): int => ($weekStart->dayOfWeekIso - 1 + $offset) % 7);
        $assignments = TeachingAssignment::query()->where('organization_id', $organization)
            ->where('teacher_id', $teacher->id)
            ->whereHas('academicSession', fn ($query) => $query->where('is_active', true)
                ->whereDate('starts_on', '<=', $now->toDateString())->whereDate('ends_on', '>=', $now->toDateString()))
            ->whereHas('academicSection', fn ($query) => $query->where('is_active', true))
            ->get(['academic_section_id', 'academic_session_id']);
        $students = Student::query()->where('organization_id', $organization)->where('status', 'active')
            ->whereIn('academic_section_id', $assignments->pluck('academic_section_id')->unique())
            ->whereIn('academic_session_id', $assignments->pluck('academic_session_id')->unique())->count();
        $reviews = AssignmentSubmission::query()->where('organization_id', $organization)->whereNull('graded_at')
            ->whereHas('assignment.course', fn ($query) => $query->where('teacher_id', $teacher->id)->where('status', 'published'))->count();
        $classesThisWeek = TimetableEntry::query()->where('organization_id', $organization)->where('teacher_id', $teacher->id)
            ->where('is_active', true)->whereIn('weekday', $weekdays)
            ->whereHas('academicSession', fn ($query) => $query->where('is_active', true)
                ->whereDate('starts_on', '<=', $weekEnd->toDateString())->whereDate('ends_on', '>=', $weekStart->toDateString()))->count();

        return response()->json(['data' => [
            'kind' => 'teacher',
            'date' => $now->toDateString(),
            'classes' => $assignments->pluck('academic_section_id')->unique()->count(),
            'students' => $students,
            'reviews_pending' => $reviews,
            'classes_this_week' => $classesThisWeek,
            'courses' => Course::query()->where('organization_id', $organization)
                ->where('teacher_id', $teacher->id)->where('status', 'published')->count(),
        ]]);
    }
}
