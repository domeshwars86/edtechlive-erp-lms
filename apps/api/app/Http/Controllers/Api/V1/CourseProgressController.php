<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\Course;
use App\Models\CourseLesson;
use App\Models\CourseLessonProgress;
use App\Models\Student;
use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CourseProgressController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization, int $course): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($membership->role === OrganizationRole::Student, 403);
        $student = $this->studentProfile($request, $organization);
        $courseRecord = $this->enrolledCourse($organization, $course, $student);
        $lessons = $courseRecord->lessons()->where('status', 'published')->get();
        $progress = CourseLessonProgress::query()->where('organization_id', $organization)
            ->where('course_id', $course)->where('student_id', $student->id)
            ->whereIn('lesson_id', $lessons->modelKeys())->whereNotNull('completed_at')
            ->get()->keyBy('lesson_id');

        return response()->json([
            'data' => $lessons->map(fn (CourseLesson $lesson): array => [
                'lesson_id' => $lesson->id,
                'completed_at' => $progress->get($lesson->id)?->completed_at?->toISOString(),
            ])->values(),
            'summary' => [
                'completed' => $progress->count(),
                'total' => $lessons->count(),
                'percent' => $lessons->isEmpty() ? 0 : (int) round($progress->count() / $lessons->count() * 100),
            ],
        ]);
    }

    public function update(Request $request, int $organization, int $course, int $lesson): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($membership->role === OrganizationRole::Student, 403);
        $student = $this->studentProfile($request, $organization);
        $courseRecord = $this->enrolledCourse($organization, $course, $student);
        $lessonRecord = $courseRecord->lessons()->where('status', 'published')->findOrFail($lesson);
        $data = $request->validate(['completed' => ['required', 'boolean']]);

        $record = DB::transaction(function () use ($organization, $course, $lessonRecord, $student, $data): CourseLessonProgress {
            $record = CourseLessonProgress::query()->firstOrNew([
                'lesson_id' => $lessonRecord->id,
                'student_id' => $student->id,
            ]);
            $record->organization_id = $organization;
            $record->course_id = $course;
            $record->completed_at = (bool) $data['completed'] ? ($record->completed_at ?? now()) : null;
            $record->save();

            return $record;
        });

        return response()->json(['data' => [
            'lesson_id' => $lessonRecord->id,
            'completed_at' => $record->completed_at?->toISOString(),
        ]]);
    }

    private function enrolledCourse(int $organization, int $course, Student $student): Course
    {
        return Course::query()->where('organization_id', $organization)->where('status', 'published')
            ->whereHas('enrollments', fn ($query) => $query->where('student_id', $student->id)->where('status', 'active'))
            ->findOrFail($course);
    }

    private function studentProfile(Request $request, int $organization): Student
    {
        return Student::query()->where('organization_id', $organization)->where('user_id', $request->user()->id)
            ->where('status', 'active')->firstOrFail();
    }
}
