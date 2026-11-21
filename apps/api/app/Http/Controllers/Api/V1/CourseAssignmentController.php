<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\OrganizationAccess;
use App\Services\OrganizationNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CourseAssignmentController
{
    public function __construct(private readonly OrganizationAccess $access, private readonly OrganizationNotifier $notifier) {}

    public function index(Request $request, int $organization, int $course): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        $courseRecord = Course::query()->where('organization_id', $organization)->findOrFail($course);
        $student = null;
        if ($membership->role === OrganizationRole::Student) {
            $student = $this->studentProfile($request, $organization);
            abort_unless($courseRecord->status === 'published' && $courseRecord->enrollments()->where('student_id', $student->id)->where('status', 'active')->exists(), 404);
        } else {
            $this->assertCourseEditor($request, $membership->role, $organization, $courseRecord);
        }

        $query = $courseRecord->assignments()->orderBy('due_at')->orderBy('id');
        if ($student) {
            $query->where('status', 'published');
        }
        $assignments = $query->get();
        $submission = $student ? AssignmentSubmission::query()->where('organization_id', $organization)
            ->where('student_id', $student->id)->whereIn('assignment_id', $assignments->pluck('id'))->get()->keyBy('assignment_id') : collect();

        return response()->json(['data' => $assignments->map(fn (CourseAssignment $assignment): array => [
            ...$this->assignmentData($assignment),
            'submission' => $student && $submission->has($assignment->id) ? $this->submissionData($submission->get($assignment->id)) : null,
        ])->values()]);
    }

    public function store(Request $request, int $organization, int $course): JsonResponse
    {
        $courseRecord = Course::query()->where('organization_id', $organization)->findOrFail($course);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $courseRecord);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:160'],
            'instructions' => ['nullable', 'string', 'max:10000'],
            'due_at' => ['nullable', 'date'],
            'max_points' => ['sometimes', 'numeric', 'gt:0', 'max:100000'],
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
        ]);
        if (($data['status'] ?? 'draft') === 'published' && $courseRecord->status !== 'published') {
            throw ValidationException::withMessages(['status' => ['Publish the course before publishing assignments to students.']]);
        }
        $assignment = $courseRecord->assignments()->create([
            ...$data,
            'organization_id' => $organization,
            'created_by' => $request->user()->id,
            'max_points' => $data['max_points'] ?? 100,
            'status' => $data['status'] ?? 'draft',
        ]);
        if ($assignment->status === 'published') {
            $this->notifier->learningActivity($courseRecord, $request->user()->id, 'learning_assignment_published', 'course_assignment', $assignment->id,
                'New assignment available', "{$courseRecord->title}: {$assignment->title}");
        }

        return response()->json(['data' => $this->assignmentData($assignment)], 201);
    }

    public function update(Request $request, int $organization, int $record): JsonResponse
    {
        $assignment = CourseAssignment::query()->where('organization_id', $organization)->with('course')->findOrFail($record);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $assignment->course);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'max_points' => ['sometimes', 'numeric', 'gt:0', 'max:100000'],
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
        ]);
        if (($data['status'] ?? $assignment->status) === 'published' && $assignment->course->status !== 'published') {
            throw ValidationException::withMessages(['status' => ['Publish the course before publishing assignments to students.']]);
        }
        $wasPublished = $assignment->status === 'published';
        $assignment->update($data);
        if (! $wasPublished && $assignment->status === 'published') {
            $this->notifier->learningActivity($assignment->course, $request->user()->id, 'learning_assignment_published', 'course_assignment', $assignment->id,
                'New assignment available', "{$assignment->course->title}: {$assignment->title}");
        }

        return response()->json(['data' => $this->assignmentData($assignment->refresh())]);
    }

    public function submit(Request $request, int $organization, int $assignment): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($membership->role === OrganizationRole::Student, 403);
        $student = $this->studentProfile($request, $organization);
        $record = CourseAssignment::query()->where('organization_id', $organization)->with('course')->findOrFail($assignment);
        abort_unless($record->status === 'published' && $record->course->status === 'published'
            && $record->course->enrollments()->where('student_id', $student->id)->where('status', 'active')->exists(), 404);
        $data = $request->validate(['answer' => ['required', 'string', 'min:1', 'max:50000']]);
        $existing = AssignmentSubmission::query()->where('assignment_id', $record->id)->where('student_id', $student->id)->first();
        if ($existing?->graded_at !== null) {
            abort(409, 'A graded submission cannot be changed.');
        }
        $submission = DB::transaction(fn (): AssignmentSubmission => AssignmentSubmission::query()->updateOrCreate(
            ['assignment_id' => $record->id, 'student_id' => $student->id],
            ['organization_id' => $organization, 'answer' => $data['answer'], 'submitted_at' => now()],
        ));

        return response()->json(['data' => $this->submissionData($submission)], $existing ? 200 : 201);
    }

    public function submissions(Request $request, int $organization, int $assignment): JsonResponse
    {
        $record = CourseAssignment::query()->where('organization_id', $organization)->with('course')->findOrFail($assignment);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $record->course);
        $submissions = $record->submissions()->with('student:id,name,admission_number')->orderByDesc('submitted_at')->get();

        return response()->json(['data' => $submissions->map(fn (AssignmentSubmission $submission): array => [
            ...$this->submissionData($submission),
            'student' => $submission->student->only(['id', 'name', 'admission_number']),
        ])->values()]);
    }

    public function grade(Request $request, int $organization, int $submission): JsonResponse
    {
        $record = AssignmentSubmission::query()->where('organization_id', $organization)->with('assignment.course')->findOrFail($submission);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $record->assignment->course);
        $data = $request->validate([
            'awarded_points' => ['required', 'numeric', 'min:0'],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);
        if ((float) $data['awarded_points'] > (float) $record->assignment->max_points) {
            throw ValidationException::withMessages(['awarded_points' => ['The score cannot exceed the assignment maximum.']]);
        }
        $record->update([
            'awarded_points' => $data['awarded_points'],
            'feedback' => $data['feedback'] ?? null,
            'graded_by' => $request->user()->id,
            'graded_at' => now(),
        ]);

        return response()->json(['data' => $this->submissionData($record->refresh())]);
    }

    private function assertCourseEditor(Request $request, OrganizationRole $role, int $organization, Course $course): void
    {
        if ($role === OrganizationRole::Teacher) {
            $teacher = Teacher::query()->where('organization_id', $organization)->where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail();
            abort_unless($course->teacher_id === $teacher->id, 404);

            return;
        }
        abort_unless(in_array($role, [OrganizationRole::Admin, OrganizationRole::Management, OrganizationRole::IndependentEducator], true), 403);
        $this->access->membership($request, $organization, true);
    }

    private function studentProfile(Request $request, int $organization): Student
    {
        return Student::query()->where('organization_id', $organization)->where('user_id', $request->user()->id)
            ->where('status', 'active')->firstOrFail();
    }

    private function assignmentData(CourseAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'course_id' => $assignment->course_id,
            'title' => $assignment->title,
            'instructions' => $assignment->instructions,
            'due_at' => $assignment->due_at?->toISOString(),
            'max_points' => $assignment->max_points,
            'status' => $assignment->status,
        ];
    }

    private function submissionData(AssignmentSubmission $submission): array
    {
        return [
            'id' => $submission->id,
            'assignment_id' => $submission->assignment_id,
            'answer' => $submission->answer,
            'submitted_at' => $submission->submitted_at?->toISOString(),
            'awarded_points' => $submission->awarded_points,
            'feedback' => $submission->feedback,
            'graded_at' => $submission->graded_at?->toISOString(),
        ];
    }
}
