<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AcademicBatch;
use App\Models\AcademicSection;
use App\Services\OrganizationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AcademicBatchController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $this->access->membership($request, $organization);
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'academic_session_id' => ['sometimes', 'integer', Rule::exists('academic_sessions', 'id')->where('organization_id', $organization)],
            'academic_class_id' => ['sometimes', 'integer', Rule::exists('academic_classes', 'id')->where('organization_id', $organization)],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = AcademicBatch::query()->where('organization_id', $organization)
            ->with(['academicSession:id,name', 'academicClass:id,name', 'academicSection:id,name']);
        if (isset($filters['search'])) {
            $search = '%'.mb_strtolower(trim($filters['search'])).'%';
            $query->where(fn (Builder $builder) => $builder
                ->whereRaw('LOWER(name) LIKE ?', [$search])
                ->orWhereRaw('LOWER(code) LIKE ?', [$search]));
        }
        $query->when(isset($filters['academic_session_id']), fn (Builder $builder) => $builder->where('academic_session_id', $filters['academic_session_id']))
            ->when(isset($filters['academic_class_id']), fn (Builder $builder) => $builder->where('academic_class_id', $filters['academic_class_id']))
            ->when(array_key_exists('is_active', $filters), fn (Builder $builder) => $builder->where('is_active', $filters['is_active']))
            ->orderBy('academic_session_id')->orderBy('academic_class_id')->orderBy('name');
        $records = $query->paginate(min(max((int) ($filters['per_page'] ?? 50), 1), 100));

        return response()->json(['data' => $records->items(), 'meta' => [
            'current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'total' => $records->total(),
        ]]);
    }

    public function store(Request $request, int $organization): JsonResponse
    {
        $this->access->membership($request, $organization, true);
        $data = $request->validate([
            'academic_session_id' => ['required', 'integer', Rule::exists('academic_sessions', 'id')->where('organization_id', $organization)],
            'academic_class_id' => ['required', 'integer', Rule::exists('academic_classes', 'id')->where('organization_id', $organization)],
            'academic_section_id' => ['nullable', 'integer', Rule::exists('academic_sections', 'id')->where('organization_id', $organization)],
            'name' => ['required', 'string', 'min:1', 'max:120', Rule::unique('academic_batches', 'name')->where('organization_id', $organization)->where('academic_session_id', $request->input('academic_session_id'))->where('academic_class_id', $request->input('academic_class_id'))],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('academic_batches', 'code')->where('organization_id', $organization)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $this->validatePlacement($data, $organization);
        $batch = AcademicBatch::query()->create(['organization_id' => $organization] + $data);

        return response()->json(['data' => $batch->load(['academicSession:id,name', 'academicClass:id,name', 'academicSection:id,name'])], 201);
    }

    public function update(Request $request, int $organization, int $record): JsonResponse
    {
        $this->access->membership($request, $organization, true);
        $batch = AcademicBatch::query()->where('organization_id', $organization)->findOrFail($record);
        $data = $request->validate([
            'academic_session_id' => ['sometimes', 'required', 'integer', Rule::exists('academic_sessions', 'id')->where('organization_id', $organization)],
            'academic_class_id' => ['sometimes', 'required', 'integer', Rule::exists('academic_classes', 'id')->where('organization_id', $organization)],
            'academic_section_id' => ['sometimes', 'nullable', 'integer', Rule::exists('academic_sections', 'id')->where('organization_id', $organization)],
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:120', Rule::unique('academic_batches', 'name')
                ->where('organization_id', $organization)
                ->where('academic_session_id', $request->input('academic_session_id', $batch->academic_session_id))
                ->where('academic_class_id', $request->input('academic_class_id', $batch->academic_class_id))->ignore($batch->id)],
            'code' => ['sometimes', 'nullable', 'string', 'max:32', Rule::unique('academic_batches', 'code')->where('organization_id', $organization)->ignore($batch->id)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $this->validatePlacement(array_merge($batch->only(['academic_session_id', 'academic_class_id', 'academic_section_id']), $data), $organization);
        $batch->update($data);

        return response()->json(['data' => $batch->refresh()->load(['academicSession:id,name', 'academicClass:id,name', 'academicSection:id,name'])]);
    }

    private function validatePlacement(array $data, int $organization): void
    {
        if (empty($data['academic_section_id'])) {
            return;
        }
        $section = AcademicSection::query()->where('organization_id', $organization)->findOrFail($data['academic_section_id']);
        if ((int) $section->academic_class_id !== (int) $data['academic_class_id']
            || ($section->academic_session_id !== null && (int) $section->academic_session_id !== (int) $data['academic_session_id'])) {
            throw ValidationException::withMessages(['academic_section_id' => ['The section must belong to the selected class and academic session.']]);
        }
    }
}
