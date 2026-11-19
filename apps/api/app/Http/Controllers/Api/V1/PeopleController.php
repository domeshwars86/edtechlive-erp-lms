<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\AcademicClass;
use App\Models\AcademicSection;
use App\Models\OrganizationBranch;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\OrganizationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PeopleController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function students(Request $request, int $organization): JsonResponse
    {
        $this->authorizeRoster($request, $organization);
        $query = Student::query()->where('organization_id', $organization)
            ->with(['branch:id,name,code', 'academicClass:id,name,organization_branch_id', 'academicClass.branch:id,name,code', 'academicSection:id,name', 'academicSession:id,name']);
        $this->filter($request, $query, ['name', 'email', 'admission_number'], $organization);

        return $this->collection($query->orderBy('name')->orderBy('id'));
    }

    public function storeStudent(Request $request, int $organization): JsonResponse
    {
        $this->authorizeRoster($request, $organization, true);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'admission_number' => ['required', 'string', 'max:40', Rule::unique('students')->where('organization_id', $organization)],
            'email' => ['nullable', 'email:rfc', 'max:254', Rule::unique('students')->where('organization_id', $organization)],
            'phone' => ['nullable', 'string', 'max:24', 'regex:/^[+0-9() .-]{7,24}$/'],
            'academic_class_id' => ['nullable', 'integer', Rule::exists('academic_classes', 'id')->where('organization_id', $organization)],
            'academic_section_id' => ['nullable', 'integer', Rule::exists('academic_sections', 'id')->where('organization_id', $organization)],
            'academic_session_id' => ['nullable', 'integer', Rule::exists('academic_sessions', 'id')->where('organization_id', $organization)],
            'organization_branch_id' => ['nullable', 'integer', Rule::exists('organization_branches', 'id')->where('organization_id', $organization)],
            'enrolled_on' => ['nullable', 'date'],
        ]);
        $this->validateSectionPlacement($data, $organization);
        if ($data['academic_section_id'] ?? null) {
            $section = AcademicSection::query()->findOrFail($data['academic_section_id']);
            $data['academic_class_id'] ??= $section->academic_class_id;
            $data['academic_session_id'] ??= $section->academic_session_id;
        }
        $this->resolveStudentBranch($data, $organization);
        $student = Student::query()->create(['organization_id' => $organization] + $data);

        return response()->json(['data' => $student->load(['branch:id,name,code', 'academicClass:id,name,organization_branch_id', 'academicClass.branch:id,name,code', 'academicSection:id,name', 'academicSession:id,name'])], 201);
    }

    public function updateStudent(Request $request, int $organization, int $record): JsonResponse
    {
        $this->authorizeRoster($request, $organization, true);
        $student = Student::query()->where('organization_id', $organization)->findOrFail($record);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'admission_number' => ['sometimes', 'required', 'string', 'max:40', Rule::unique('students')->where('organization_id', $organization)->ignore($student->id)],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:254', Rule::unique('students')->where('organization_id', $organization)->ignore($student->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:24', 'regex:/^[+0-9() .-]{7,24}$/'],
            'academic_class_id' => ['sometimes', 'nullable', 'integer', Rule::exists('academic_classes', 'id')->where('organization_id', $organization)],
            'academic_section_id' => ['sometimes', 'nullable', 'integer', Rule::exists('academic_sections', 'id')->where('organization_id', $organization)],
            'academic_session_id' => ['sometimes', 'nullable', 'integer', Rule::exists('academic_sessions', 'id')->where('organization_id', $organization)],
            'organization_branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('organization_branches', 'id')->where('organization_id', $organization)],
            'enrolled_on' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'graduated', 'withdrawn'])],
        ]);
        $this->validateSectionPlacement(array_merge($student->only(['academic_class_id', 'academic_section_id', 'academic_session_id']), $data), $organization);
        if ($data['academic_section_id'] ?? null) {
            $section = AcademicSection::query()->where('organization_id', $organization)->findOrFail($data['academic_section_id']);
            $data['academic_class_id'] ??= $section->academic_class_id;
            $data['academic_session_id'] ??= $section->academic_session_id;
        }
        $branchData = array_merge($student->only(['academic_class_id']), $data);
        $this->resolveStudentBranch($branchData, $organization, (int) $student->organization_branch_id);
        if (array_key_exists('organization_branch_id', $branchData)) {
            $data['organization_branch_id'] = $branchData['organization_branch_id'];
        }
        $student->update($data);

        return response()->json(['data' => $student->refresh()->load(['branch:id,name,code', 'academicClass:id,name,organization_branch_id', 'academicClass.branch:id,name,code', 'academicSection:id,name', 'academicSession:id,name'])]);
    }

    public function teachers(Request $request, int $organization): JsonResponse
    {
        $this->authorizeRoster($request, $organization);
        $query = Teacher::query()->where('organization_id', $organization)->with('branch:id,name,code');
        $this->filter($request, $query, ['name', 'email', 'employee_number'], $organization);

        return $this->collection($query->orderBy('name')->orderBy('id'));
    }

    public function storeTeacher(Request $request, int $organization): JsonResponse
    {
        $this->authorizeRoster($request, $organization, true);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'employee_number' => ['nullable', 'string', 'max:40', Rule::unique('teachers')->where('organization_id', $organization)],
            'email' => ['nullable', 'email:rfc', 'max:254', Rule::unique('teachers')->where('organization_id', $organization)],
            'phone' => ['nullable', 'string', 'max:24', 'regex:/^[+0-9() .-]{7,24}$/'],
            'specialization' => ['nullable', 'string', 'max:120'],
            'organization_branch_id' => ['nullable', 'integer', Rule::exists('organization_branches', 'id')->where('organization_id', $organization)],
        ]);
        $this->assertActiveBranch($data['organization_branch_id'] ?? null, $organization);
        $teacher = Teacher::query()->create(['organization_id' => $organization] + $data);

        return response()->json(['data' => $teacher->load('branch:id,name,code')], 201);
    }

    public function updateTeacher(Request $request, int $organization, int $record): JsonResponse
    {
        $this->authorizeRoster($request, $organization, true);
        $teacher = Teacher::query()->where('organization_id', $organization)->findOrFail($record);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'employee_number' => ['sometimes', 'nullable', 'string', 'max:40', Rule::unique('teachers')->where('organization_id', $organization)->ignore($teacher->id)],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:254', Rule::unique('teachers')->where('organization_id', $organization)->ignore($teacher->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:24', 'regex:/^[+0-9() .-]{7,24}$/'],
            'specialization' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'on_leave'])],
            'organization_branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('organization_branches', 'id')->where('organization_id', $organization)],
        ]);
        if (array_key_exists('organization_branch_id', $data) && (int) $data['organization_branch_id'] !== (int) $teacher->organization_branch_id) {
            $this->assertActiveBranch($data['organization_branch_id'], $organization);
        }
        $teacher->update($data);

        return response()->json(['data' => $teacher->refresh()->load('branch:id,name,code')]);
    }

    private function authorizeRoster(Request $request, int $organization, bool $write = false): void
    {
        $membership = $this->access->membership($request, $organization, $write);
        abort_unless(in_array($membership->role, [
            OrganizationRole::Admin,
            OrganizationRole::Management,
            OrganizationRole::IndependentEducator,
        ], true), 403);
    }

    private function filter(Request $request, Builder $query, array $searchableFields, int $organization): void
    {
        $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'graduated', 'withdrawn', 'on_leave'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'organization_branch_id' => ['sometimes', 'integer', Rule::exists('organization_branches', 'id')->where('organization_id', $organization)],
        ]);
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function (Builder $builder) use ($searchableFields, $search): void {
                foreach ($searchableFields as $index => $field) {
                    $pattern = '%'.mb_strtolower($search).'%';
                    if ($index === 0) {
                        $builder->whereRaw('LOWER('.$field.') LIKE ?', [$pattern]);
                    } else {
                        $builder->orWhereRaw('LOWER('.$field.') LIKE ?', [$pattern]);
                    }
                }
            });
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($branchId = $request->query('organization_branch_id')) {
            $query->where('organization_branch_id', $branchId);
        }
    }

    private function resolveStudentBranch(array &$data, int $organization, ?int $existingBranchId = null): void
    {
        $branchWasProvided = array_key_exists('organization_branch_id', $data);
        $branchId = $branchWasProvided ? $data['organization_branch_id'] : $existingBranchId;
        if ($branchWasProvided) {
            $this->assertActiveBranch($branchId, $organization, $existingBranchId);
        }

        if (! ($data['academic_class_id'] ?? null)) {
            if (array_key_exists('organization_branch_id', $data)) {
                $data['organization_branch_id'] = $branchId;
            }

            return;
        }

        $class = AcademicClass::query()->where('organization_id', $organization)->findOrFail($data['academic_class_id']);
        $classBranchId = $class->organization_branch_id;
        if ($branchWasProvided && $classBranchId !== null && $branchId !== null && (int) $classBranchId !== (int) $branchId) {
            throw ValidationException::withMessages(['organization_branch_id' => ['The student branch must match the selected class branch.']]);
        }
        if ($classBranchId !== null) {
            if (! OrganizationBranch::query()->where('organization_id', $organization)->where('id', $classBranchId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['academic_class_id' => ['The selected class is assigned to an inactive branch.']]);
            }
            $data['organization_branch_id'] = (int) $classBranchId;
        } elseif (array_key_exists('organization_branch_id', $data)) {
            $data['organization_branch_id'] = $branchId;
        }
    }

    private function assertActiveBranch(mixed $branchId, int $organization, ?int $existingBranchId = null): void
    {
        if ($branchId === null || ($existingBranchId !== null && (int) $branchId === $existingBranchId)) {
            return;
        }
        if (! OrganizationBranch::query()->where('organization_id', $organization)->where('id', $branchId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['organization_branch_id' => ['Choose an active branch in this organization.']]);
        }
    }

    private function validateSectionPlacement(array $data, int $organization): void
    {
        if (! ($data['academic_section_id'] ?? null)) {
            return;
        }

        $section = AcademicSection::query()->where('organization_id', $organization)->findOrFail($data['academic_section_id']);
        if (isset($data['academic_class_id']) && (int) $data['academic_class_id'] !== (int) $section->academic_class_id) {
            throw ValidationException::withMessages(['academic_section_id' => ['The selected section does not belong to the selected class.']]);
        }
        if (isset($data['academic_session_id']) && $section->academic_session_id !== null && (int) $data['academic_session_id'] !== (int) $section->academic_session_id) {
            throw ValidationException::withMessages(['academic_section_id' => ['The selected section does not belong to the selected academic session.']]);
        }
    }

    private function collection(Builder $query): JsonResponse
    {
        $records = $query->paginate(min(max(request()->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => $records->items(),
            'meta' => ['current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'per_page' => $records->perPage(), 'total' => $records->total()],
        ]);
    }
}
