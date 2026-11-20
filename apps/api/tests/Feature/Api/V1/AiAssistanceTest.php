<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\AiAssistanceUsage;
use App\Models\Course;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiAssistanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_ai_is_disabled_by_default_and_disabled_requests_do_not_contact_a_provider(): void
    {
        [$admin, $organization, $course] = $this->workspace();
        Sanctum::actingAs($admin);
        $base = "/api/v1/organizations/{$organization->id}";

        $this->getJson("{$base}/ai/config")->assertOk()->assertJsonPath('enabled', false)
            ->assertJsonPath('daily_limit', 10)->assertJsonPath('usage.requests_today', 0);
        $this->postJson("{$base}/ai/courses/{$course->id}/lesson-plan", [
            'topic' => 'Water cycle', 'objectives' => 'Explain how water moves through the environment.', 'language' => 'English',
        ])->assertServiceUnavailable();

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_assistance_usages', 0);
    }

    public function test_public_ai_hosts_are_rejected_to_keep_inference_private_and_local(): void
    {
        [$admin, $organization, $course] = $this->workspace();
        Config::set('services.ai.driver', 'ollama');
        Config::set('services.ai.model', 'educator-model');
        Config::set('services.ai.base_url', 'https://api.example.com');
        Sanctum::actingAs($admin);
        $base = "/api/v1/organizations/{$organization->id}";

        $this->getJson("{$base}/ai/config")->assertOk()->assertJsonPath('enabled', false);
        $this->postJson("{$base}/ai/courses/{$course->id}/lesson-plan", [
            'topic' => 'Water cycle', 'objectives' => 'Explain how water moves through the environment.', 'language' => 'English',
        ])->assertServiceUnavailable();
        Config::set('services.ai.base_url', 'http://169.254.169.254');
        $this->getJson("{$base}/ai/config")->assertOk()->assertJsonPath('enabled', false);
        $this->postJson("{$base}/ai/courses/{$course->id}/lesson-plan", [
            'topic' => 'Water cycle', 'objectives' => 'Explain how water moves through the environment.', 'language' => 'English',
        ])->assertServiceUnavailable();
        Http::assertNothingSent();
    }

    public function test_teacher_can_generate_private_lesson_plan_draft_and_usage_does_not_store_prompt_text(): void
    {
        [$teacherUser, $organization, $course] = $this->workspace(withTeacher: true);
        $this->enableAi();
        Http::fake(['127.0.0.1:11434/api/generate' => Http::response([
            'model' => 'test-educator-model',
            'response' => "Learning goals\nExplain evaporation and condensation.",
            'prompt_eval_count' => 123,
            'eval_count' => 34,
        ])]);
        Sanctum::actingAs($teacherUser);
        $base = "/api/v1/organizations/{$organization->id}";

        $this->postJson("{$base}/ai/courses/{$course->id}/lesson-plan", [
            'topic' => 'The water cycle', 'grade_level' => 'Grade 6',
            'objectives' => 'Explain how water changes state during the water cycle.', 'language' => 'English',
        ])->assertOk()->assertJsonPath('data.draft', "Learning goals\nExplain evaporation and condensation.")
            ->assertJsonPath('data.model', 'test-educator-model')->assertJsonPath('data.usage.prompt_tokens', 123)
            ->assertJsonPath('data.review_required', true);

        Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:11434/api/generate'
            && $request['model'] === 'educator-model'
            && str_contains($request['prompt'], 'The water cycle')
            && str_contains($request['system'], 'Do not include student names')
            && ! str_contains($request['prompt'], 'Learner')
            && $request['stream'] === false);
        $this->assertDatabaseHas('ai_assistance_usages', [
            'organization_id' => $organization->id, 'user_id' => $teacherUser->id,
            'provider' => 'ollama', 'model' => 'test-educator-model', 'prompt_tokens' => 123,
            'completion_tokens' => 34, 'status' => 'succeeded',
        ]);
        $usage = AiAssistanceUsage::query()->firstOrFail()->getAttributes();
        $this->assertArrayNotHasKey('prompt', $usage);
        $this->assertArrayNotHasKey('draft', $usage);
        $this->getJson("{$base}/ai/config")->assertOk()->assertJsonPath('enabled', true)
            ->assertJsonPath('usage.requests_today', 1)->assertJsonPath('usage.prompt_tokens_30_days', 123);
    }

    public function test_teacher_cannot_use_ai_on_another_teachers_course_and_students_are_denied(): void
    {
        [$teacherUser, $studentUser, $organization, $course] = $this->workspace(withTeacher: true, withStudent: true);
        $course->update(['teacher_id' => null]);
        $this->enableAi();
        Sanctum::actingAs($teacherUser);
        $base = "/api/v1/organizations/{$organization->id}";
        $this->postJson("{$base}/ai/courses/{$course->id}/lesson-plan", [
            'topic' => 'Forces and motion', 'objectives' => 'Describe how balanced forces affect movement.', 'language' => 'English',
        ])->assertNotFound();

        Sanctum::actingAs($studentUser);
        $this->postJson("{$base}/ai/courses/{$course->id}/lesson-plan", [
            'topic' => 'Forces and motion', 'objectives' => 'Describe how balanced forces affect movement.', 'language' => 'English',
        ])->assertForbidden();
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_assistance_usages', 0);
    }

    public function test_daily_limit_blocks_extra_requests_and_provider_errors_are_sanitized_and_recorded(): void
    {
        [$admin, $organization, $course] = $this->workspace();
        Config::set('services.ai.daily_user_limit', 1);
        $this->enableAi();
        Http::fake(['*' => Http::sequence()
            ->push(['model' => 'educator-model', 'response' => 'A teacher-reviewed draft.', 'prompt_eval_count' => 20, 'eval_count' => 50])
            ->push(['error' => 'provider-secret-must-not-leak'], 500)]);
        Sanctum::actingAs($admin);
        $base = "/api/v1/organizations/{$organization->id}";
        $payload = ['topic' => 'Fractions', 'objectives' => 'Compare fractions with unlike denominators.', 'language' => 'Hindi'];

        $this->postJson("{$base}/ai/courses/{$course->id}/lesson-plan", $payload)->assertOk();
        $this->postJson("{$base}/ai/courses/{$course->id}/lesson-plan", $payload)->assertTooManyRequests();
        $this->assertDatabaseCount('ai_assistance_usages', 1);

        RateLimiter::clear('ai-lesson-plan:'.$organization->id.':'.$admin->id.':'.now()->toDateString());
        $this->postJson("{$base}/ai/courses/{$course->id}/lesson-plan", $payload)->assertServiceUnavailable()
            ->assertDontSee('provider-secret-must-not-leak');
        $this->assertDatabaseHas('ai_assistance_usages', ['user_id' => $admin->id, 'status' => 'failed']);
        $this->assertDatabaseCount('ai_assistance_usages', 2);
    }

    public function test_usage_metadata_is_purged_after_ninety_days(): void
    {
        [$admin, $organization] = $this->workspace();
        $usage = AiAssistanceUsage::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'provider' => 'ollama',
            'model' => 'educator-model',
            'status' => 'succeeded',
            'created_at' => now()->subDays(91),
        ]);

        $this->artisan('ai:purge-usage')->expectsOutput('Purged 1 AI usage records older than 90 days.')->assertExitCode(0);
        $this->assertDatabaseMissing('ai_assistance_usages', ['id' => $usage->id]);
    }

    private function enableAi(): void
    {
        Config::set('services.ai.driver', 'ollama');
        Config::set('services.ai.base_url', 'http://127.0.0.1:11434');
        Config::set('services.ai.model', 'educator-model');
    }

    private function workspace(bool $withTeacher = false, bool $withStudent = false): array
    {
        $admin = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Northstar Academy', 'slug' => 'northstar-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $admin->id,
            'role' => OrganizationRole::Admin, 'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
        $course = Course::query()->create([
            'organization_id' => $organization->id, 'created_by' => $admin->id,
            'title' => 'Physics', 'level' => 'Grade 6', 'status' => 'draft',
        ]);
        $users = [$admin, $organization, $course];

        if ($withTeacher) {
            $teacherUser = User::factory()->create();
            $teacher = Teacher::query()->create([
                'organization_id' => $organization->id, 'user_id' => $teacherUser->id,
                'name' => 'Course Teacher', 'email' => $teacherUser->email, 'status' => 'active',
            ]);
            OrganizationMembership::query()->create([
                'organization_id' => $organization->id, 'user_id' => $teacherUser->id,
                'role' => OrganizationRole::Teacher, 'status' => MembershipStatus::Active, 'accepted_at' => now(),
            ]);
            $course->update(['teacher_id' => $teacher->id]);
            $users = [$teacherUser, $organization, $course];
        }

        if ($withStudent) {
            $studentUser = User::factory()->create();
            OrganizationMembership::query()->create([
                'organization_id' => $organization->id, 'user_id' => $studentUser->id,
                'role' => OrganizationRole::Student, 'status' => MembershipStatus::Active, 'accepted_at' => now(),
            ]);
            $users = $withTeacher
                ? [$users[0], $studentUser, $organization, $course]
                : [$admin, $studentUser, $organization, $course];
        }

        return $users;
    }
}
