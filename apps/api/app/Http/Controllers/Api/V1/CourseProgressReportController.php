<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseLessonProgress;
use App\Models\Teacher;
use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseProgressReportController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        $courses = Course::query()->where('organization_id', $organization)->where('status', 'published');

        if ($membership->role === OrganizationRole::Teacher) {
            $teacher = Teacher::query()->where('organization_id', $organization)
                ->where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail();
            $courses->where('teacher_id', $teacher->id);
        } else {
            abort_unless(in_array($membership->role, [
                OrganizationRole::Admin,
                OrganizationRole::Management,
                OrganizationRole::IndependentEducator,
            ], true), 403);
        }

        $courseRows = $courses->withCount(['lessons as published_lessons_count' => fn ($query) => $query->where('status', 'published')])
            ->orderBy('title')->get(['id', 'title', 'teacher_id']);
        $courseIds = $courseRows->modelKeys();
        $enrollments = CourseEnrollment::query()->where('organization_id', $organization)
            ->whereIn('course_id', $courseIds)->where('status', 'active')
            ->whereHas('student', fn ($query) => $query->where('status', 'active'))
            ->get(['course_id', 'student_id']);
        $progress = CourseLessonProgress::query()->where('organization_id', $organization)
            ->whereIn('course_id', $courseIds)->whereNotNull('completed_at')
            ->whereHas('lesson', fn ($query) => $query->where('status', 'published'))
            ->selectRaw('course_id, student_id, COUNT(DISTINCT lesson_id) as completed_count')
            ->groupBy('course_id', 'student_id')->get()->keyBy(fn ($row): string => "{$row->course_id}:{$row->student_id}");

        $report = $courseRows->map(function (Course $course) use ($enrollments, $progress): array {
            $learners = $enrollments->where('course_id', $course->id);
            $lessonCount = (int) $course->published_lessons_count;
            $percentages = $learners->map(function (CourseEnrollment $enrollment) use ($course, $lessonCount, $progress): int {
                $completed = (int) ($progress->get("{$course->id}:{$enrollment->student_id}")?->completed_count ?? 0);

                return $lessonCount > 0 ? (int) round(min($completed, $lessonCount) / $lessonCount * 100) : 0;
            });

            return [
                'course_id' => $course->id,
                'title' => $course->title,
                'lessons' => $lessonCount,
                'learners' => $learners->count(),
                'average_percent' => $percentages->isEmpty() ? 0 : (int) round($percentages->avg()),
                'completed_learners' => $percentages->filter(fn (int $percent): bool => $percent === 100 && $lessonCount > 0)->count(),
            ];
        })->values();

        $coursesWithLearners = $report->filter(fn (array $course): bool => $course['learners'] > 0);

        return response()->json([
            'data' => $report,
            'summary' => [
                'courses' => $report->count(),
                'learners' => $report->sum('learners'),
                'average_percent' => $coursesWithLearners->isEmpty() ? 0 : (int) round($coursesWithLearners->avg('average_percent')),
            ],
        ]);
    }
}
