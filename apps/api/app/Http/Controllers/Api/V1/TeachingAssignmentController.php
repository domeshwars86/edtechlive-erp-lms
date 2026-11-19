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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeachingAssignmentController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        $filters = $request->validate([
            'academic_session_id' => ['sometimes', 'integer', Rule::exists('academic_sessions', 'id')->where('organization_id', $organization)],
            'teacher_id' => ['sometimes', 'integer', Rule::exists('teachers', 'id')->where('organization_id', $organization)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = TeachingAssignment::query()->where('organization_id', $organization)
            ->with(['academicSession:id,name', 'academicSection:id,name,academic_class_id,academic_session_id', 'academicSection.academicClass:id,name,organization_branch_id', 'academicSection.academicClass.branch:id,name,code', 'subject:id,name,code', 'teacher:id,name,employee_number,organization_branch_id', 'teacher.branch:id,name,code']);
        if ($membership->role === OrganizationRole::Teacher) {
            $teacher = Teacher::query()->where('organization_id', $organization)->where('user_id', $request->user()->id)->where('status', 'active')->first();
            abort_unless($teacher !== null, 403);
            abort_if(isset($filters['teacher_id']) && (int) $filters['teacher_id'] !== (int) $teacher->id, 403);
            $filters['teacher_id'] = $teacher->id;
        } else {
            abort_unless(in_array($membership->role, [OrganizationRole::Admin, OrganizationRole::Management, OrganizationRole::IndependentEducator], true), 403);
        }
        if (isset($filters['academic_session_id'])) {
            $query->where('academic_session_id', $filters['academic_session_id']);
        }
        if (isset($filters['teacher_id'])) {
            $query->where('teacher_id', $filters['teacher_id']);
        }
        $assignments = $query->orderBy('academic_session_id')->orderBy('academic_section_id')->paginate(min(max($request->integer('per_page', 50), 1), 100));

        return response()->json([
            'data' => $assignments->items(),
            'meta' => ['current_page' => $assignments->currentPage(), 'last_page' => $assignments->lastPage(), 'per_page' => $assignments->perPage(), 'total' => $assignments->total()],
        ]);
    }

    public function store(Request $request, int $organization): JsonResponse
    {
        $this->authorize($request, $organization, true);
        $data = $request->validate([
            'academic_session_id' => ['required', 'integer', Rule::exists('academic_sessions', 'id')->where('organization_id', $organization)],
            'academic_section_id' => ['required', 'integer', Rule::exists('academic_sections', 'id')->where('organization_id', $organization)],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->where('organization_id', $organization)],
            'teacher_id' => ['required', 'integer', Rule::exists('teachers', 'id')->where('organization_id', $organization)],
        ]);
        $session = AcademicSession::query()->where('organization_id', $organization)->where('is_active', true)->findOrFail($data['academic_session_id']);
        $section = AcademicSection::query()->where('organization_id', $organization)->where('is_active', true)->findOrFail($data['academic_section_id']);
        $subject = Subject::query()->where('organization_id', $organization)->where('is_active', true)->findOrFail($data['subject_id']);
        $teacher = Teacher::query()->where('organization_id', $organization)->where('status', 'active')->findOrFail($data['teacher_id']);

        if ($section->academic_session_id === null || (int) $section->academic_session_id !== (int) $session->id) {
            throw ValidationException::withMessages(['academic_section_id' => ['The selected section must belong to the selected academic session.']]);
        }
        $class = $section->academicClass;
        if ($class->organization_branch_id !== null) {
            if (! DB::table('organization_branches')->where('organization_id', $organization)->where('id', $class->organization_branch_id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['academic_section_id' => ['The selected class branch is inactive.']]);
            }
            if ($teacher->organization_branch_id !== null && (int) $teacher->organization_branch_id !== (int) $class->organization_branch_id) {
                throw ValidationException::withMessages(['teacher_id' => ['The teacher must belong to the selected class branch or be assigned organization-wide.']]);
            }
        }
        $assignment = TeachingAssignment::query()->firstOrCreate(
            ['academic_session_id' => $session->id, 'academic_section_id' => $section->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id],
            ['organization_id' => $organization],
        );
        $assignment->load(['academicSession:id,name', 'academicSection:id,name,academic_class_id,academic_session_id', 'academicSection.academicClass:id,name,organization_branch_id', 'academicSection.academicClass.branch:id,name,code', 'subject:id,name,code', 'teacher:id,name,employee_number,organization_branch_id', 'teacher.branch:id,name,code']);

        return response()->json(['data' => $assignment], $assignment->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, int $organization, int $record): JsonResponse
    {
        $this->authorize($request, $organization, true);
        $assignment = TeachingAssignment::query()->where('organization_id', $organization)->findOrFail($record);
        DB::transaction(function () use ($assignment): void {
            TimetableEntry::query()->where('organization_id', $assignment->organization_id)
                ->where('academic_session_id', $assignment->academic_session_id)
                ->where('academic_section_id', $assignment->academic_section_id)
                ->where('subject_id', $assignment->subject_id)
                ->where('teacher_id', $assignment->teacher_id)
                ->update(['teacher_id' => null]);
            $assignment->delete();
        });

        return response()->json(['message' => 'Teaching assignment removed.']);
    }

    private function authorize(Request $request, int $organization, bool $write = false): void
    {
        $membership = $this->access->membership($request, $organization, $write);
        abort_unless(in_array($membership->role, [OrganizationRole::Admin, OrganizationRole::Management, OrganizationRole::IndependentEducator], true), 403);
    }
}
