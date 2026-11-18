<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\AcademicSection;
use App\Models\AcademicSession;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use App\Models\TimetableEntry;
use App\Services\OrganizationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TimetableController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $this->authorizeTimetable($request, $organization);
        $filters = $request->validate([
            'academic_session_id' => ['sometimes', 'integer', Rule::exists('academic_sessions', 'id')->where('organization_id', $organization)],
            'section_id' => ['sometimes', 'integer', Rule::exists('academic_sections', 'id')->where('organization_id', $organization)],
            'weekday' => ['sometimes', 'integer', 'between:0,6'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = TimetableEntry::query()->where('organization_id', $organization)
            ->with(['academicSession:id,name', 'academicSection:id,name,academic_class_id', 'academicSection.academicClass:id,name', 'subject:id,name', 'teacher:id,name']);
        if (isset($filters['academic_session_id'])) {
            $query->where('academic_session_id', $filters['academic_session_id']);
        }
        if (isset($filters['section_id'])) {
            $query->where('academic_section_id', $filters['section_id']);
        }
        if (isset($filters['weekday'])) {
            $query->where('weekday', $filters['weekday']);
        }

        $records = $query->orderBy('weekday')->orderBy('starts_at')->paginate(min(max($request->integer('per_page', 50), 1), 100));

        return response()->json([
            'data' => $records->items(),
            'meta' => ['current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'per_page' => $records->perPage(), 'total' => $records->total()],
        ]);
    }

    public function store(Request $request, int $organization): JsonResponse
    {
        $this->authorizeTimetable($request, $organization, true);
        $data = $request->validate($this->rules($organization));
        $this->validatePlacement($data, $organization);
        $this->validateConflict($data, $organization);

        $entry = TimetableEntry::query()->create(['organization_id' => $organization] + $data);

        return response()->json(['data' => $entry->load($this->relations())], 201);
    }

    public function update(Request $request, int $organization, int $record): JsonResponse
    {
        $this->authorizeTimetable($request, $organization, true);
        $entry = TimetableEntry::query()->where('organization_id', $organization)->findOrFail($record);
        $data = $request->validate($this->rules($organization, true));
        $candidate = array_merge($entry->only([
            'academic_session_id', 'academic_section_id', 'subject_id', 'teacher_id', 'weekday',
            'starts_at', 'ends_at', 'room', 'meeting_url', 'is_active',
        ]), $data);
        $this->validatePlacement($candidate, $organization);
        $this->validateConflict($candidate, $organization, $entry->id);
        $entry->update($data);

        return response()->json(['data' => $entry->refresh()->load($this->relations())]);
    }

    private function rules(int $organization, bool $partial = false): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            'academic_session_id' => [...$required, 'integer', Rule::exists('academic_sessions', 'id')->where('organization_id', $organization)],
            'academic_section_id' => [...$required, 'integer', Rule::exists('academic_sections', 'id')->where('organization_id', $organization)],
            'subject_id' => [...$required, 'integer', Rule::exists('subjects', 'id')->where('organization_id', $organization)],
            'teacher_id' => ['sometimes', 'nullable', 'integer', Rule::exists('teachers', 'id')->where('organization_id', $organization)],
            'weekday' => [...$required, 'integer', 'between:0,6'],
            'starts_at' => [...$required, 'date_format:H:i'],
            'ends_at' => [...$required, 'date_format:H:i'],
            'room' => ['sometimes', 'nullable', 'string', 'max:120'],
            'meeting_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function validatePlacement(array $data, int $organization): void
    {
        $session = AcademicSession::query()->where('organization_id', $organization)->findOrFail($data['academic_session_id']);
        if (! $session->is_active) {
            throw ValidationException::withMessages(['academic_session_id' => ['Select an active academic session.']]);
        }

        $section = AcademicSection::query()->where('organization_id', $organization)->findOrFail($data['academic_section_id']);
        if ($section->academic_session_id === null || (int) $section->academic_session_id !== (int) $session->id) {
            throw ValidationException::withMessages(['academic_section_id' => ['The section belongs to a different academic session.']]);
        }
        if (! $section->is_active) {
            throw ValidationException::withMessages(['academic_section_id' => ['Select an active class section.']]);
        }

        $subject = Subject::query()->where('organization_id', $organization)->findOrFail($data['subject_id']);
        if (! $subject->is_active) {
            throw ValidationException::withMessages(['subject_id' => ['Select an active subject.']]);
        }

        if (! empty($data['teacher_id'])) {
            $teacher = Teacher::query()->where('organization_id', $organization)->findOrFail($data['teacher_id']);
            if ($teacher->status !== 'active') {
                throw ValidationException::withMessages(['teacher_id' => ['Select an active teacher.']]);
            }
            if (! TeachingAssignment::query()
                ->where('organization_id', $organization)
                ->where('academic_session_id', $session->id)
                ->where('academic_section_id', $section->id)
                ->where('subject_id', $data['subject_id'])
                ->where('teacher_id', $teacher->id)
                ->exists()) {
                throw ValidationException::withMessages(['teacher_id' => ['Assign this teacher to the section and subject before scheduling them.']]);
            }
        }

        if ($data['ends_at'] <= $data['starts_at']) {
            throw ValidationException::withMessages(['ends_at' => ['The end time must be later than the start time.']]);
        }
        if (isset($data['meeting_url']) && ! str_starts_with(strtolower($data['meeting_url']), 'https://')) {
            throw ValidationException::withMessages(['meeting_url' => ['Use a secure HTTPS meeting link.']]);
        }
    }

    private function validateConflict(array $data, int $organization, ?int $ignoreId = null): void
    {
        if (! ($data['is_active'] ?? true)) {
            return;
        }

        $conflict = TimetableEntry::query()
            ->where('organization_id', $organization)
            ->where('is_active', true)
            ->where('weekday', $data['weekday'])
            ->where('starts_at', '<', $data['ends_at'])
            ->where('ends_at', '>', $data['starts_at'])
            ->where('academic_session_id', $data['academic_session_id'])
            ->when($ignoreId !== null, fn (Builder $query) => $query->where('id', '<>', $ignoreId))
            ->where(function (Builder $query) use ($data): void {
                $query->where('academic_section_id', $data['academic_section_id']);
                if (! empty($data['teacher_id'])) {
                    $query->orWhere('teacher_id', $data['teacher_id']);
                }
                if (! empty($data['room'])) {
                    $query->orWhere('room', $data['room']);
                }
            })
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages(['starts_at' => ['This time overlaps another active lesson for the same section, teacher, or room.']]);
        }
    }

    private function authorizeTimetable(Request $request, int $organization, bool $write = false): void
    {
        $membership = $this->access->membership($request, $organization, $write);
        abort_unless(in_array($membership->role, [
            OrganizationRole::Admin,
            OrganizationRole::Management,
            OrganizationRole::IndependentEducator,
        ], true), 403);
    }

    private function relations(): array
    {
        return ['academicSession:id,name', 'academicSection:id,name,academic_class_id', 'academicSection.academicClass:id,name', 'subject:id,name', 'teacher:id,name'];
    }
}
