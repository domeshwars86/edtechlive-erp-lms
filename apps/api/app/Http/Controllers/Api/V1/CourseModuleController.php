<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CourseModuleController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization, int $course): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        $record = Course::query()->where('organization_id', $organization)->findOrFail($course);
        $this->assertVisible($request, $membership->role, $organization, $record);
        $query = $record->modules();
        if ($membership->role === OrganizationRole::Student) {
            $query->whereHas('lessons', fn ($lessons) => $lessons->where('status', 'published'));
        }

        return response()->json(['data' => $query->get()->map(fn (CourseModule $module): array => $this->moduleData($module))->values()]);
    }

    public function store(Request $request, int $organization, int $course): JsonResponse
    {
        $record = Course::query()->where('organization_id', $organization)->findOrFail($course);
        $membership = $this->access->membership($request, $organization);
        $this->assertEditor($request, $membership->role, $organization, $record);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:160', Rule::unique('course_modules', 'title')->where('course_id', $course)],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ]);
        $data['sort_order'] ??= ((int) $record->modules()->max('sort_order')) + 1;
        $module = $record->modules()->create([...$data, 'organization_id' => $organization]);

        return response()->json(['data' => $this->moduleData($module)], 201);
    }

    public function update(Request $request, int $organization, int $course, int $module): JsonResponse
    {
        $record = Course::query()->where('organization_id', $organization)->findOrFail($course);
        $membership = $this->access->membership($request, $organization);
        $this->assertEditor($request, $membership->role, $organization, $record);
        $moduleRecord = $record->modules()->findOrFail($module);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:2', 'max:160', Rule::unique('course_modules', 'title')->where('course_id', $course)->ignore($moduleRecord->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ]);
        $moduleRecord->update($data);

        return response()->json(['data' => $this->moduleData($moduleRecord->refresh())]);
    }

    private function assertVisible(Request $request, OrganizationRole $role, int $organization, Course $course): void
    {
        if ($role === OrganizationRole::Student) {
            $student = Student::query()->where('organization_id', $organization)
                ->where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail();
            abort_unless($course->status === 'published' && $course->enrollments()
                ->where('student_id', $student->id)->where('status', 'active')->exists(), 404);

            return;
        }

        $this->assertEditor($request, $role, $organization, $course);
    }

    private function assertEditor(Request $request, OrganizationRole $role, int $organization, Course $course): void
    {
        if ($role === OrganizationRole::Teacher) {
            $teacher = Teacher::query()->where('organization_id', $organization)
                ->where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail();
            abort_unless($course->teacher_id === $teacher->id, 404);

            return;
        }

        $this->access->membership($request, $organization, true);
    }

    private function moduleData(CourseModule $module): array
    {
        return [
            'id' => $module->id,
            'title' => $module->title,
            'description' => $module->description,
            'sort_order' => $module->sort_order,
        ];
    }
}
