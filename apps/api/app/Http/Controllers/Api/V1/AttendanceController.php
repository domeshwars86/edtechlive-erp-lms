<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\AcademicSection;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttendanceController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $teacher = $this->authorizeAttendance($request, $organization);
        $filters = $request->validate([
            'section_id' => ['required', 'integer', Rule::exists('academic_sections', 'id')->where('organization_id', $organization)],
            'date' => ['required', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $section = $this->sectionForDate($organization, (int) $filters['section_id'], $filters['date']);
        $this->assertAssignedTeacher($teacher, $organization, $section->id, (int) $section->academic_session_id);
        $students = Student::query()
            ->where('organization_id', $organization)
            ->where('academic_section_id', $section->id)
            ->where('academic_session_id', $section->academic_session_id)
            ->where('status', 'active')
            ->orderBy('name')
            ->paginate(min(max($request->integer('per_page', 100), 1), 200));
        $marks = AttendanceRecord::query()
            ->where('organization_id', $organization)
            ->where('academic_section_id', $section->id)
            ->where('attendance_date', $filters['date'])
            ->whereIn('student_id', $students->getCollection()->pluck('id'))
            ->get(['student_id', 'status', 'note'])
            ->keyBy('student_id');

        return response()->json([
            'data' => $students->getCollection()->map(fn (Student $student): array => [
                'id' => $student->id,
                'name' => $student->name,
                'admission_number' => $student->admission_number,
                'status' => $marks->get($student->id)?->status,
                'note' => $marks->get($student->id)?->note,
            ])->values(),
            'context' => [
                'section' => ['id' => $section->id, 'name' => $section->name],
                'class' => ['id' => $section->academicClass->id, 'name' => $section->academicClass->name],
                'session' => ['id' => $section->academicSession->id, 'name' => $section->academicSession->name],
                'date' => $filters['date'],
            ],
            'meta' => [
                'current_page' => $students->currentPage(),
                'last_page' => $students->lastPage(),
                'per_page' => $students->perPage(),
                'total' => $students->total(),
            ],
        ]);
    }

    public function store(Request $request, int $organization): JsonResponse
    {
        $teacher = $this->authorizeAttendance($request, $organization, true);
        $data = $request->validate([
            'section_id' => ['required', 'integer', Rule::exists('academic_sections', 'id')->where('organization_id', $organization)],
            'date' => ['required', 'date_format:Y-m-d'],
            'records' => ['required', 'array', 'min:1', 'max:200'],
            'records.*.student_id' => ['required', 'integer', 'distinct', Rule::exists('students', 'id')->where('organization_id', $organization)],
            'records.*.status' => ['required', Rule::in(['present', 'absent', 'late', 'excused'])],
            'records.*.note' => ['nullable', 'string', 'max:500'],
            'records.*.correction_reason' => ['nullable', 'string', 'max:500'],
        ]);
        $section = $this->sectionForDate($organization, (int) $data['section_id'], $data['date']);
        $this->assertAssignedTeacher($teacher, $organization, $section->id, (int) $section->academic_session_id);
        $studentIds = collect($data['records'])->pluck('student_id')->map(fn ($id): int => (int) $id);
        $eligibleCount = Student::query()
            ->where('organization_id', $organization)
            ->where('academic_section_id', $section->id)
            ->where('academic_session_id', $section->academic_session_id)
            ->where('status', 'active')
            ->whereIn('id', $studentIds)
            ->count();

        if ($eligibleCount !== $studentIds->unique()->count()) {
            throw ValidationException::withMessages(['records' => ['Every student must be active and enrolled in the selected section and academic session.']]);
        }

        DB::transaction(function () use ($data, $organization, $request, $section, $studentIds): void {
            $existing = AttendanceRecord::query()->where('organization_id', $organization)
                ->where('academic_section_id', $section->id)
                ->where('attendance_date', $data['date'])
                ->whereIn('student_id', $studentIds)
                ->lockForUpdate()->get()->keyBy('student_id');

            foreach ($data['records'] as $index => $record) {
                $previous = $existing->get((int) $record['student_id']);
                $nextNote = $record['note'] ?? null;
                if ($previous === null || ($previous->status === $record['status'] && $previous->note === $nextNote)) {
                    continue;
                }

                $reason = trim((string) ($record['correction_reason'] ?? ''));
                if ($reason === '') {
                    throw ValidationException::withMessages([
                        "records.{$index}.correction_reason" => ['Explain why a previously saved attendance mark is changing.'],
                    ]);
                }

                AttendanceCorrection::query()->create([
                    'organization_id' => $organization,
                    'attendance_record_id' => $previous->id,
                    'actor_user_id' => $request->user()->id,
                    'previous_status' => $previous->status,
                    'status' => $record['status'],
                    'previous_note' => $previous->note,
                    'note' => $nextNote,
                    'reason' => $reason,
                ]);
            }

            $now = now();
            $rows = collect($data['records'])->map(fn (array $record): array => [
                'organization_id' => $organization,
                'academic_section_id' => $section->id,
                'academic_session_id' => $section->academic_session_id,
                'student_id' => $record['student_id'],
                'attendance_date' => $data['date'],
                'status' => $record['status'],
                'note' => $record['note'] ?? null,
                'marked_by' => $request->user()->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            AttendanceRecord::query()->upsert(
                $rows,
                ['organization_id', 'academic_section_id', 'student_id', 'attendance_date'],
                ['academic_session_id', 'status', 'note', 'marked_by', 'updated_at'],
            );
        });

        return response()->json(['message' => 'Attendance saved.', 'saved' => count($data['records'])]);
    }

    public function corrections(Request $request, int $organization): JsonResponse
    {
        $teacher = $this->authorizeAttendance($request, $organization);
        $filters = $request->validate([
            'section_id' => ['required', 'integer', Rule::exists('academic_sections', 'id')->where('organization_id', $organization)],
            'date' => ['required', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $section = $this->sectionForDate($organization, (int) $filters['section_id'], $filters['date']);
        $this->assertAssignedTeacher($teacher, $organization, $section->id, (int) $section->academic_session_id);
        $corrections = AttendanceCorrection::query()->where('organization_id', $organization)
            ->whereHas('record', fn ($query) => $query->where('academic_section_id', $section->id)
                ->where('attendance_date', $filters['date']))
            ->with(['record.student:id,name,admission_number', 'actor:id,name'])
            ->orderByDesc('id')->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => $corrections->getCollection()->map(fn (AttendanceCorrection $correction): array => [
                'id' => $correction->id,
                'student' => $correction->record?->student?->only(['id', 'name', 'admission_number']),
                'actor' => $correction->actor?->name,
                'previous_status' => $correction->previous_status,
                'status' => $correction->status,
                'previous_note' => $correction->previous_note,
                'note' => $correction->note,
                'reason' => $correction->reason,
                'created_at' => $correction->created_at?->toISOString(),
            ])->values(),
            'meta' => [
                'current_page' => $corrections->currentPage(),
                'last_page' => $corrections->lastPage(),
                'total' => $corrections->total(),
            ],
        ]);
    }

    private function authorizeAttendance(Request $request, int $organization, bool $write = false): ?Teacher
    {
        $membership = $this->access->membership($request, $organization);
        if (in_array($membership->role, [
            OrganizationRole::Admin,
            OrganizationRole::Management,
            OrganizationRole::IndependentEducator,
        ], true)) {
            if ($write) {
                $this->access->membership($request, $organization, true);
            }

            return null;
        }
        abort_unless($membership->role === OrganizationRole::Teacher, 403);
        $teacher = Teacher::query()->where('organization_id', $organization)
            ->where('user_id', $request->user()->id)->where('status', 'active')->first();
        abort_unless($teacher !== null, 403);

        return $teacher;
    }

    private function assertAssignedTeacher(?Teacher $teacher, int $organization, int $sectionId, int $sessionId): void
    {
        if ($teacher === null) {
            return;
        }
        abort_unless(TeachingAssignment::query()->where('organization_id', $organization)
            ->where('teacher_id', $teacher->id)
            ->where('academic_section_id', $sectionId)
            ->where('academic_session_id', $sessionId)
            ->exists(), 403);
    }

    private function sectionForDate(int $organization, int $sectionId, string $date): AcademicSection
    {
        $section = AcademicSection::query()->where('organization_id', $organization)
            ->with(['academicClass:id,name', 'academicSession:id,name,starts_on,ends_on'])
            ->findOrFail($sectionId);

        if ($section->academic_session_id === null || $section->academicSession === null) {
            throw ValidationException::withMessages(['section_id' => ['Assign this section to an academic session before taking attendance.']]);
        }

        $day = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        if ($day->lt($section->academicSession->starts_on) || $day->gt($section->academicSession->ends_on)) {
            throw ValidationException::withMessages(['date' => ['The attendance date must fall within the section’s academic session.']]);
        }

        return $section;
    }
}
