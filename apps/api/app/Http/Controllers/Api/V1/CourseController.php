<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseLesson;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\OrganizationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CourseController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        $query = Course::query()->where('organization_id', $organization)->with(['subject:id,name', 'teacher:id,name']);
        if ($membership->role === OrganizationRole::Teacher) {
            $teacher = $this->teacherProfile($request, $organization);
            $query->where('teacher_id', $teacher->id);
        } elseif ($membership->role === OrganizationRole::Student) {
            $student = $this->studentProfile($request, $organization);
            $query->where('status', 'published')->whereHas('enrollments', fn (Builder $enrollments) => $enrollments
                ->where('student_id', $student->id)->where('status', 'active'));
        } else {
            $this->assertManager($membership->role);
        }
        $courses = $query->orderByDesc('updated_at')->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => $courses->getCollection()->map(fn (Course $course): array => $this->courseData($course))->values(),
            'meta' => ['current_page' => $courses->currentPage(), 'last_page' => $courses->lastPage(), 'per_page' => $courses->perPage(), 'total' => $courses->total()],
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
        }
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:160'],
            'level' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:5000'],
            'subject_id' => ['nullable', 'integer', Rule::exists('subjects', 'id')->where('organization_id', $organization)],
            'teacher_id' => [Rule::prohibitedIf($teacher !== null), 'nullable', 'integer', Rule::exists('teachers', 'id')->where('organization_id', $organization)->where('status', 'active')],
        ]);
        $course = Course::query()->create([
            ...$data,
            'organization_id' => $organization,
            'teacher_id' => $teacher?->id ?? ($data['teacher_id'] ?? null),
            'created_by' => $request->user()->id,
            'status' => 'draft',
        ]);

        return response()->json(['data' => $this->courseData($course->load(['subject:id,name', 'teacher:id,name']))], 201);
    }

    public function update(Request $request, int $organization, int $record): JsonResponse
    {
        $course = Course::query()->where('organization_id', $organization)->findOrFail($record);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $course);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'level' => ['sometimes', 'nullable', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'subject_id' => ['sometimes', 'nullable', 'integer', Rule::exists('subjects', 'id')->where('organization_id', $organization)],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'is_public_listing' => ['sometimes', 'boolean'],
            'teacher_id' => [Rule::prohibitedIf($membership->role === OrganizationRole::Teacher), 'sometimes', 'nullable', 'integer', Rule::exists('teachers', 'id')->where('organization_id', $organization)->where('status', 'active')],
        ]);
        if (array_key_exists('is_public_listing', $data) && $course->status !== 'published') {
            throw ValidationException::withMessages(['is_public_listing' => ['Only published courses can change their public listing setting.']]);
        }
        if (($data['is_public_listing'] ?? false) && ($data['status'] ?? $course->status) !== 'published') {
            throw ValidationException::withMessages(['is_public_listing' => ['A public course must remain published.']]);
        }
        if (($data['status'] ?? null) === 'published' && $course->lessons()->where('status', 'published')->doesntExist()) {
            throw ValidationException::withMessages(['status' => ['Publish at least one lesson before publishing a course.']]);
        }
        $data['published_at'] = ($data['status'] ?? null) === 'published' ? ($course->published_at ?? now()) : $course->published_at;
        if (isset($data['status']) && $data['status'] !== 'published') {
            $data['is_public_listing'] = false;
        }
        unset($data['teacher_id']);
        $course->update($data);

        if (array_key_exists('teacher_id', $request->all()) && $membership->role !== OrganizationRole::Teacher) {
            $request->validate(['teacher_id' => ['nullable', 'integer', Rule::exists('teachers', 'id')->where('organization_id', $organization)->where('status', 'active')]]);
            $course->update(['teacher_id' => $request->input('teacher_id')]);
        }

        return response()->json(['data' => $this->courseData($course->refresh()->load(['subject:id,name', 'teacher:id,name']))]);
    }

    public function lessons(Request $request, int $organization, int $course): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        $record = Course::query()->where('organization_id', $organization)->findOrFail($course);
        $this->assertCourseVisible($request, $membership->role, $organization, $record);
        $query = $record->lessons()->with('module');
        if ($membership->role === OrganizationRole::Student) {
            $query->where('status', 'published');
        }
        $lessons = $query->get();

        return response()->json(['data' => $lessons->map(fn (CourseLesson $lesson): array => $this->lessonData($lesson))->values()]);
    }

    public function storeLesson(Request $request, int $organization, int $course): JsonResponse
    {
        $record = Course::query()->where('organization_id', $organization)->findOrFail($course);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $record);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:160'],
            'module_id' => ['nullable', 'integer', Rule::exists('course_modules', 'id')->where('organization_id', $organization)->where('course_id', $course)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'content' => ['nullable', 'string', 'max:50000'],
            'external_url' => ['nullable', 'url:https', 'max:2048'],
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
        ]);
        if (($data['status'] ?? 'draft') === 'published' && empty($data['content']) && empty($data['external_url'])) {
            throw ValidationException::withMessages(['content' => ['A published lesson needs written content or an external resource link.']]);
        }
        $lesson = $record->lessons()->create([...$data, 'organization_id' => $organization]);

        return response()->json(['data' => $this->lessonData($lesson)], 201);
    }

    public function enroll(Request $request, int $organization, int $course): JsonResponse
    {
        $this->access->membership($request, $organization, true);
        $record = Course::query()->where('organization_id', $organization)->findOrFail($course);
        abort_unless($record->status === 'published', 409, 'Only published courses can accept enrollments.');
        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:200'],
            'student_ids.*' => ['required', 'integer', 'distinct', Rule::exists('students', 'id')->where('organization_id', $organization)->where('status', 'active')],
        ]);
        DB::transaction(function () use ($data, $organization, $record, $request): void {
            foreach ($data['student_ids'] as $studentId) {
                CourseEnrollment::query()->updateOrCreate(
                    ['course_id' => $record->id, 'student_id' => $studentId],
                    ['organization_id' => $organization, 'enrolled_by' => $request->user()->id, 'status' => 'active', 'completed_at' => null],
                );
            }
        });

        return response()->json(['message' => 'Students enrolled.', 'enrolled' => count($data['student_ids'])]);
    }

    private function assertCourseVisible(Request $request, OrganizationRole $role, int $organization, Course $course): void
    {
        if ($role === OrganizationRole::Teacher) {
            abort_unless($course->teacher_id === $this->teacherProfile($request, $organization)->id, 404);

            return;
        }
        if ($role === OrganizationRole::Student) {
            $student = $this->studentProfile($request, $organization);
            abort_unless($course->status === 'published' && $course->enrollments()->where('student_id', $student->id)->where('status', 'active')->exists(), 404);

            return;
        }
        $this->assertManager($role);
    }

    private function assertCourseEditor(Request $request, OrganizationRole $role, int $organization, Course $course): void
    {
        if ($role === OrganizationRole::Teacher) {
            abort_unless($course->teacher_id === $this->teacherProfile($request, $organization)->id, 404);

            return;
        }
        $this->access->membership($request, $organization, true);
    }

    private function assertManager(OrganizationRole $role): void
    {
        abort_unless(in_array($role, [OrganizationRole::Admin, OrganizationRole::Management, OrganizationRole::IndependentEducator], true), 403);
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

    private function courseData(Course $course): array
    {
        return [
            'id' => $course->id,
            'title' => $course->title,
            'slug' => $course->slug,
            'level' => $course->level,
            'description' => $course->description,
            'status' => $course->status,
            'is_public_listing' => $course->is_public_listing,
            'subject' => $course->subject?->only(['id', 'name']),
            'teacher' => $course->teacher?->only(['id', 'name']),
            'published_at' => $course->published_at?->toISOString(),
        ];
    }

    private function lessonData(CourseLesson $lesson): array
    {
        return [
            'id' => $lesson->id,
            'title' => $lesson->title,
            'sort_order' => $lesson->sort_order,
            'content' => $lesson->content,
            'external_url' => $lesson->external_url,
            'status' => $lesson->status,
            'module' => $lesson->module ? [
                'id' => $lesson->module->id,
                'title' => $lesson->module->title,
                'description' => $lesson->module->description,
                'sort_order' => $lesson->module->sort_order,
            ] : null,
        ];
    }
}
