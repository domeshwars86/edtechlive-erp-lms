<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\AcademicSession;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use App\Models\TimetableEntry;
use App\Services\OrganizationAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherWorkloadReportController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless(in_array($membership->role, [
            OrganizationRole::Admin,
            OrganizationRole::Management,
            OrganizationRole::IndependentEducator,
        ], true), 403);

        $today = CarbonImmutable::now($membership->organization->timezone)->toDateString();
        $sessions = AcademicSession::query()->where('organization_id', $organization)->where('is_active', true)
            ->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today)->get(['id', 'name']);
        $sessionIds = $sessions->pluck('id');
        $teachers = Teacher::query()->where('organization_id', $organization)->where('status', 'active')
            ->orderBy('name')->get(['id', 'name']);

        $assignments = TeachingAssignment::query()->where('organization_id', $organization)
            ->whereIn('academic_session_id', $sessionIds)->whereIn('teacher_id', $teachers->pluck('id'))
            ->whereHas('academicSection', fn ($query) => $query->where('is_active', true))
            ->get(['teacher_id', 'academic_session_id', 'academic_section_id']);
        $slots = TimetableEntry::query()->where('organization_id', $organization)->whereIn('academic_session_id', $sessionIds)
            ->whereIn('teacher_id', $teachers->pluck('id'))->where('is_active', true)
            ->whereHas('academicSection', fn ($query) => $query->where('is_active', true))
            ->get(['teacher_id', 'academic_session_id', 'academic_section_id', 'starts_at', 'ends_at']);

        $learnersBySectionSession = Student::query()->where('organization_id', $organization)->where('status', 'active')
            ->whereIn('academic_session_id', $sessionIds)->whereNotNull('academic_section_id')
            ->get(['academic_session_id', 'academic_section_id'])
            ->groupBy(fn (Student $student): string => $student->academic_session_id.':'.$student->academic_section_id)
            ->map(fn ($students): int => $students->count());

        $rows = $teachers->map(function (Teacher $teacher) use ($assignments, $slots, $learnersBySectionSession): array {
            $teacherAssignments = $assignments->where('teacher_id', $teacher->id);
            $sectionSessions = $teacherAssignments->map(fn (TeachingAssignment $assignment): string => $assignment->academic_session_id.':'.$assignment->academic_section_id)->unique();
            $teacherSlots = $slots->where('teacher_id', $teacher->id);
            $weeklyMinutes = $teacherSlots->sum(function (TimetableEntry $entry): int {
                $start = CarbonImmutable::parse($entry->starts_at);
                $end = CarbonImmutable::parse($entry->ends_at);

                return max(0, (int) $start->diffInMinutes($end, false));
            });

            return [
                'teacher_id' => $teacher->id,
                'teacher' => $teacher->name,
                'section_assignments' => $sectionSessions->count(),
                'active_learners' => $sectionSessions->sum(fn (string $key): int => (int) $learnersBySectionSession->get($key, 0)),
                'weekly_timetable_slots' => $teacherSlots->count(),
                'weekly_scheduled_hours' => round($weeklyMinutes / 60, 2),
            ];
        })->values();

        return response()->json(['data' => [
            'as_of' => $today,
            'sessions' => $sessions->pluck('name')->values(),
            'summary' => [
                'active_teachers' => $rows->count(),
                'teachers_with_assignments' => $rows->where('section_assignments', '>', 0)->count(),
                'section_assignments' => $rows->sum('section_assignments'),
                'teacher_learner_assignments' => $rows->sum('active_learners'),
                'weekly_timetable_slots' => $rows->sum('weekly_timetable_slots'),
                'weekly_scheduled_hours' => round($rows->sum('weekly_scheduled_hours'), 2),
            ],
            'teachers' => $rows,
        ]]);
    }
}
