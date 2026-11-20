<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\AiAssistanceUsage;
use App\Models\Course;
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
        if (! $this->assistant->enabled()) {
            abort(503, 'Educator AI drafts are disabled until a local provider and model are configured.');
        }

        $record = Course::query()->where('organization_id', $organization)->with('subject:id,name')->findOrFail($course);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $record);

        $data = $request->validate([
            'topic' => ['required', 'string', 'min:3', 'max:180'],
            'grade_level' => ['nullable', 'string', 'max:80'],
            'objectives' => ['required', 'string', 'min:10', 'max:1000'],
            'language' => ['required', Rule::in(['English', 'Hindi'])],
        ]);

        $limit = min(max((int) config('services.ai.daily_user_limit', 10), 1), 100);
        $usageKey = 'ai-lesson-plan:'.$organization.':'.$request->user()->id.':'.now()->toDateString();
        abort_if(RateLimiter::tooManyAttempts($usageKey, $limit), 429, 'Your daily AI draft limit has been reached. Try again tomorrow.');
        RateLimiter::hit($usageKey, max(60, (int) now()->diffInSeconds(now()->copy()->addDay()->startOfDay())));

        try {
            $result = $this->assistant->draftLessonPlan($record, $data);
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
                'draft' => $result['draft'],
                'model' => $result['model'],
                'usage' => ['prompt_tokens' => $result['prompt_tokens'], 'completion_tokens' => $result['completion_tokens']],
                'review_required' => true,
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
