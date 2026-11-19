<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\Course;
use App\Models\CourseQuiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CourseQuizController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization, int $course): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        $courseRecord = Course::query()->where('organization_id', $organization)->findOrFail($course);
        $student = $this->authorizeCourseRead($request, $membership->role, $organization, $courseRecord);
        $quizzes = $courseRecord->quizzes()->with('questions')->orderByDesc('updated_at')->get();
        if ($student) {
            $quizzes = $quizzes->where('status', 'published')->values();
        }
        $attempts = $student ? QuizAttempt::query()->where('student_id', $student->id)
            ->whereIn('quiz_id', $quizzes->pluck('id'))->orderByDesc('attempt_number')->get()->groupBy('quiz_id') : collect();

        return response()->json(['data' => $quizzes->map(fn (CourseQuiz $quiz): array => [
            ...$this->quizData($quiz, ! $student),
            'attempts_used' => $student ? $attempts->get($quiz->id, collect())->count() : null,
            'latest_result' => $student && $attempts->has($quiz->id) ? $this->attemptData($attempts->get($quiz->id)->first()) : null,
        ])->values()]);
    }

    public function store(Request $request, int $organization, int $course): JsonResponse
    {
        $courseRecord = Course::query()->where('organization_id', $organization)->findOrFail($course);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $courseRecord);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:160'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'max_attempts' => ['sometimes', 'integer', 'min:1', 'max:5'],
        ]);
        $quiz = $courseRecord->quizzes()->create([
            ...$data,
            'organization_id' => $organization,
            'created_by' => $request->user()->id,
            'max_attempts' => $data['max_attempts'] ?? 1,
            'status' => 'draft',
        ]);

        return response()->json(['data' => $this->quizData($quiz)], 201);
    }

    public function update(Request $request, int $organization, int $quiz): JsonResponse
    {
        $record = CourseQuiz::query()->where('organization_id', $organization)->with('course')->findOrFail($quiz);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $record->course);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'time_limit_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:240'],
            'max_attempts' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
        ]);
        if (($data['status'] ?? $record->status) === 'published' && $record->questions()->doesntExist()) {
            throw ValidationException::withMessages(['status' => ['Add at least one question before publishing a quiz.']]);
        }
        if ($record->status === 'published' && $request->hasAny(['title', 'instructions', 'time_limit_minutes', 'max_attempts'])) {
            throw ValidationException::withMessages(['status' => ['Published quiz settings cannot be changed after attempts are enabled.']]);
        }
        $record->update($data);

        return response()->json(['data' => $this->quizData($record->refresh()->load('questions'))]);
    }

    public function addQuestion(Request $request, int $organization, int $quiz): JsonResponse
    {
        $record = CourseQuiz::query()->where('organization_id', $organization)->with('course')->findOrFail($quiz);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $record->course);
        abort_unless($record->status === 'draft', 409, 'Questions cannot be changed after the quiz is published.');
        $data = $request->validate([
            'prompt' => ['required', 'string', 'min:3', 'max:2000'],
            'options' => ['required', 'array', 'min:2', 'max:6'],
            'options.*' => ['required', 'string', 'min:1', 'max:500', 'distinct'],
            'correct_option' => ['required', 'integer', 'min:0', 'max:5'],
            'points' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ]);
        if ($data['correct_option'] >= count($data['options'])) {
            throw ValidationException::withMessages(['correct_option' => ['Select one of the submitted answer choices.']]);
        }
        $question = $record->questions()->create([
            ...$data,
            'organization_id' => $organization,
            'points' => $data['points'] ?? 1,
            'sort_order' => $data['sort_order'] ?? $record->questions()->count(),
        ]);

        return response()->json(['data' => $this->questionData($question, true)], 201);
    }

    public function startAttempt(Request $request, int $organization, int $quiz): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($membership->role === OrganizationRole::Student, 403);
        $student = $this->studentProfile($request, $organization);
        $record = CourseQuiz::query()->where('organization_id', $organization)->with(['course', 'questions'])->findOrFail($quiz);
        abort_unless($record->status === 'published' && $record->course->status === 'published'
            && $record->course->enrollments()->where('student_id', $student->id)->where('status', 'active')->exists(), 404);
        $attempt = DB::transaction(function () use ($record, $student, $organization): QuizAttempt {
            $lockedQuiz = CourseQuiz::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $count = QuizAttempt::query()->where('quiz_id', $lockedQuiz->id)->where('student_id', $student->id)->count();
            if ($count >= $lockedQuiz->max_attempts) {
                abort(409, 'You have used all attempts allowed for this quiz.');
            }

            return QuizAttempt::query()->create([
                'organization_id' => $organization,
                'quiz_id' => $lockedQuiz->id,
                'student_id' => $student->id,
                'attempt_number' => $count + 1,
                'started_at' => now(),
            ]);
        });

        return response()->json([
            'data' => [
                'attempt' => $this->attemptData($attempt),
                'quiz' => ['id' => $record->id, 'title' => $record->title, 'instructions' => $record->instructions, 'time_limit_minutes' => $record->time_limit_minutes],
                'questions' => $record->questions->map(fn (QuizQuestion $question): array => $this->questionData($question))->values(),
            ],
        ], 201);
    }

    public function submitAttempt(Request $request, int $organization, int $quiz, int $attempt): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($membership->role === OrganizationRole::Student, 403);
        $student = $this->studentProfile($request, $organization);
        $record = QuizAttempt::query()->where('organization_id', $organization)->where('quiz_id', $quiz)
            ->where('student_id', $student->id)->with(['quiz.questions'])->findOrFail($attempt);
        abort_if($record->submitted_at !== null, 409, 'This attempt has already been submitted.');
        $data = $request->validate([
            'answers' => ['present', 'array', 'max:100'],
            'answers.*.question_id' => ['required', 'integer', 'distinct'],
            'answers.*.selected_option' => ['required', 'integer', 'min:0', 'max:5'],
        ]);
        $questions = $record->quiz->questions->keyBy('id');
        $answers = [];
        foreach ($data['answers'] as $answer) {
            $question = $questions->get($answer['question_id']);
            if (! $question || $answer['selected_option'] >= count($question->options)) {
                throw ValidationException::withMessages(['answers' => ['Answers must reference this quiz and a valid option.']]);
            }
            $answers[$question->id] = $answer['selected_option'];
        }
        $maxScore = $record->quiz->questions->sum('points');
        $score = $record->quiz->questions->sum(fn (QuizQuestion $question): int => ($answers[$question->id] ?? null) === $question->correct_option ? $question->points : 0);
        if ($record->quiz->time_limit_minutes !== null && now()->gt($record->started_at->addMinutes($record->quiz->time_limit_minutes))) {
            $record->update(['answers' => [], 'score' => 0, 'max_score' => $maxScore, 'submitted_at' => now()]);
            abort(409, 'The quiz time limit expired; this attempt was submitted with no answers.');
        }
        $record->update(['answers' => $answers, 'score' => $score, 'max_score' => $maxScore, 'submitted_at' => now()]);

        return response()->json(['data' => $this->attemptData($record->refresh())]);
    }

    public function attempts(Request $request, int $organization, int $quiz): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $membership = $this->access->membership($request, $organization);
        $record = CourseQuiz::query()->where('organization_id', $organization)->with('course')->findOrFail($quiz);
        $student = $this->authorizeCourseRead($request, $membership->role, $organization, $record->course);
        $query = $record->attempts()->whereNotNull('submitted_at')->with('student:id,name,admission_number');
        if ($student) {
            $query->where('student_id', $student->id);
        }
        $attempts = $query->orderByDesc('submitted_at')->orderByDesc('id')->paginate(
            min(max($filters['per_page'] ?? 20, 1), 100),
            ['*'],
            'page',
            max($filters['page'] ?? 1, 1),
        );

        return response()->json([
            'data' => $attempts->getCollection()->map(fn (QuizAttempt $attempt): array => [
                ...$this->attemptData($attempt),
                'student' => $student ? null : $attempt->student->only(['id', 'name', 'admission_number']),
            ])->values(),
            'meta' => [
                'current_page' => $attempts->currentPage(),
                'last_page' => $attempts->lastPage(),
                'per_page' => $attempts->perPage(),
                'total' => $attempts->total(),
            ],
        ]);
    }

    private function authorizeCourseRead(Request $request, OrganizationRole $role, int $organization, Course $course): ?Student
    {
        if ($role === OrganizationRole::Student) {
            $student = $this->studentProfile($request, $organization);
            abort_unless($course->status === 'published' && $course->enrollments()->where('student_id', $student->id)->where('status', 'active')->exists(), 404);

            return $student;
        }
        $this->assertCourseEditor($request, $role, $organization, $course);

        return null;
    }

    private function assertCourseEditor(Request $request, OrganizationRole $role, int $organization, Course $course): void
    {
        if ($role === OrganizationRole::Teacher) {
            $teacher = Teacher::query()->where('organization_id', $organization)->where('user_id', $request->user()->id)
                ->where('status', 'active')->firstOrFail();
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

    private function quizData(CourseQuiz $quiz, bool $includeAnswers = false): array
    {
        return [
            'id' => $quiz->id,
            'course_id' => $quiz->course_id,
            'title' => $quiz->title,
            'instructions' => $quiz->instructions,
            'time_limit_minutes' => $quiz->time_limit_minutes,
            'max_attempts' => $quiz->max_attempts,
            'status' => $quiz->status,
            'questions' => $quiz->questions->map(fn (QuizQuestion $question): array => $this->questionData($question, $includeAnswers))->values(),
        ];
    }

    private function questionData(QuizQuestion $question, bool $includeAnswer = false): array
    {
        return [
            'id' => $question->id,
            'prompt' => $question->prompt,
            'options' => $question->options,
            'points' => $question->points,
            'sort_order' => $question->sort_order,
            ...($includeAnswer ? ['correct_option' => $question->correct_option] : []),
        ];
    }

    private function attemptData(QuizAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'attempt_number' => $attempt->attempt_number,
            'answers' => $attempt->answers,
            'score' => $attempt->score,
            'max_score' => $attempt->max_score,
            'started_at' => $attempt->started_at?->toISOString(),
            'submitted_at' => $attempt->submitted_at?->toISOString(),
        ];
    }
}
