<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\AcademicSection;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Meeting;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use App\Services\OrganizationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MeetingController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        $query = Meeting::query()->where('organization_id', $organization)
            ->with(['section.academicClass:id,name', 'course:id,title', 'creator:id,name']);

        if ($this->isManager($membership->role)) {
            $this->access->membership($request, $organization, true);
        } elseif ($membership->role === OrganizationRole::Teacher) {
            $teacher = $this->teacherProfile($request, $organization);
            $sectionIds = TeachingAssignment::query()->where('organization_id', $organization)
                ->where('teacher_id', $teacher->id)->pluck('academic_section_id');
            $courseIds = Course::query()->where('organization_id', $organization)->where('teacher_id', $teacher->id)->pluck('id');
            $query->where('kind', 'class')->where(fn (Builder $meetings) => $meetings
                ->whereIn('academic_section_id', $sectionIds)->orWhereIn('course_id', $courseIds)->orWhere('created_by', $request->user()->id));
        } elseif ($membership->role === OrganizationRole::Student) {
            $student = $this->studentProfile($request, $organization);
            $courseIds = CourseEnrollment::query()->where('organization_id', $organization)->where('student_id', $student->id)
                ->where('status', 'active')->pluck('course_id');
            $query->where('kind', 'class')->where(fn (Builder $meetings) => $meetings
                ->where(fn (Builder $sectionMeetings) => $sectionMeetings->where('academic_section_id', $student->academic_section_id)
                    ->where('academic_session_id', $student->academic_session_id))
                ->orWhereIn('course_id', $courseIds));
        } elseif ($membership->role === OrganizationRole::Parent) {
            $children = StudentGuardian::query()->where('organization_id', $organization)
                ->where('guardian_user_id', $request->user()->id)
                ->whereHas('student', fn ($students) => $students->where('status', 'active'))
                ->with('student:id,academic_section_id,academic_session_id')->get()->pluck('student')->filter();
            $courseIds = CourseEnrollment::query()->where('organization_id', $organization)
                ->whereIn('student_id', $children->pluck('id'))->where('status', 'active')->pluck('course_id');
            $query->where('kind', 'class')->where(fn (Builder $meetings) => $meetings
                ->where(function (Builder $sectionMeetings) use ($children): void {
                    foreach ($children as $child) {
                        if ($child->academic_section_id && $child->academic_session_id) {
                            $sectionMeetings->orWhere(fn (Builder $scope) => $scope
                                ->where('academic_section_id', $child->academic_section_id)
                                ->where('academic_session_id', $child->academic_session_id));
                        }
                    }
                })->orWhereIn('course_id', $courseIds));
        } else {
            abort(403);
        }

        $meetings = $query->orderBy('starts_at')->orderBy('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => $meetings->getCollection()->map(fn (Meeting $meeting): array => $this->meetingData($meeting))->values(),
            'meta' => ['current_page' => $meetings->currentPage(), 'last_page' => $meetings->lastPage(), 'per_page' => $meetings->perPage(), 'total' => $meetings->total()],
        ]);
    }

    public function store(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        $teacher = null;
        if ($membership->role === OrganizationRole::Teacher) {
            $teacher = $this->teacherProfile($request, $organization);
        } else {
            $this->access->membership($request, $organization, true);
            abort_unless($this->isManager($membership->role), 403);
        }

        $data = $request->validate([
            'kind' => ['required', Rule::in(['class', 'management'])],
            'academic_section_id' => [Rule::requiredIf(fn () => $request->input('kind') === 'class' && ! $request->filled('course_id')), 'nullable', 'integer', Rule::exists('academic_sections', 'id')->where('organization_id', $organization)->where('is_active', true)],
            'academic_session_id' => [Rule::requiredIf(fn () => $request->filled('academic_section_id')), 'nullable', 'integer', Rule::exists('academic_sessions', 'id')->where('organization_id', $organization)],
            'course_id' => [Rule::requiredIf(fn () => $request->input('kind') === 'class' && ! $request->filled('academic_section_id')), 'nullable', 'integer', Rule::exists('courses', 'id')->where('organization_id', $organization)->where('status', 'published')],
            'title' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date', 'after_or_equal:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'meeting_url' => ['required', 'url:https', 'max:2048'],
        ]);
        if ($data['kind'] === 'management' && (! empty($data['academic_section_id']) || ! empty($data['course_id']) || ! empty($data['academic_session_id']))) {
            throw ValidationException::withMessages(['kind' => ['Management meetings cannot be attached to a class or course.']]);
        }
        if ($data['kind'] === 'class' && ! empty($data['academic_section_id']) && ! empty($data['course_id'])) {
            throw ValidationException::withMessages(['course_id' => ['Choose either a class section or a course for this meeting.']]);
        }
        if (! empty($data['academic_section_id'])) {
            $section = AcademicSection::query()->where('organization_id', $organization)->findOrFail($data['academic_section_id']);
            if ((int) $section->academic_session_id !== (int) $data['academic_session_id']) {
                throw ValidationException::withMessages(['academic_session_id' => ['Select the session assigned to this section.']]);
            }
            if ($teacher && ! TeachingAssignment::query()->where('organization_id', $organization)->where('teacher_id', $teacher->id)
                ->where('academic_section_id', $section->id)->where('academic_session_id', $data['academic_session_id'])->exists()) {
                abort(404);
            }
        }
        if (! empty($data['course_id']) && $teacher) {
            abort_unless(Course::query()->where('organization_id', $organization)->where('id', $data['course_id'])
                ->where('teacher_id', $teacher->id)->exists(), 404);
        }
        if ($data['kind'] === 'management' && $teacher) {
            abort(403);
        }

        $meeting = DB::transaction(function () use ($data, $organization, $request): Meeting {
            if ($data['kind'] === 'class') {
                if (! empty($data['academic_section_id'])) {
                    AcademicSection::query()->where('organization_id', $organization)->lockForUpdate()->findOrFail($data['academic_section_id']);
                    $target = ['academic_section_id' => $data['academic_section_id']];
                } else {
                    Course::query()->where('organization_id', $organization)->where('status', 'published')
                        ->lockForUpdate()->findOrFail($data['course_id']);
                    $target = ['course_id' => $data['course_id']];
                }
                $startsAt = Carbon::parse($data['starts_at']);
                $endsAt = Carbon::parse($data['ends_at']);
                $conflict = Meeting::query()->where('organization_id', $organization)->where('kind', 'class')
                    ->where('status', 'scheduled')->where($target)
                    ->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt)->exists();
                if ($conflict) {
                    throw ValidationException::withMessages(['starts_at' => ['Another class meeting is already scheduled for this group at that time.']]);
                }
            }

            return Meeting::query()->create([
                ...$data,
                'organization_id' => $organization,
                'created_by' => $request->user()->id,
                'status' => 'scheduled',
            ]);
        });

        return response()->json(['data' => $this->meetingData($meeting->load(['section.academicClass:id,name', 'course:id,title', 'creator:id,name']))], 201);
    }

    public function update(Request $request, int $organization, int $meeting): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        $record = Meeting::query()->where('organization_id', $organization)->findOrFail($meeting);
        if ($membership->role === OrganizationRole::Teacher) {
            abort_unless($record->created_by === $request->user()->id && $record->kind === 'class', 404);
        } else {
            $this->access->membership($request, $organization, true);
            abort_unless($this->isManager($membership->role), 403);
        }
        $data = $request->validate(['status' => ['required', Rule::in(['cancelled'])]]);
        $record->update($data);

        return response()->json(['data' => $this->meetingData($record->refresh()->load(['section.academicClass:id,name', 'course:id,title', 'creator:id,name']))]);
    }

    private function meetingData(Meeting $meeting): array
    {
        return [
            'id' => $meeting->id,
            'kind' => $meeting->kind,
            'title' => $meeting->title,
            'description' => $meeting->description,
            'starts_at' => $meeting->starts_at?->toISOString(),
            'ends_at' => $meeting->ends_at?->toISOString(),
            'meeting_url' => $meeting->meeting_url,
            'status' => $meeting->status,
            'section' => $meeting->section ? [
                'id' => $meeting->section->id,
                'name' => $meeting->section->name,
                'class' => $meeting->section->academicClass?->name,
            ] : null,
            'course' => $meeting->course?->only(['id', 'title']),
            'creator' => $meeting->creator?->only(['id', 'name']),
        ];
    }

    private function isManager(OrganizationRole $role): bool
    {
        return in_array($role, [OrganizationRole::Admin, OrganizationRole::Management, OrganizationRole::IndependentEducator], true);
    }

    private function teacherProfile(Request $request, int $organization): Teacher
    {
        return Teacher::query()->where('organization_id', $organization)->where('user_id', $request->user()->id)
            ->where('status', 'active')->firstOrFail();
    }

    private function studentProfile(Request $request, int $organization): Student
    {
        return Student::query()->where('organization_id', $organization)->where('user_id', $request->user()->id)
            ->where('status', 'active')->firstOrFail();
    }
}
