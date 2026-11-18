<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\AssignmentSubmission;
use App\Models\AttendanceRecord;
use App\Models\CourseEnrollment;
use App\Models\CourseLessonProgress;
use App\Models\QuizAttempt;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Services\OrganizationAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FamilyController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function student(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($membership->role === OrganizationRole::Student, 403);
        $student = Student::query()->where('organization_id', $organization)
            ->where('user_id', $request->user()->id)->where('status', 'active')
            ->with(['academicClass:id,name', 'academicSection:id,name', 'academicSession:id,name'])->firstOrFail();

        return response()->json(['data' => $this->studentData($student)]);
    }

    public function children(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($membership->role === OrganizationRole::Parent, 403);
        $links = StudentGuardian::query()->where('organization_id', $organization)
            ->where('guardian_user_id', $request->user()->id)
            ->whereHas('student', fn (Builder $query) => $query->where('status', 'active'))
            ->with(['student.academicClass:id,name', 'student.academicSection:id,name', 'student.academicSession:id,name'])
            ->orderByDesc('is_primary')->orderBy('student_id')->paginate(min(max($request->integer('per_page', 50), 1), 100));

        return response()->json([
            'data' => $links->getCollection()->map(fn (StudentGuardian $link): array => [
                ...$this->studentData($link->student),
                'relationship' => $link->relationship,
                'is_primary' => $link->is_primary,
            ])->values(),
            'meta' => ['current_page' => $links->currentPage(), 'last_page' => $links->lastPage(), 'per_page' => $links->perPage(), 'total' => $links->total()],
        ]);
    }

    public function studentAttendance(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($membership->role === OrganizationRole::Student, 403);
        $student = Student::query()->where('organization_id', $organization)
            ->where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail();

        return $this->attendance($request, $organization, $student);
    }

    public function childAttendance(Request $request, int $organization, int $student): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($membership->role === OrganizationRole::Parent, 403);
        $studentRecord = Student::query()->where('organization_id', $organization)->where('status', 'active')->findOrFail($student);
        abort_unless(StudentGuardian::query()->where('organization_id', $organization)
            ->where('student_id', $studentRecord->id)->where('guardian_user_id', $request->user()->id)->exists(), 404);

        return $this->attendance($request, $organization, $studentRecord);
    }

    public function studentResults(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($membership->role === OrganizationRole::Student, 403);
        $student = Student::query()->where('organization_id', $organization)
            ->where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail();

        return $this->results($organization, $student);
    }

    public function childResults(Request $request, int $organization, int $student): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($membership->role === OrganizationRole::Parent, 403);
        $studentRecord = Student::query()->where('organization_id', $organization)->where('status', 'active')->findOrFail($student);
        abort_unless(StudentGuardian::query()->where('organization_id', $organization)
            ->where('student_id', $studentRecord->id)->where('guardian_user_id', $request->user()->id)->exists(), 404);

        return $this->results($organization, $studentRecord);
    }

    public function studentProgress(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($membership->role === OrganizationRole::Student, 403);
        $student = Student::query()->where('organization_id', $organization)
            ->where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail();

        return $this->progress($organization, $student);
    }

    public function childProgress(Request $request, int $organization, int $student): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($membership->role === OrganizationRole::Parent, 403);
        $studentRecord = Student::query()->where('organization_id', $organization)->where('status', 'active')->findOrFail($student);
        abort_unless(StudentGuardian::query()->where('organization_id', $organization)
            ->where('student_id', $studentRecord->id)->where('guardian_user_id', $request->user()->id)->exists(), 404);

        return $this->progress($organization, $studentRecord);
    }

    public function studentHomework(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($membership->role === OrganizationRole::Student, 403);
        $student = Student::query()->where('organization_id', $organization)
            ->where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail();

        return $this->homework($request, $organization, $student);
    }

    public function childHomework(Request $request, int $organization, int $student): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($membership->role === OrganizationRole::Parent, 403);
        $studentRecord = Student::query()->where('organization_id', $organization)->where('status', 'active')->findOrFail($student);
        abort_unless(StudentGuardian::query()->where('organization_id', $organization)
            ->where('student_id', $studentRecord->id)->where('guardian_user_id', $request->user()->id)->exists(), 404);

        return $this->homework($request, $organization, $studentRecord);
    }

    private function homework(Request $request, int $organization, Student $student): JsonResponse
    {
        $enrollment = CourseEnrollment::query()->where('course_enrollments.organization_id', $organization)
            ->where('course_enrollments.student_id', $student->id)->where('course_enrollments.status', 'active')
            ->where('course_enrollments.organization_id', $organization);
        $query = $enrollment->join('course_assignments', function ($join): void {
            $join->on('course_assignments.course_id', '=', 'course_enrollments.course_id')
                ->on('course_assignments.organization_id', '=', 'course_enrollments.organization_id')
                ->where('course_assignments.status', '=', 'published');
        })->join('courses', function ($join) use ($organization): void {
            $join->on('courses.id', '=', 'course_assignments.course_id')
                ->on('courses.organization_id', '=', 'course_assignments.organization_id')
                ->where('courses.organization_id', '=', $organization)
                ->where('courses.status', '=', 'published');
        })->leftJoin('assignment_submissions', function ($join): void {
            $join->on('assignment_submissions.assignment_id', '=', 'course_assignments.id')
                ->on('assignment_submissions.student_id', '=', 'course_enrollments.student_id')
                ->on('assignment_submissions.organization_id', '=', 'course_enrollments.organization_id');
        })->select([
            'course_assignments.id', 'course_assignments.title', 'course_assignments.max_points', 'course_assignments.due_at',
            'courses.title as course_title', 'assignment_submissions.submitted_at', 'assignment_submissions.awarded_points',
            'assignment_submissions.graded_at', 'assignment_submissions.feedback',
        ])->orderByRaw('CASE WHEN course_assignments.due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('course_assignments.due_at')->orderByDesc('course_assignments.id');
        $perPage = min(max($request->integer('per_page', 50), 1), 100);
        $assignments = $query->paginate($perPage);
        $now = CarbonImmutable::now();
        $data = $assignments->getCollection()->map(function ($assignment) use ($now): array {
            $dueAt = $assignment->due_at ? CarbonImmutable::parse($assignment->due_at) : null;
            $submitted = $assignment->submitted_at !== null;
            $graded = $assignment->graded_at !== null;
            $status = $graded ? 'graded' : ($submitted ? 'submitted' : ($dueAt?->lt($now) ? 'overdue' : 'pending'));

            return [
                'id' => $assignment->id,
                'title' => $assignment->title,
                'course' => $assignment->course_title,
                'due_at' => $dueAt?->toISOString(),
                'max_points' => $assignment->max_points,
                'submitted_at' => $assignment->submitted_at ? CarbonImmutable::parse($assignment->submitted_at)->toISOString() : null,
                'score' => $assignment->awarded_points,
                'feedback' => $assignment->feedback,
                'status' => $status,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => ['current_page' => $assignments->currentPage(), 'last_page' => $assignments->lastPage(), 'total' => $assignments->total()],
        ]);
    }

    private function progress(int $organization, Student $student): JsonResponse
    {
        $enrollments = CourseEnrollment::query()->where('organization_id', $organization)
            ->where('student_id', $student->id)->where('status', 'active')
            ->whereHas('student', fn (Builder $query) => $query->where('status', 'active'))
            ->whereHas('course', fn (Builder $query) => $query->where('organization_id', $organization)->where('status', 'published'))
            ->with(['course' => fn ($query) => $query->withCount(['lessons as published_lessons_count' => fn ($lessons) => $lessons->where('status', 'published')])])
            ->orderBy('course_id')->get();
        $courseIds = $enrollments->pluck('course_id');
        $completed = CourseLessonProgress::query()->where('organization_id', $organization)
            ->where('student_id', $student->id)->whereIn('course_id', $courseIds)->whereNotNull('completed_at')
            ->whereHas('lesson', fn (Builder $query) => $query->where('status', 'published'))
            ->selectRaw('course_id, COUNT(DISTINCT lesson_id) as completed_count')->groupBy('course_id')->get()->keyBy('course_id');
        $data = $enrollments->map(function (CourseEnrollment $enrollment) use ($completed): array {
            $lessonCount = (int) $enrollment->course->published_lessons_count;
            $completedCount = min((int) ($completed->get($enrollment->course_id)?->completed_count ?? 0), $lessonCount);

            return [
                'course_id' => $enrollment->course_id,
                'title' => $enrollment->course->title,
                'lessons' => $lessonCount,
                'completed_lessons' => $completedCount,
                'completion_percent' => $lessonCount > 0 ? (int) round($completedCount / $lessonCount * 100) : 0,
            ];
        })->values();
        $totalLessons = $data->sum('lessons');
        $completedLessons = $data->sum('completed_lessons');

        return response()->json(['data' => $data, 'summary' => [
            'courses' => $data->count(),
            'lessons' => $totalLessons,
            'completed_lessons' => $completedLessons,
            'completion_percent' => $totalLessons > 0 ? (int) round($completedLessons / $totalLessons * 100) : 0,
        ]]);
    }

    private function results(int $organization, Student $student): JsonResponse
    {
        $quizzes = QuizAttempt::query()->where('quiz_attempts.organization_id', $organization)
            ->where('quiz_attempts.student_id', $student->id)->whereNotNull('quiz_attempts.submitted_at')
            ->where('course_quizzes.organization_id', $organization)->where('courses.organization_id', $organization)
            ->where('course_quizzes.status', 'published')->where('courses.status', 'published')
            ->join('course_quizzes', 'course_quizzes.id', '=', 'quiz_attempts.quiz_id')
            ->join('courses', 'courses.id', '=', 'course_quizzes.course_id')
            ->select('quiz_attempts.id', 'quiz_attempts.score', 'quiz_attempts.max_score', 'quiz_attempts.attempt_number', 'quiz_attempts.submitted_at', 'course_quizzes.title', 'courses.title as course_title')
            ->orderByDesc('quiz_attempts.submitted_at')->limit(100)->get()
            ->map(fn (QuizAttempt $attempt): array => [
                'id' => $attempt->id, 'title' => $attempt->title, 'course' => $attempt->course_title,
                'score' => $attempt->score, 'max_score' => $attempt->max_score,
                'attempt_number' => $attempt->attempt_number, 'completed_at' => $attempt->submitted_at?->toISOString(),
            ])->values();

        $assignments = AssignmentSubmission::query()->where('assignment_submissions.organization_id', $organization)
            ->where('assignment_submissions.student_id', $student->id)->whereNotNull('assignment_submissions.graded_at')
            ->where('course_assignments.organization_id', $organization)->where('courses.organization_id', $organization)
            ->where('course_assignments.status', 'published')->where('courses.status', 'published')
            ->join('course_assignments', 'course_assignments.id', '=', 'assignment_submissions.assignment_id')
            ->join('courses', 'courses.id', '=', 'course_assignments.course_id')
            ->select('assignment_submissions.id', 'assignment_submissions.awarded_points', 'assignment_submissions.feedback', 'assignment_submissions.graded_at', 'course_assignments.title', 'course_assignments.max_points', 'courses.title as course_title')
            ->orderByDesc('assignment_submissions.graded_at')->limit(100)->get()
            ->map(fn (AssignmentSubmission $submission): array => [
                'id' => $submission->id, 'title' => $submission->title, 'course' => $submission->course_title,
                'score' => $submission->awarded_points, 'max_score' => $submission->max_points,
                'feedback' => $submission->feedback, 'completed_at' => $submission->graded_at?->toISOString(),
            ])->values();

        return response()->json(['data' => ['quizzes' => $quizzes, 'assignments' => $assignments]]);
    }

    private function attendance(Request $request, int $organization, Student $student): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        if (isset($filters['from'], $filters['to']) && $filters['from'] > $filters['to']) {
            throw ValidationException::withMessages(['to' => ['The end date must be on or after the start date.']]);
        }
        $query = AttendanceRecord::query()->where('organization_id', $organization)
            ->where('student_id', $student->id)
            ->with(['academicSection:id,name,academic_class_id', 'academicSection.academicClass:id,name', 'academicSession:id,name']);
        if (isset($filters['from'])) {
            $query->whereDate('attendance_date', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate('attendance_date', '<=', $filters['to']);
        }
        $records = $query->orderByDesc('attendance_date')->paginate(min(max($request->integer('per_page', 50), 1), 100));

        return response()->json([
            'data' => $records->getCollection()->map(fn (AttendanceRecord $record): array => [
                'id' => $record->id,
                'date' => $record->attendance_date->toDateString(),
                'status' => $record->status,
                'note' => $record->note,
                'section' => ['id' => $record->academicSection->id, 'name' => $record->academicSection->name, 'class' => $record->academicSection->academicClass->name],
                'session' => ['id' => $record->academicSession->id, 'name' => $record->academicSession->name],
            ])->values(),
            'meta' => ['current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'per_page' => $records->perPage(), 'total' => $records->total()],
        ]);
    }

    private function studentData(Student $student): array
    {
        return [
            'id' => $student->id,
            'name' => $student->name,
            'admission_number' => $student->admission_number,
            'academic_class' => $student->academicClass?->only(['id', 'name']),
            'academic_section' => $student->academicSection?->only(['id', 'name']),
            'academic_session' => $student->academicSession?->only(['id', 'name']),
        ];
    }
}
