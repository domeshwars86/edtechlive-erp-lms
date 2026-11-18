<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\AssignmentSubmission;
use App\Models\AttendanceRecord;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseEnrollment;
use App\Models\CourseLessonProgress;
use App\Models\Meeting;
use App\Models\Student;
use App\Models\StudentGuardian;
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
        if ($membership->role === OrganizationRole::Parent) {
            return $this->parentSummary($request, $organization, $membership->organization->timezone ?: 'UTC');
        }
        if ($membership->role === OrganizationRole::IndependentEducator) {
            return $this->educatorSummary($organization, $membership->organization->timezone ?: 'UTC');
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
            ->whereDate('attendance_date', '>=', $now->copy()->subDays(29)->toDateString())
            ->whereDate('attendance_date', '<=', $now->toDateString())
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
            ->whereHas('academicSession', function ($query) use ($now): void {
                $query->where('is_active', true)
                    ->whereDate('starts_on', '<=', $now->toDateString())
                    ->whereDate('ends_on', '>=', $now->toDateString());
            })
            ->whereHas('academicSection', function ($query): void {
                $query->where('is_active', true);
            })
            ->get(['academic_section_id', 'academic_session_id']);
        $students = Student::query()->where('organization_id', $organization)->where('status', 'active')
            ->whereIn('academic_section_id', $assignments->pluck('academic_section_id')->unique())
            ->whereIn('academic_session_id', $assignments->pluck('academic_session_id')->unique())->count();
        $reviews = AssignmentSubmission::query()->where('organization_id', $organization)->whereNull('graded_at')
            ->whereHas('assignment.course', fn ($query) => $query->where('teacher_id', $teacher->id)->where('status', 'published'))->count();
        $classesThisWeek = TimetableEntry::query()->where('organization_id', $organization)->where('teacher_id', $teacher->id)
            ->where('is_active', true)->whereIn('weekday', $weekdays)
            ->whereHas('academicSession', function ($query) use ($weekEnd, $weekStart): void {
                $query->where('is_active', true)
                    ->whereDate('starts_on', '<=', $weekEnd->toDateString())
                    ->whereDate('ends_on', '>=', $weekStart->toDateString());
            })->count();

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

    private function parentSummary(Request $request, int $organization, string $timezone): JsonResponse
    {
        $now = Carbon::now($timezone);
        $studentIds = StudentGuardian::query()->where('organization_id', $organization)
            ->where('guardian_user_id', $request->user()->id)
            ->whereHas('student', function ($query): void {
                $query->where('status', 'active');
            })->pluck('student_id');
        $students = Student::query()->where('organization_id', $organization)->whereIn('id', $studentIds)
            ->get(['id', 'academic_section_id', 'academic_session_id']);
        $enrollments = CourseEnrollment::query()->where('organization_id', $organization)->whereIn('student_id', $studentIds)
            ->where('status', 'active')->whereHas('course', fn ($query) => $query->where('status', 'published'))
            ->get(['course_id', 'student_id']);
        $courseIds = $enrollments->pluck('course_id')->unique();
        $assignments = CourseAssignment::query()->where('organization_id', $organization)
            ->whereIn('course_id', $courseIds)->where('status', 'published')->get(['id', 'course_id']);
        $submittedKeys = AssignmentSubmission::query()->where('organization_id', $organization)
            ->whereIn('student_id', $studentIds)->whereIn('assignment_id', $assignments->pluck('id'))
            ->get(['student_id', 'assignment_id'])->mapWithKeys(fn ($submission): array => ["{$submission->student_id}:{$submission->assignment_id}" => true]);
        $pendingAssignments = 0;
        foreach ($enrollments as $enrollment) {
            foreach ($assignments->where('course_id', $enrollment->course_id) as $assignment) {
                if (! $submittedKeys->has("{$enrollment->student_id}:{$assignment->id}")) {
                    $pendingAssignments++;
                }
            }
        }

        $startDate = $now->copy()->subDays(29)->toDateString();
        $attendance = AttendanceRecord::query()->where('organization_id', $organization)->whereIn('student_id', $studentIds)
            ->whereDate('attendance_date', '>=', $startDate)->whereDate('attendance_date', '<=', $now->toDateString())
            ->get(['status'])->countBy('status');
        $attendanceRecords = $attendance->sum();
        $sectionIds = $students->pluck('academic_section_id')->filter()->unique();
        $sessionIds = $students->pluck('academic_session_id')->filter()->unique();
        $upcomingMeetings = Meeting::query()->where('organization_id', $organization)->where('status', 'scheduled')
            ->where('starts_at', '>=', $now->utc())
            ->where(function ($query) use ($sectionIds, $sessionIds, $courseIds): void {
                $query->where(function ($sectionQuery) use ($sectionIds, $sessionIds): void {
                    $sectionQuery->whereIn('academic_section_id', $sectionIds)
                        ->whereIn('academic_session_id', $sessionIds);
                });
                $query->orWhereIn('course_id', $courseIds);
            })->count();

        return response()->json(['data' => [
            'kind' => 'parent',
            'date' => $now->toDateString(),
            'children' => $students->count(),
            'assignments_pending' => $pendingAssignments,
            'attendance_recent' => [
                'records' => $attendanceRecords,
                'rate' => $attendanceRecords > 0 ? round(($attendance->get('present', 0) + $attendance->get('late', 0)) / $attendanceRecords * 100, 1) : null,
            ],
            'upcoming_meetings' => $upcomingMeetings,
        ]]);
    }

    private function educatorSummary(int $organization, string $timezone): JsonResponse
    {
        $now = Carbon::now($timezone);
        $weekEnd = $now->copy()->endOfWeek();
        $courseIds = Course::query()->where('organization_id', $organization)
            ->where('status', 'published')->pluck('id');
        $learners = CourseEnrollment::query()->where('organization_id', $organization)
            ->whereIn('course_id', $courseIds)->where('status', 'active')->distinct()->count('student_id');
        $reviews = AssignmentSubmission::query()->where('organization_id', $organization)->whereNull('graded_at')
            ->whereHas('assignment.course', fn ($query) => $query->where('status', 'published'))->count();
        $classesThisWeek = Meeting::query()->where('organization_id', $organization)->where('kind', 'class')
            ->where('status', 'scheduled')->whereBetween('starts_at', [$now->utc(), $weekEnd->utc()])->count();

        return response()->json(['data' => [
            'kind' => 'educator',
            'date' => $now->toDateString(),
            'courses' => $courseIds->count(),
            'learners' => $learners,
            'classes_this_week' => $classesThisWeek,
            'reviews_pending' => $reviews,
        ]]);
    }
}
