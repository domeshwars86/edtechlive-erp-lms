<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\AcademicSection;
use App\Models\AcademicSession;
use App\Models\CourseEnrollment;
use App\Models\Student;
use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentReportController
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

        $students = Student::query()->where('organization_id', $organization)->where('status', 'active')
            ->get(['id', 'academic_session_id', 'academic_section_id']);
        $sections = AcademicSection::query()->where('organization_id', $organization)
            ->with('academicClass:id,name')->orderBy('academic_session_id')->orderBy('academic_class_id')->orderBy('name')->get([
                'id', 'academic_session_id', 'academic_class_id', 'name', 'capacity', 'is_active',
            ]);
        $sessions = AcademicSession::query()->where('organization_id', $organization)
            ->orderByDesc('is_current')->orderBy('starts_on')->get(['id', 'name', 'is_current', 'is_active', 'starts_on', 'ends_on']);
        $courseEnrollments = CourseEnrollment::query()->where('organization_id', $organization)->where('status', 'active')
            ->whereHas('student', fn ($query) => $query->where('status', 'active'))
            ->whereHas('course', fn ($query) => $query->where('status', 'published'))
            ->get(['student_id']);
        $enrollmentsByStudent = $courseEnrollments->countBy('student_id');

        $sessionRows = $sessions->map(function (AcademicSession $session) use ($students, $sections, $enrollmentsByStudent): array {
            $sessionStudents = $students->where('academic_session_id', $session->id);
            $sectionRows = $sections->where('academic_session_id', $session->id)->map(function (AcademicSection $section) use ($sessionStudents, $enrollmentsByStudent): array {
                $sectionStudents = $sessionStudents->where('academic_section_id', $section->id);

                return [
                    'section_id' => $section->id,
                    'class' => $section->academicClass?->name ?? 'Unclassified',
                    'section' => $section->name,
                    'active' => $section->is_active,
                    'capacity' => $section->capacity,
                    'active_students' => $sectionStudents->count(),
                    'active_course_enrollments' => $sectionStudents->sum(fn (Student $student): int => (int) $enrollmentsByStudent->get($student->id, 0)),
                ];
            })->values();

            return [
                'session_id' => $session->id,
                'session' => $session->name,
                'is_current' => $session->is_current,
                'is_active' => $session->is_active,
                'starts_on' => $session->starts_on?->toDateString(),
                'ends_on' => $session->ends_on?->toDateString(),
                'active_students' => $sessionStudents->count(),
                'active_course_enrollments' => $sessionStudents->sum(fn (Student $student): int => (int) $enrollmentsByStudent->get($student->id, 0)),
                'sections' => $sectionRows,
            ];
        })->values();

        return response()->json(['data' => [
            'summary' => [
                'active_students' => $students->count(),
                'unassigned_students' => $students->filter(fn (Student $student): bool => ! $student->academic_session_id || ! $student->academic_section_id)->count(),
                'students_with_published_courses' => $courseEnrollments->pluck('student_id')->unique()->count(),
                'active_course_enrollments' => $courseEnrollments->count(),
            ],
            'sessions' => $sessionRows,
        ]]);
    }
}
