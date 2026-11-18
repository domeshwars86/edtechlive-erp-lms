<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\AttendanceRecord;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Services\OrganizationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FamilyController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function student(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($membership->role === OrganizationRole::Student, 403);
        $student = Student::query()->where('organization_id', $organization)
            ->where('user_id', $request->user()->id)->where('status', 'active')
            ->with(['academicClass:id,name', 'academicSection:id,name', 'academicSession:id,name'])->firstOrFail();

        return response()->json(['data' => $this->studentData($student)]);
    }

    public function children(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($membership->role === OrganizationRole::Parent, 403);
        $links = StudentGuardian::query()->where('organization_id', $organization)
            ->where('guardian_user_id', $request->user()->id)
            ->whereHas('student', fn (Builder $query) => $query->where('status', 'active'))
            ->with(['student.academicClass:id,name', 'student.academicSection:id,name', 'student.academicSession:id,name'])
            ->orderByDesc('is_primary')->orderBy('student_id')->paginate(min(max($request->integer('per_page', 50), 1), 100));

        return response()->json([
            'data' => $links->getCollection()->map(fn (StudentGuardian $link): array => [
                ...$this->studentData($link->student),
                'relationship' => $link->relationship,
                'is_primary' => $link->is_primary,
            ])->values(),
            'meta' => ['current_page' => $links->currentPage(), 'last_page' => $links->lastPage(), 'per_page' => $links->perPage(), 'total' => $links->total()],
        ]);
    }

    public function studentAttendance(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($membership->role === OrganizationRole::Student, 403);
        $student = Student::query()->where('organization_id', $organization)
            ->where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail();

        return $this->attendance($request, $organization, $student);
    }

    public function childAttendance(Request $request, int $organization, int $student): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($membership->role === OrganizationRole::Parent, 403);
        $studentRecord = Student::query()->where('organization_id', $organization)->where('status', 'active')->findOrFail($student);
        abort_unless(StudentGuardian::query()->where('organization_id', $organization)
            ->where('student_id', $studentRecord->id)->where('guardian_user_id', $request->user()->id)->exists(), 404);

        return $this->attendance($request, $organization, $studentRecord);
    }

    private function attendance(Request $request, int $organization, Student $student): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        if (isset($filters['from'], $filters['to']) && $filters['from'] > $filters['to']) {
            throw ValidationException::withMessages(['to' => ['The end date must be on or after the start date.']]);
        }
        $query = AttendanceRecord::query()->where('organization_id', $organization)
            ->where('student_id', $student->id)
            ->with(['academicSection:id,name,academic_class_id', 'academicSection.academicClass:id,name', 'academicSession:id,name']);
        if (isset($filters['from'])) {
            $query->whereDate('attendance_date', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate('attendance_date', '<=', $filters['to']);
        }
        $records = $query->orderByDesc('attendance_date')->paginate(min(max($request->integer('per_page', 50), 1), 100));

        return response()->json([
            'data' => $records->getCollection()->map(fn (AttendanceRecord $record): array => [
                'id' => $record->id,
                'date' => $record->attendance_date->toDateString(),
                'status' => $record->status,
                'note' => $record->note,
                'section' => ['id' => $record->academicSection->id, 'name' => $record->academicSection->name, 'class' => $record->academicSection->academicClass->name],
                'session' => ['id' => $record->academicSession->id, 'name' => $record->academicSession->name],
            ])->values(),
            'meta' => ['current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'per_page' => $records->perPage(), 'total' => $records->total()],
        ]);
    }

    private function studentData(Student $student): array
    {
        return [
            'id' => $student->id,
            'name' => $student->name,
            'admission_number' => $student->admission_number,
            'academic_class' => $student->academicClass?->only(['id', 'name']),
            'academic_section' => $student->academicSection?->only(['id', 'name']),
            'academic_session' => $student->academicSession?->only(['id', 'name']),
        ];
    }
}
