<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\AiAssistanceUsage;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseLesson;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Student;
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

    public function test_teacher_can_generate_validated_mcq_drafts_but_malformed_model_json_is_rejected(): void
    {
        [$teacherUser, $organization, $course] = $this->workspace(withTeacher: true);
        $this->enableAi();
        $questions = ['questions' => array_map(fn (int $index): array => [
            'question' => 'Which process changes water vapor into liquid droplets? '.$index,
            'options' => ['Condensation', 'Evaporation', 'Sublimation', 'Filtration'],
            'correct_index' => 0,
            'explanation' => 'Cooling water vapor causes condensation.',
        ], range(1, 3))];
        Http::fake(['*' => Http::sequence()
            ->push(['model' => 'educator-model', 'response' => json_encode($questions), 'prompt_eval_count' => 80, 'eval_count' => 100])
            ->push(['model' => 'educator-model', 'response' => '{"questions":[]}', 'prompt_eval_count' => 75, 'eval_count' => 20])]);
        Sanctum::actingAs($teacherUser);
        $base = "/api/v1/organizations/{$organization->id}";
        $payload = [
            'topic' => 'The water cycle', 'grade_level' => 'Grade 6',
            'objectives' => 'Explain how water changes state during the water cycle.',
            'language' => 'English', 'question_count' => 3, 'difficulty' => 'medium',
        ];

        $response = $this->postJson("{$base}/ai/courses/{$course->id}/question-drafts", $payload)
            ->assertOk()->assertJsonPath('data.model', 'educator-model')->assertJsonPath('data.review_required', true);
        $draft = json_decode($response->json('data.draft'), true);
        $this->assertCount(3, $draft['questions']);
        $this->assertSame(4, count($draft['questions'][0]['options']));
        $this->assertSame(0, $draft['questions'][0]['correct_index']);
        Http::assertSent(fn ($request): bool => $request['format'] === 'json'
            && str_contains($request['prompt'], 'Create exactly 3 original multiple-choice questions.')
            && str_contains($request['system'], 'Do not include student names'));

        $this->postJson("{$base}/ai/courses/{$course->id}/question-drafts", $payload)
            ->assertServiceUnavailable()->assertDontSee('questions');
        $this->assertDatabaseHas('ai_assistance_usages', ['user_id' => $teacherUser->id, 'status' => 'succeeded', 'completion_tokens' => 100]);
        $this->assertDatabaseHas('ai_assistance_usages', ['user_id' => $teacherUser->id, 'status' => 'failed', 'completion_tokens' => 0]);
        $this->assertDatabaseCount('ai_assistance_usages', 2);
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

    public function test_enrolled_student_gets_private_local_study_help_from_published_course_notes(): void
    {
        [$admin, $studentUser, $organization, $course] = $this->workspace(withStudent: true);
        $course->update(['status' => 'published']);
        $student = Student::query()->where('organization_id', $organization->id)->where('user_id', $studentUser->id)->firstOrFail();
        CourseEnrollment::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id, 'student_id' => $student->id,
            'enrolled_by' => $admin->id, 'status' => 'active',
        ]);
        $lesson = CourseLesson::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id, 'title' => 'Water cycle',
            'content' => 'The sun warms surface water. Evaporation turns liquid water into water vapor, which rises and cools to form clouds.',
            'status' => 'published',
        ]);
        $this->enableAi();
        Http::fake(['*' => Http::response([
            'model' => 'educator-model', 'response' => 'The sun provides heat for evaporation.',
            'prompt_eval_count' => 88, 'eval_count' => 12,
        ])]);
        Sanctum::actingAs($studentUser);
        $base = "/api/v1/organizations/{$organization->id}";

        $this->postJson("{$base}/ai/courses/{$course->id}/student-question", [
            'question' => 'How does the sun start the water cycle?', 'language' => 'English', 'lesson_id' => $lesson->id,
        ])->assertOk()->assertJsonPath('data.answer', 'The sun provides heat for evaporation.')
            ->assertJsonPath('data.review_required', false);

        Http::assertSent(fn ($request): bool => str_contains($request['prompt'], 'How does the sun start the water cycle?')
            && str_contains($request['prompt'], 'Evaporation turns liquid water into water vapor')
            && str_contains($request['system'], 'published lesson excerpts')
            && str_contains($request['system'], 'Do not provide a direct answer to an active test')
            && ! str_contains($request['prompt'], $student->name));
        $usage = AiAssistanceUsage::query()->firstOrFail()->getAttributes();
        $this->assertSame('succeeded', $usage['status']);
        $this->assertArrayNotHasKey('prompt', $usage);
        $this->assertArrayNotHasKey('draft', $usage);
    }

    public function test_student_study_help_requires_an_active_enrollment_and_published_text(): void
    {
        [, $studentUser, $organization, $course] = $this->workspace(withStudent: true);
        $course->update(['status' => 'published']);
        $this->enableAi();
        Sanctum::actingAs($studentUser);
        $base = "/api/v1/organizations/{$organization->id}";
        $payload = ['question' => 'Can you explain this topic?', 'language' => 'English'];

        $this->postJson("{$base}/ai/courses/{$course->id}/student-question", $payload)->assertNotFound();
        $student = Student::query()->where('organization_id', $organization->id)->where('user_id', $studentUser->id)->firstOrFail();
        CourseEnrollment::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id, 'student_id' => $student->id,
            'enrolled_by' => OrganizationMembership::query()->where('organization_id', $organization->id)->where('role', OrganizationRole::Admin)->value('user_id'),
            'status' => 'active',
        ]);

        $this->postJson("{$base}/ai/courses/{$course->id}/student-question", $payload)->assertUnprocessable()
            ->assertJsonPath('message', 'No published lesson notes are available for study help yet. Ask your teacher to add lesson content.');
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

        RateLimiter::clear('ai-assistant:'.$organization->id.':'.$admin->id.':'.now()->toDateString());
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
            Student::query()->create([
                'organization_id' => $organization->id, 'user_id' => $studentUser->id,
                'name' => 'Learner with private identity', 'admission_number' => 'STU-'.str()->upper(str()->random(8)),
                'email' => $studentUser->email, 'status' => 'active',
            ]);
            $users = $withTeacher
                ? [$users[0], $studentUser, $organization, $course]
                : [$admin, $studentUser, $organization, $course];
        }

        return $users;
    }
}
