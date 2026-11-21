<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\AiAssistanceUsage;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseLesson;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\LocalAiAssistant;
use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use RuntimeException;

class AiAssistanceController
{
    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly LocalAiAssistant $assistant,
    ) {}

    public function config(Request $request, int $organization): JsonResponse
    {
        $this->access->membership($request, $organization);
        $userUsage = AiAssistanceUsage::query()->where('organization_id', $organization)
            ->where('user_id', $request->user()->id)->where('created_at', '>=', now()->subDays(30));
        $limit = min(max((int) config('services.ai.daily_user_limit', 10), 1), 100);

        return response()->json([
            'enabled' => $this->assistant->enabled(),
            'provider' => $this->assistant->enabled() ? 'ollama' : null,
            'model' => $this->assistant->enabled() ? config('services.ai.model') : null,
            'daily_limit' => $limit,
            'usage' => [
                'requests_30_days' => (clone $userUsage)->count(),
                'prompt_tokens_30_days' => (int) (clone $userUsage)->sum('prompt_tokens'),
                'completion_tokens_30_days' => (int) (clone $userUsage)->sum('completion_tokens'),
                'requests_today' => (clone $userUsage)->where('created_at', '>=', now()->startOfDay())->count(),
            ],
        ]);
    }

    public function lessonPlan(Request $request, int $organization, int $course): JsonResponse
    {
        $record = $this->editableCourse($request, $organization, $course);
        $data = $request->validate([
            'topic' => ['required', 'string', 'min:3', 'max:180'],
            'grade_level' => ['nullable', 'string', 'max:80'],
            'objectives' => ['required', 'string', 'min:10', 'max:1000'],
            'language' => ['required', Rule::in(['English', 'Hindi'])],
        ]);

        return $this->makeDraft(
            $request,
            $organization,
            fn (): array => $this->assistant->draftLessonPlan($record, $data),
        );
    }

    public function questionDraft(Request $request, int $organization, int $course): JsonResponse
    {
        $record = $this->editableCourse($request, $organization, $course);
        $data = $request->validate([
            'topic' => ['required', 'string', 'min:3', 'max:180'],
            'grade_level' => ['nullable', 'string', 'max:80'],
            'objectives' => ['required', 'string', 'min:10', 'max:1000'],
            'language' => ['required', Rule::in(['English', 'Hindi'])],
            'question_count' => ['required', 'integer', 'min:3', 'max:10'],
            'difficulty' => ['required', Rule::in(['easy', 'medium', 'hard'])],
        ]);

        return $this->makeDraft(
            $request,
            $organization,
            fn (): array => $this->assistant->draftQuestions($record, $data),
        );
    }

    public function studentQuestion(Request $request, int $organization, int $course): JsonResponse
    {
        if (! $this->assistant->enabled()) {
            abort(503, 'AI study help is disabled until a private local provider and model are configured.');
        }

        $membership = $this->access->membership($request, $organization);
        abort_unless($membership->role === OrganizationRole::Student, 403);
        $student = Student::query()->where('organization_id', $organization)
            ->where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail();
        $courseRecord = Course::query()->where('organization_id', $organization)
            ->where('status', 'published')->findOrFail($course);
        abort_unless(CourseEnrollment::query()->where('organization_id', $organization)->where('course_id', $courseRecord->id)
            ->where('student_id', $student->id)->where('status', 'active')->exists(), 404);

        $data = $request->validate([
            'question' => ['required', 'string', 'min:5', 'max:1000'],
            'language' => ['required', Rule::in(['English', 'Hindi'])],
            'lesson_id' => ['nullable', 'integer'],
        ]);
        $lessonQuery = CourseLesson::query()->where('organization_id', $organization)
            ->where('course_id', $courseRecord->id)->where('status', 'published')
            ->whereNotNull('content')->where('content', '!=', '')->orderBy('sort_order')->orderBy('id');
        if (isset($data['lesson_id'])) {
            $lessonQuery->whereKey($data['lesson_id']);
        }
        $materials = $lessonQuery->limit(4)->get(['title', 'content'])->map(fn (CourseLesson $lesson): array => [
            'title' => $lesson->title,
            'content' => mb_substr((string) $lesson->content, 0, 2500),
        ])->all();

        if ($materials === []) {
            return response()->json(['message' => 'No published lesson notes are available for study help yet. Ask your teacher to add lesson content.'], 422);
        }

        return $this->makeDraft(
            $request,
            $organization,
            fn (): array => $this->assistant->answerStudentQuestion($courseRecord, $materials, $data),
            false,
            'answer',
        );
    }

    private function editableCourse(Request $request, int $organization, int $course): Course
    {
        if (! $this->assistant->enabled()) {
            abort(503, 'Educator AI drafts are disabled until a private local provider and model are configured.');
        }

        $record = Course::query()->where('organization_id', $organization)->with('subject:id,name')->findOrFail($course);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $record);

        return $record;
    }

    private function makeDraft(Request $request, int $organization, \Closure $generate, bool $reviewRequired = true, string $resultKey = 'draft'): JsonResponse
    {
        $limit = min(max((int) config('services.ai.daily_user_limit', 10), 1), 100);
        $usageKey = 'ai-assistant:'.$organization.':'.$request->user()->id.':'.now()->toDateString();
        abort_if(RateLimiter::tooManyAttempts($usageKey, $limit), 429, 'Your daily AI draft limit has been reached. Try again tomorrow.');
        RateLimiter::hit($usageKey, max(60, (int) now()->diffInSeconds(now()->copy()->addDay()->startOfDay())));

        try {
            $result = $generate();
            AiAssistanceUsage::query()->create([
                'organization_id' => $organization,
                'user_id' => $request->user()->id,
                'provider' => 'ollama',
                'model' => mb_substr($result['model'], 0, 120),
                'prompt_tokens' => $result['prompt_tokens'],
                'completion_tokens' => $result['completion_tokens'],
                'status' => 'succeeded',
                'created_at' => now(),
            ]);

            return response()->json(['data' => [
                $resultKey => $result['draft'],
                'model' => $result['model'],
                'usage' => ['prompt_tokens' => $result['prompt_tokens'], 'completion_tokens' => $result['completion_tokens']],
                'review_required' => $reviewRequired,
            ]]);
        } catch (RuntimeException $exception) {
            AiAssistanceUsage::query()->create([
                'organization_id' => $organization,
                'user_id' => $request->user()->id,
                'provider' => 'ollama',
                'model' => mb_substr((string) config('services.ai.model'), 0, 120),
                'status' => 'failed',
                'created_at' => now(),
            ]);

            abort(503, $exception->getMessage());
        }
    }

    private function assertCourseEditor(Request $request, OrganizationRole $role, int $organization, Course $course): void
    {
        if ($role === OrganizationRole::Teacher) {
            $teacher = Teacher::query()->where('organization_id', $organization)
                ->where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail();
            abort_unless($course->teacher_id === $teacher->id, 404);

            return;
        }

        abort_unless(in_array($role, [OrganizationRole::Admin, OrganizationRole::Management, OrganizationRole::IndependentEducator], true), 403);
        $this->access->membership($request, $organization, true);
    }
}
