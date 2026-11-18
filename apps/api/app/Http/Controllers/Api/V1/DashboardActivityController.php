<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseLessonProgress;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Teacher;
use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardActivityController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        $timezone = $membership->organization->timezone ?: 'UTC';
        $role = $membership->role;
        $courseQuery = Course::query()->where('organization_id', $organization)->where('status', 'published');
        $studentIds = null;

        if ($role === OrganizationRole::Teacher) {
            $teacher = Teacher::query()->where('organization_id', $organization)
                ->where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail();
            $courseQuery->where('teacher_id', $teacher->id);
        } elseif ($role === OrganizationRole::Student) {
            $student = Student::query()->where('organization_id', $organization)
                ->where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail();
            $studentIds = [$student->id];
        } elseif ($role === OrganizationRole::Parent) {
            $studentIds = StudentGuardian::query()->where('organization_id', $organization)
                ->where('guardian_user_id', $request->user()->id)
                ->whereHas('student', fn ($query) => $query->where('status', 'active'))
                ->pluck('student_id')->all();
        } elseif (! in_array($role, [
            OrganizationRole::Admin,
            OrganizationRole::Management,
            OrganizationRole::IndependentEducator,
        ], true)) {
            abort(403);
        }

        $courseIds = $courseQuery->pluck('id');
        $enrollments = CourseEnrollment::query()->where('organization_id', $organization)
            ->whereIn('course_id', $courseIds)->where('status', 'active')
            ->whereHas('student', fn ($query) => $query->where('status', 'active'));
        if ($studentIds !== null) {
            $enrollments->whereIn('student_id', $studentIds);
        }
        $activeStudentIds = $enrollments->distinct()->pluck('student_id');

        $today = Carbon::now($timezone)->startOfDay();
        $from = $today->copy()->subDays(6);
        $days = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $date = $from->copy()->addDays($offset);
            $startUtc = $date->copy()->startOfDay()->utc();
            $endUtc = $date->copy()->endOfDay()->utc();

            $lessons = CourseLessonProgress::query()->where('organization_id', $organization)
                ->whereIn('course_id', $courseIds)->whereIn('student_id', $activeStudentIds)
                ->whereNotNull('completed_at')->whereBetween('completed_at', [$startUtc, $endUtc])
                ->whereHas('lesson', fn ($query) => $query->where('status', 'published'))
                ->count();
            $submissions = AssignmentSubmission::query()->where('organization_id', $organization)
                ->whereIn('student_id', $activeStudentIds)->whereBetween('submitted_at', [$startUtc, $endUtc])
                ->whereHas('assignment', fn ($query) => $query->where('status', 'published')
                    ->whereHas('course', fn ($course) => $course->whereIn('id', $courseIds)->where('status', 'published')))
                ->count();

            $days[] = [
                'date' => $date->toDateString(),
                'label' => $date->format('D'),
                'lessons_completed' => $lessons,
                'assignments_submitted' => $submissions,
            ];
        }

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $today->toDateString(),
            'days' => $days,
        ]]);
    }
}
