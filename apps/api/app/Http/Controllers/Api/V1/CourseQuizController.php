<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\Course;
use App\Models\CourseQuestionBank;
use App\Models\CourseQuestionBankQuestion;
use App\Models\CourseQuiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\OrganizationAccess;
use App\Services\OrganizationNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CourseQuizController
{
    public function __construct(private readonly OrganizationAccess $access, private readonly OrganizationNotifier $notifier) {}

    public function index(Request $request, int $organization, int $course): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        $courseRecord = Course::query()->where('organization_id', $organization)->findOrFail($course);
        $student = $this->authorizeCourseRead($request, $membership->role, $organization, $courseRecord);
        $quizzes = $courseRecord->quizzes()->with('questions')->orderByDesc('updated_at')->get();
        if ($student) {
            $quizzes = $quizzes->where('status', 'published')->values();
        }
        $attempts = $student ? QuizAttempt::query()->with('quiz')->where('student_id', $student->id)
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
            'opens_at' => ['sometimes', 'nullable', 'date'],
            'closes_at' => ['sometimes', 'nullable', 'date'],
            'negative_marking_rate' => ['sometimes', 'numeric', 'min:0', 'max:1', 'decimal:0,2'],
            'pass_percent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
        ]);
        $this->assertSchedule($data['opens_at'] ?? null, $data['closes_at'] ?? null);
        $quiz = $courseRecord->quizzes()->create([
            ...$data,
            'organization_id' => $organization,
            'created_by' => $request->user()->id,
            'max_attempts' => $data['max_attempts'] ?? 1,
            'negative_marking_rate' => $data['negative_marking_rate'] ?? 0,
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
            'opens_at' => ['sometimes', 'nullable', 'date'],
            'closes_at' => ['sometimes', 'nullable', 'date'],
            'negative_marking_rate' => ['sometimes', 'numeric', 'min:0', 'max:1', 'decimal:0,2'],
            'pass_percent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
        ]);
        $this->assertSchedule(
            array_key_exists('opens_at', $data) ? $data['opens_at'] : $record->opens_at,
            array_key_exists('closes_at', $data) ? $data['closes_at'] : $record->closes_at,
        );
        if (($data['status'] ?? $record->status) === 'published' && $record->questions()->doesntExist()) {
            throw ValidationException::withMessages(['status' => ['Add at least one question before publishing a quiz.']]);
        }
        if ($record->status === 'published' && $request->hasAny(['title', 'instructions', 'time_limit_minutes', 'max_attempts', 'opens_at', 'closes_at', 'negative_marking_rate', 'pass_percent'])) {
            throw ValidationException::withMessages(['status' => ['Published quiz settings cannot be changed after attempts are enabled.']]);
        }
        $wasPublished = $record->status === 'published';
        $record->update($data);
        if (! $wasPublished && $record->status === 'published') {
            $this->notifier->learningActivity($record->course, $request->user()->id, 'learning_quiz_published', 'course_quiz', $record->id,
                'New quiz available', "{$record->course->title}: {$record->title}");
        }

        return response()->json(['data' => $this->quizData($record->refresh()->load('questions'))]);
    }

    public function addQuestion(Request $request, int $organization, int $quiz): JsonResponse
    {
        $record = CourseQuiz::query()->where('organization_id', $organization)->with('course')->findOrFail($quiz);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $record->course);
        abort_unless($record->status === 'draft', 409, 'Questions cannot be changed after the quiz is published.');
        $data = $this->validatedQuestionData($request);
        $question = $record->questions()->create([
            ...$data,
            'organization_id' => $organization,
            'sort_order' => $data['sort_order'] ?? $record->questions()->count(),
        ]);

        return response()->json(['data' => $this->questionData($question, true)], 201);
    }

    public function questionBanks(Request $request, int $organization, int $course): JsonResponse
    {
        $courseRecord = Course::query()->where('organization_id', $organization)->findOrFail($course);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $courseRecord);

        return response()->json(['data' => $courseRecord->questionBanks()->withCount('questions')->orderBy('title')->get()]);
    }

    public function storeQuestionBank(Request $request, int $organization, int $course): JsonResponse
    {
        $courseRecord = Course::query()->where('organization_id', $organization)->findOrFail($course);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $courseRecord);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:150', Rule::unique('course_question_banks', 'title')->where('course_id', $courseRecord->id)],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        $bank = $courseRecord->questionBanks()->create([
            ...$data,
            'organization_id' => $organization,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $bank->loadCount('questions')], 201);
    }

    public function updateQuestionBank(Request $request, int $organization, int $bank): JsonResponse
    {
        $record = CourseQuestionBank::query()->where('organization_id', $organization)->with('course')->findOrFail($bank);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $record->course);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:2', 'max:150', Rule::unique('course_question_banks', 'title')->where('course_id', $record->course_id)->ignore($record->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $record->update($data);

        return response()->json(['data' => $record->refresh()->loadCount('questions')]);
    }

    public function deleteQuestionBank(Request $request, int $organization, int $bank): JsonResponse
    {
        $record = CourseQuestionBank::query()->where('organization_id', $organization)->with('course')->findOrFail($bank);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $record->course);
        $record->delete();

        return response()->json(status: 204);
    }

    public function questionBankQuestions(Request $request, int $organization, int $bank): JsonResponse
    {
        $record = CourseQuestionBank::query()->where('organization_id', $organization)->with('course')->findOrFail($bank);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $record->course);
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = $record->questions();
        if (filled($filters['q'] ?? null)) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($filters['q'])).'%';
            $query->where('prompt', 'like', $term);
        }
        $questions = $query->paginate($filters['per_page'] ?? 50, ['*'], 'page', $filters['page'] ?? 1);

        return response()->json([
            'data' => $questions->getCollection()->map(fn (CourseQuestionBankQuestion $question): array => $this->bankQuestionData($question))->values(),
            'meta' => ['current_page' => $questions->currentPage(), 'last_page' => $questions->lastPage(), 'per_page' => $questions->perPage(), 'total' => $questions->total()],
        ]);
    }

    public function storeQuestionBankQuestion(Request $request, int $organization, int $bank): JsonResponse
    {
        $record = CourseQuestionBank::query()->where('organization_id', $organization)->with('course')->findOrFail($bank);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $record->course);
        abort_if($record->questions()->count() >= 1000, 409, 'This question bank has reached its 1,000 question limit.');
        $data = $this->validatedQuestionData($request);
        unset($data['correct_option']);
        $question = $record->questions()->create([
            ...$data,
            'organization_id' => $organization,
            'created_by' => $request->user()->id,
            'sort_order' => $data['sort_order'] ?? $record->questions()->count(),
        ]);

        return response()->json(['data' => $this->bankQuestionData($question)], 201);
    }

    public function updateQuestionBankQuestion(Request $request, int $organization, int $bank, int $question): JsonResponse
    {
        $record = CourseQuestionBank::query()->where('organization_id', $organization)->with('course')->findOrFail($bank);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $record->course);
        $questionRecord = $record->questions()->findOrFail($question);
        $data = $this->validatedQuestionData($request);
        unset($data['correct_option']);
        $questionRecord->update($data);

        return response()->json(['data' => $this->bankQuestionData($questionRecord->refresh())]);
    }

    public function deleteQuestionBankQuestion(Request $request, int $organization, int $bank, int $question): JsonResponse
    {
        $record = CourseQuestionBank::query()->where('organization_id', $organization)->with('course')->findOrFail($bank);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $record->course);
        $record->questions()->findOrFail($question)->delete();

        return response()->json(status: 204);
    }

    public function addQuestionFromBank(Request $request, int $organization, int $quiz): JsonResponse
    {
        $record = CourseQuiz::query()->where('organization_id', $organization)->with('course')->findOrFail($quiz);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $record->course);
        abort_unless($record->status === 'draft', 409, 'Questions cannot be changed after the quiz is published.');
        $data = $request->validate(['question_bank_question_id' => ['required', 'integer']]);
        $source = CourseQuestionBankQuestion::query()->where('organization_id', $organization)
            ->whereHas('bank', fn ($query) => $query->where('course_id', $record->course_id))
            ->findOrFail($data['question_bank_question_id']);
        abort_if($record->questions()->where('question_bank_question_id', $source->id)->exists(), 409, 'This bank question is already in the quiz.');
        $question = $record->questions()->create([
            'organization_id' => $organization,
            'question_bank_question_id' => $source->id,
            'prompt' => $source->prompt,
            'options' => $source->options,
            'question_type' => $source->question_type,
            'difficulty' => $source->difficulty,
            'correct_option' => $source->correct_options[0] ?? 0,
            'correct_options' => $source->correct_options,
            'points' => $source->points,
            'sort_order' => $record->questions()->count(),
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
        $now = now();
        abort_if($record->opens_at !== null && $now->lt($record->opens_at), 409, 'This quiz is not open yet.');
        abort_if($record->closes_at !== null && $now->gte($record->closes_at), 409, 'The quiz availability window has closed.');
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
            'answers.*.selected_option' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'answers.*.selected_options' => ['sometimes', 'array', 'min:1', 'max:6'],
            'answers.*.selected_options.*' => ['required', 'integer', 'min:0', 'max:5', 'distinct'],
            'answers.*.answer_text' => ['sometimes', 'string', 'max:10000'],
        ]);
        $questions = $record->quiz->questions->keyBy('id');
        $answers = [];
        foreach ($data['answers'] as $answer) {
            $question = $questions->get($answer['question_id']);
            $isShortAnswer = $question?->question_type === 'short_answer';
            $isMultiple = $question?->question_type === 'multiple_select';
            $hasSingle = array_key_exists('selected_option', $answer);
            if ($isShortAnswer) {
                if ($hasSingle || array_key_exists('selected_options', $answer) || ! array_key_exists('answer_text', $answer)) {
                    throw ValidationException::withMessages(['answers' => ['Written questions require answer_text and cannot include selected options.']]);
                }
                $answers[$question->id] = trim($answer['answer_text']);

                continue;
            }
            $selectedOptions = $isMultiple ? ($answer['selected_options'] ?? []) : (isset($answer['selected_option']) ? [$answer['selected_option']] : []);
            if (! $question || ($isMultiple && ($hasSingle || ! array_key_exists('selected_options', $answer)))
                || (! $isMultiple && (array_key_exists('selected_options', $answer) || ! $hasSingle))
                || array_key_exists('answer_text', $answer)
                || collect($selectedOptions)->contains(fn ($option) => $option >= count($question->options))) {
                throw ValidationException::withMessages(['answers' => ['Answers must reference this quiz and a valid option.']]);
            }
            sort($selectedOptions);
            $answers[$question->id] = $isMultiple ? $selectedOptions : $selectedOptions[0];
        }
        $maxScore = $record->quiz->questions->sum('points');
        $score = $record->quiz->questions->sum(function (QuizQuestion $question) use ($answers, $record): float {
            if ($question->question_type === 'short_answer') {
                return 0.0;
            }
            $submitted = $answers[$question->id] ?? null;
            $expected = $question->question_type === 'multiple_select'
                ? ($question->correct_options ?? [])
                : [$question->correct_option];
            sort($expected);
            $selected = is_array($submitted) ? $submitted : (is_int($submitted) ? [$submitted] : []);

            return $selected === $expected
                ? (float) $question->points
                : ($selected === [] ? 0.0 : -((float) $question->points * (float) $record->quiz->negative_marking_rate));
        });
        $score = max(0, round($score, 2));
        $timeLimitExpired = $record->quiz->time_limit_minutes !== null
            && now()->gt($record->started_at->addMinutes($record->quiz->time_limit_minutes));
        $scheduleExpired = $record->quiz->closes_at !== null && now()->gte($record->quiz->closes_at);
        if ($timeLimitExpired || $scheduleExpired) {
            $record->update(['answers' => [], 'manual_grades' => [], 'review_status' => 'not_required', 'score' => 0, 'max_score' => $maxScore, 'submitted_at' => now()]);
            abort(409, $scheduleExpired
                ? 'The quiz availability window closed; this attempt was submitted with no answers.'
                : 'The quiz time limit expired; this attempt was submitted with no answers.');
        }
        $needsReview = $record->quiz->questions->contains(fn (QuizQuestion $question): bool => $question->question_type === 'short_answer' && filled($answers[$question->id] ?? null));
        $record->update(['answers' => $answers, 'manual_grades' => [], 'review_status' => $needsReview ? 'pending' : 'not_required', 'score' => $score, 'max_score' => $maxScore, 'submitted_at' => now()]);

        return response()->json(['data' => $this->attemptData($record->refresh())]);
    }

    public function gradeShortAnswer(Request $request, int $organization, int $quiz, int $attempt, int $question): JsonResponse
    {
        $record = CourseQuiz::query()->where('organization_id', $organization)->with('course')->findOrFail($quiz);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $record->course);
        $questionRecord = $record->questions()->where('question_type', 'short_answer')->findOrFail($question);
        $attemptRecord = QuizAttempt::query()->where('organization_id', $organization)->where('quiz_id', $record->id)->findOrFail($attempt);
        abort_if($attemptRecord->submitted_at === null, 409, 'Only submitted attempts can be reviewed.');
        $answers = $attemptRecord->answers ?? [];
        abort_unless(is_string($answers[$questionRecord->id] ?? null) && filled($answers[$questionRecord->id]), 409, 'This attempt has no written response for this question.');
        $data = $request->validate([
            'awarded_points' => ['required', 'integer', 'min:0', 'max:'.$questionRecord->points],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ]);
        $grades = $attemptRecord->manual_grades ?? [];
        $grades[$questionRecord->id] = [
            'awarded_points' => $data['awarded_points'],
            'feedback' => $data['feedback'] ?? null,
            'graded_at' => now()->toISOString(),
            'graded_by' => $request->user()->id,
        ];
        $pendingCount = $record->questions()->where('question_type', 'short_answer')->get()->filter(
            fn (QuizQuestion $item): bool => filled($answers[$item->id] ?? null) && ! array_key_exists($item->id, $grades),
        )->count();
        $gradedCount = count($grades);
        $status = $pendingCount > 0 ? ($gradedCount > 0 ? 'partial' : 'pending') : 'graded';
        $recordQuestions = $record->questions()->get();
        $objectiveScore = $recordQuestions->sum(function (QuizQuestion $item) use ($answers, $record): float {
            if ($item->question_type === 'short_answer') {
                return 0.0;
            }
            $submitted = $answers[$item->id] ?? null;
            $expected = $item->question_type === 'multiple_select' ? ($item->correct_options ?? []) : [$item->correct_option];
            sort($expected);
            $selected = is_array($submitted) ? $submitted : (is_int($submitted) ? [$submitted] : []);
            sort($selected);

            return $selected === $expected
                ? (float) $item->points
                : ($selected === [] ? 0.0 : -((float) $item->points * (float) $record->negative_marking_rate));
        });
        $manualScore = collect($grades)->sum(fn (array $grade): int => (int) ($grade['awarded_points'] ?? 0));
        $attemptRecord->update([
            'manual_grades' => $grades,
            'review_status' => $status,
            'score' => max(0, round($objectiveScore + $manualScore, 2)),
            'max_score' => $recordQuestions->sum('points'),
        ]);

        return response()->json(['data' => $this->attemptData($attemptRecord->refresh())]);
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
        $query = $record->attempts()->whereNotNull('submitted_at')->with(['student:id,name,admission_number', 'quiz']);
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
            'opens_at' => $quiz->opens_at?->toISOString(),
            'closes_at' => $quiz->closes_at?->toISOString(),
            'negative_marking_rate' => $quiz->negative_marking_rate,
            'pass_percent' => $quiz->pass_percent,
            'negative_marking_rate' => $quiz->negative_marking_rate,
            'pass_percent' => $quiz->pass_percent,
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
            'question_type' => $question->question_type,
            'difficulty' => $question->difficulty,
            'points' => $question->points,
            'sort_order' => $question->sort_order,
            ...($includeAnswer ? [
                'question_bank_question_id' => $question->question_bank_question_id,
                'correct_option' => $question->correct_option,
                'correct_options' => $question->correct_options ?? [$question->correct_option],
            ] : []),
        ];
    }

    private function bankQuestionData(CourseQuestionBankQuestion $question): array
    {
        return [
            'id' => $question->id,
            'prompt' => $question->prompt,
            'options' => $question->options,
            'question_type' => $question->question_type,
            'difficulty' => $question->difficulty,
            'correct_options' => $question->correct_options,
            'points' => $question->points,
            'sort_order' => $question->sort_order,
        ];
    }

    private function validatedQuestionData(Request $request): array
    {
        $questionType = $request->input('question_type', 'single_choice');
        $rules = [
            'prompt' => ['required', 'string', 'min:3', 'max:2000'],
            'question_type' => ['sometimes', Rule::in(['single_choice', 'multiple_select', 'short_answer'])],
            'difficulty' => ['sometimes', Rule::in(['easy', 'medium', 'hard'])],
            'points' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
        if ($questionType === 'short_answer') {
            $rules['options'] = ['sometimes', 'array', 'max:0'];
            $rules['correct_option'] = ['prohibited'];
            $rules['correct_options'] = ['prohibited'];
        } else {
            $rules['options'] = ['required', 'array', 'min:2', 'max:6'];
            $rules['options.*'] = ['required', 'string', 'min:1', 'max:500', 'distinct'];
            $rules['correct_option'] = ['sometimes', 'integer', 'min:0', 'max:5'];
            $rules['correct_options'] = ['sometimes', 'array', 'min:2', 'max:6'];
            $rules['correct_options.*'] = ['required', 'integer', 'min:0', 'max:5', 'distinct'];
        }
        $data = $request->validate($rules);
        if ($questionType === 'short_answer') {
            return [
                'prompt' => $data['prompt'],
                'options' => [],
                'question_type' => $questionType,
                'difficulty' => $data['difficulty'] ?? 'medium',
                'correct_option' => 0,
                'correct_options' => [],
                'points' => $data['points'] ?? 1,
                ...(array_key_exists('sort_order', $data) ? ['sort_order' => $data['sort_order']] : []),
            ];
        }
        $questionType = $data['question_type'] ?? 'single_choice';
        $correctOptions = $questionType === 'multiple_select'
            ? ($data['correct_options'] ?? [])
            : [$data['correct_option'] ?? null];
        if ($questionType === 'multiple_select' && array_key_exists('correct_option', $data)) {
            throw ValidationException::withMessages(['correct_option' => ['Use correct_options for a multiple-select question.']]);
        }
        if ($questionType === 'single_choice' && (array_key_exists('correct_options', $data) || ! is_int($data['correct_option'] ?? null))) {
            throw ValidationException::withMessages(['correct_option' => ['Select exactly one correct answer.']]);
        }
        if (count($correctOptions) < ($questionType === 'multiple_select' ? 2 : 1)
            || collect($correctOptions)->contains(fn ($option) => ! is_int($option) || $option >= count($data['options']))) {
            throw ValidationException::withMessages(['correct_options' => ['Select valid answer choices from the submitted options.']]);
        }

        return [
            'prompt' => $data['prompt'],
            'options' => $data['options'],
            'question_type' => $questionType,
            'difficulty' => $data['difficulty'] ?? 'medium',
            'correct_option' => $correctOptions[0],
            'correct_options' => array_values($correctOptions),
            'points' => $data['points'] ?? 1,
            ...(array_key_exists('sort_order', $data) ? ['sort_order' => $data['sort_order']] : []),
        ];
    }

    private function assertSchedule(mixed $opensAt, mixed $closesAt): void
    {
        if ($opensAt !== null && $closesAt !== null && Carbon::parse($opensAt)->gte(Carbon::parse($closesAt))) {
            throw ValidationException::withMessages(['closes_at' => ['The closing time must be after the opening time.']]);
        }
    }

    private function attemptData(QuizAttempt $attempt): array
    {
        $attempt->loadMissing('quiz');
        $manualGrades = collect($attempt->manual_grades ?? [])->map(fn (array $grade): array => [
            'awarded_points' => $grade['awarded_points'],
            'feedback' => $grade['feedback'] ?? null,
            'graded_at' => $grade['graded_at'] ?? null,
        ]);

        return [
            'id' => $attempt->id,
            'attempt_number' => $attempt->attempt_number,
            'answers' => $attempt->answers,
            'manual_grades' => $manualGrades,
            'review_status' => $attempt->review_status,
            'score' => $attempt->score,
            'max_score' => $attempt->max_score,
            'result_outcome' => $this->resultOutcome($attempt),
            'started_at' => $attempt->started_at?->toISOString(),
            'submitted_at' => $attempt->submitted_at?->toISOString(),
        ];
    }

    private function resultOutcome(QuizAttempt $attempt): string
    {
        $passPercent = $attempt->quiz->pass_percent;
        if ($passPercent === null) {
            return 'not_set';
        }
        if (in_array($attempt->review_status, ['pending', 'partial'], true)) {
            return 'pending_review';
        }
        if ((float) $attempt->max_score <= 0) {
            return 'failed';
        }

        return ((float) $attempt->score / (float) $attempt->max_score * 100) >= $passPercent ? 'passed' : 'failed';
    }
}
