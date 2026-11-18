<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AcademicClass;
use App\Models\AcademicSection;
use App\Models\AcademicSession;
use App\Models\Subject;
use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AcademicMasterController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function sessions(Request $request, int $organization): JsonResponse
    {
        $this->access->membership($request, $organization);

        return $this->collection(AcademicSession::query()->where('organization_id', $organization)
            ->orderByDesc('is_current')->orderBy('starts_on'));
    }

    public function storeSession(Request $request, int $organization): JsonResponse
    {
        $this->access->membership($request, $organization, true);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('academic_sessions')->where('organization_id', $organization)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'is_current' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (($data['is_current'] ?? false) && ! ($data['is_active'] ?? true)) {
            throw ValidationException::withMessages(['is_current' => ['An inactive session cannot be marked as current.']]);
        }

        $record = DB::transaction(function () use ($organization, $data): AcademicSession {
            if ($data['is_current'] ?? false) {
                AcademicSession::query()->where('organization_id', $organization)->update(['is_current' => false]);
            }

            return AcademicSession::query()->create(['organization_id' => $organization] + $data);
        });

        return response()->json(['data' => $record], 201);
    }

    public function updateSession(Request $request, int $organization, int $record): JsonResponse
    {
        $this->access->membership($request, $organization, true);
        $session = AcademicSession::query()->where('organization_id', $organization)->findOrFail($record);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('academic_sessions')->where('organization_id', $organization)->ignore($session->id)],
            'starts_on' => ['sometimes', 'required', 'date'],
            'ends_on' => ['sometimes', 'required', 'date'],
            'is_current' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $start = $data['starts_on'] ?? $session->starts_on->toDateString();
        $end = $data['ends_on'] ?? $session->ends_on->toDateString();
        if ($end < $start) {
            throw ValidationException::withMessages(['ends_on' => ['The end date must be on or after the start date.']]);
        }

        if (($data['is_active'] ?? $session->is_active) === false && ($data['is_current'] ?? false)) {
            throw ValidationException::withMessages(['is_current' => ['An inactive session cannot be marked as current.']]);
        }

        DB::transaction(function () use ($organization, $session, $data): void {
            if ($data['is_current'] ?? false) {
                AcademicSession::query()->where('organization_id', $organization)->where('id', '<>', $session->id)->update(['is_current' => false]);
            }
            if (($data['is_active'] ?? true) === false && $session->is_current && ! array_key_exists('is_current', $data)) {
                $data['is_current'] = false;
            }
            $session->update($data);
        });

        return response()->json(['data' => $session->refresh()]);
    }

    public function classes(Request $request, int $organization): JsonResponse
    {
        $this->access->membership($request, $organization);

        return $this->collection(AcademicClass::query()->where('organization_id', $organization)->orderBy('sort_order')->orderBy('name'));
    }

    public function storeClass(Request $request, int $organization): JsonResponse
    {
        $this->access->membership($request, $organization, true);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('academic_classes')->where('organization_id', $organization)],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('academic_classes')->where('organization_id', $organization)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $record = AcademicClass::query()->create(['organization_id' => $organization] + $data);

        return response()->json(['data' => $record], 201);
    }

    public function updateClass(Request $request, int $organization, int $record): JsonResponse
    {
        $this->access->membership($request, $organization, true);
        $class = AcademicClass::query()->where('organization_id', $organization)->findOrFail($record);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('academic_classes')->where('organization_id', $organization)->ignore($class->id)],
            'code' => ['sometimes', 'nullable', 'string', 'max:32', Rule::unique('academic_classes')->where('organization_id', $organization)->ignore($class->id)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $class->update($data);

        return response()->json(['data' => $class->refresh()]);
    }

    public function sections(Request $request, int $organization, int $class): JsonResponse
    {
        $this->access->membership($request, $organization);
        $academicClass = AcademicClass::query()->where('organization_id', $organization)->findOrFail($class);

        return $this->collection($academicClass->sections()->with('academicSession:id,name')->orderBy('name'));
    }

    public function storeSection(Request $request, int $organization, int $class): JsonResponse
    {
        $this->access->membership($request, $organization, true);
        $academicClass = AcademicClass::query()->where('organization_id', $organization)->findOrFail($class);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'academic_session_id' => ['nullable', 'integer', Rule::exists('academic_sessions', 'id')->where('organization_id', $organization)],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $duplicate = $academicClass->sections()->where('name', $data['name'])
            ->where('academic_session_id', $data['academic_session_id'] ?? null)->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => ['That section already exists for this class and academic session.']]);
        }

        $record = $academicClass->sections()->create(['organization_id' => $organization] + $data);

        return response()->json(['data' => $record->load('academicSession:id,name')], 201);
    }

    public function updateSection(Request $request, int $organization, int $record): JsonResponse
    {
        $this->access->membership($request, $organization, true);
        $section = AcademicSection::query()->where('organization_id', $organization)->findOrFail($record);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'academic_session_id' => ['sometimes', 'nullable', 'integer', Rule::exists('academic_sessions', 'id')->where('organization_id', $organization)],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $section->update($data);

        return response()->json(['data' => $section->refresh()->load('academicSession:id,name')]);
    }

    public function subjects(Request $request, int $organization): JsonResponse
    {
        $this->access->membership($request, $organization);

        return $this->collection(Subject::query()->where('organization_id', $organization)->orderBy('name'));
    }

    public function storeSubject(Request $request, int $organization): JsonResponse
    {
        $this->access->membership($request, $organization, true);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('subjects')->where('organization_id', $organization)],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('subjects')->where('organization_id', $organization)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $record = Subject::query()->create(['organization_id' => $organization] + $data);

        return response()->json(['data' => $record], 201);
    }

    public function updateSubject(Request $request, int $organization, int $record): JsonResponse
    {
        $this->access->membership($request, $organization, true);
        $subject = Subject::query()->where('organization_id', $organization)->findOrFail($record);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('subjects')->where('organization_id', $organization)->ignore($subject->id)],
            'code' => ['sometimes', 'nullable', 'string', 'max:32', Rule::unique('subjects')->where('organization_id', $organization)->ignore($subject->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $subject->update($data);

        return response()->json(['data' => $subject->refresh()]);
    }

    private function collection($query): JsonResponse
    {
        $perPage = min(max(request()->integer('per_page', 50), 1), 100);
        $records = $query->paginate($perPage);

        return response()->json([
            'data' => $records->items(),
            'meta' => ['current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'total' => $records->total()],
        ]);
    }
}
