<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseQuiz;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\QuizAttempt;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseQuizTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_attempt_is_scored_server_side_without_exposing_answer_key(): void
    {
        [$admin, $teacherUser, $studentUser, $organization, $course] = $this->classroom();
        $base = "/api/v1/organizations/{$organization->id}";
        Sanctum::actingAs($teacherUser);
        $quizId = $this->postJson("{$base}/courses/{$course->id}/quizzes", [
            'title' => 'Water cycle check', 'instructions' => 'Choose the best answers.', 'max_attempts' => 1,
        ])->assertCreated()->json('data.id');
        $first = $this->postJson("{$base}/quizzes/{$quizId}/questions", [
            'prompt' => 'What powers evaporation?', 'options' => ['Sunlight', 'Moonlight', 'Tides'], 'correct_option' => 0, 'points' => 2,
        ])->assertCreated()->json('data.id');
        $this->postJson("{$base}/quizzes/{$quizId}/questions", [
            'prompt' => 'Where does condensation occur?', 'options' => ['In clouds', 'In soil'], 'correct_option' => 0, 'points' => 3,
        ])->assertCreated();
        $this->patchJson("{$base}/quizzes/{$quizId}", ['status' => 'published'])->assertOk();

        Sanctum::actingAs($studentUser);
        $this->getJson("{$base}/courses/{$course->id}/quizzes")->assertOk()
            ->assertJsonMissingPath('data.0.questions.0.correct_option');
        $started = $this->postJson("{$base}/quizzes/{$quizId}/attempts")->assertCreated()
            ->assertJsonMissingPath('data.questions.0.correct_option')->assertJsonPath('data.questions.0.id', $first);
        $attemptId = $started->json('data.attempt.id');
        $result = $this->patchJson("{$base}/quizzes/{$quizId}/attempts/{$attemptId}", [
            'answers' => [
                ['question_id' => $first, 'selected_option' => 0],
                ['question_id' => $started->json('data.questions.1.id'), 'selected_option' => 1],
            ],
        ])->assertOk()->assertJsonPath('data.score', '2.00')->assertJsonPath('data.max_score', '5.00');
        $this->assertDatabaseHas('quiz_attempts', [
            'id' => $attemptId, 'student_id' => Student::query()->where('user_id', $studentUser->id)->value('id'), 'score' => '2.00',
        ]);
        $this->postJson("{$base}/quizzes/{$quizId}/attempts")->assertStatus(409);
        $this->getJson("{$base}/quizzes/{$quizId}/attempts")->assertOk()
            ->assertJsonPath('data.0.attempt_number', 1)
            ->assertJsonPath('data.0.score', '2.00')
            ->assertJsonPath('data.0.student', null);

        Sanctum::actingAs($teacherUser);
        $this->getJson("{$base}/quizzes/{$quizId}/attempts")->assertOk()
            ->assertJsonPath('data.0.student.name', 'Learner One')->assertJsonPath('data.0.score', '2.00');

        Sanctum::actingAs($admin);
        $this->getJson("{$base}/quizzes/{$quizId}/attempts")->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_quiz_publication_requires_a_question_and_students_must_be_enrolled(): void
    {
        [, $teacherUser, , $organization, $course] = $this->classroom();
        $base = "/api/v1/organizations/{$organization->id}";
        Sanctum::actingAs($teacherUser);
        $quizId = $this->postJson("{$base}/courses/{$course->id}/quizzes", ['title' => 'Empty quiz'])->assertCreated()->json('data.id');
        $this->patchJson("{$base}/quizzes/{$quizId}", ['status' => 'published'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $readyQuiz = $this->postJson("{$base}/courses/{$course->id}/quizzes", ['title' => 'Enrollment check'])->assertCreated()->json('data.id');
        $this->postJson("{$base}/quizzes/{$readyQuiz}/questions", [
            'prompt' => 'Choose one', 'options' => ['One', 'Two'], 'correct_option' => 0,
        ])->assertCreated();
        $this->patchJson("{$base}/quizzes/{$readyQuiz}", ['status' => 'published'])->assertOk();
        $otherUser = User::factory()->create(['email' => 'unlinked-learner@example.com']);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $otherUser->id,
            'role' => OrganizationRole::Student, 'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
        Student::query()->create([
            'organization_id' => $organization->id, 'user_id' => $otherUser->id,
            'name' => 'Unlinked Learner', 'admission_number' => 'S-900', 'status' => 'active',
        ]);
        Sanctum::actingAs($otherUser);
        $this->postJson("{$base}/quizzes/{$readyQuiz}/attempts")->assertNotFound();
    }

    public function test_multiple_select_questions_are_scored_only_for_the_exact_correct_set(): void
    {
        [, $teacherUser, $studentUser, $organization, $course] = $this->classroom();
        $base = "/api/v1/organizations/{$organization->id}";
        Sanctum::actingAs($teacherUser);
        $quizId = $this->postJson("{$base}/courses/{$course->id}/quizzes", [
            'title' => 'Multiple-select check', 'max_attempts' => 2,
        ])->assertCreated()->json('data.id');
        $this->postJson("{$base}/quizzes/{$quizId}/questions", [
            'prompt' => 'Select both renewable sources.',
            'options' => ['Solar', 'Coal', 'Wind'],
            'question_type' => 'multiple_select',
            'difficulty' => 'hard',
            'correct_options' => [0, 2],
            'points' => 3,
        ])->assertCreated()->assertJsonPath('data.question_type', 'multiple_select')
            ->assertJsonPath('data.difficulty', 'hard')->assertJsonPath('data.correct_options', [0, 2]);
        $this->postJson("{$base}/quizzes/{$quizId}/questions", [
            'prompt' => 'Reject duplicate answer keys.',
            'options' => ['One', 'Two', 'Three'],
            'question_type' => 'multiple_select',
            'correct_options' => [1, 1],
        ])->assertUnprocessable()->assertJsonValidationErrors('correct_options.1');
        $this->patchJson("{$base}/quizzes/{$quizId}", ['status' => 'published'])->assertOk();

        Sanctum::actingAs($studentUser);
        $this->getJson("{$base}/courses/{$course->id}/quizzes")->assertOk()
            ->assertJsonPath('data.0.questions.0.question_type', 'multiple_select')
            ->assertJsonPath('data.0.questions.0.difficulty', 'hard')
            ->assertJsonMissingPath('data.0.questions.0.correct_options');
        $firstAttempt = $this->postJson("{$base}/quizzes/{$quizId}/attempts")->assertCreated();
        $attemptId = $firstAttempt->json('data.attempt.id');
        $questionId = $firstAttempt->json('data.questions.0.id');
        $this->patchJson("{$base}/quizzes/{$quizId}/attempts/{$attemptId}", [
            'answers' => [['question_id' => $questionId, 'selected_options' => [2, 0]]],
        ])->assertOk()->assertJsonPath('data.score', '3.00')
            ->assertJsonPath("data.answers.{$questionId}", [0, 2]);

        $secondAttempt = $this->postJson("{$base}/quizzes/{$quizId}/attempts")->assertCreated();
        $this->patchJson("{$base}/quizzes/{$quizId}/attempts/{$secondAttempt->json('data.attempt.id')}", [
            'answers' => [['question_id' => $questionId, 'selected_options' => [0]]],
        ])->assertOk()->assertJsonPath('data.score', '0.00');
    }

    public function test_short_answer_responses_wait_for_course_teacher_review_and_update_the_final_score(): void
    {
        [, $teacherUser, $studentUser, $organization, $course] = $this->classroom();
        $base = "/api/v1/organizations/{$organization->id}";
        Sanctum::actingAs($teacherUser);
        $quizId = $this->postJson("{$base}/courses/{$course->id}/quizzes", ['title' => 'Written response quiz', 'pass_percent' => 70])
            ->assertCreated()->json('data.id');
        $objectiveQuestion = $this->postJson("{$base}/quizzes/{$quizId}/questions", [
            'prompt' => 'Which planet is known as the red planet?', 'options' => ['Mars', 'Venus'], 'correct_option' => 0, 'points' => 2,
        ])->assertCreated()->json('data.id');
        $writtenQuestion = $this->postJson("{$base}/quizzes/{$quizId}/questions", [
            'prompt' => 'Explain one effect of the greenhouse effect.', 'question_type' => 'short_answer', 'points' => 3,
        ])->assertCreated()->assertJsonPath('data.question_type', 'short_answer')->json('data.id');
        $this->patchJson("{$base}/quizzes/{$quizId}", ['status' => 'published'])->assertOk();

        Sanctum::actingAs($studentUser);
        $started = $this->postJson("{$base}/quizzes/{$quizId}/attempts")->assertCreated()
            ->assertJsonPath('data.questions.1.options', [])->assertJsonMissingPath('data.questions.1.correct_option');
        $attemptId = $started->json('data.attempt.id');
        $this->patchJson("{$base}/quizzes/{$quizId}/attempts/{$attemptId}", [
            'answers' => [
                ['question_id' => $objectiveQuestion, 'selected_option' => 0],
                ['question_id' => $writtenQuestion, 'answer_text' => 'It traps heat in the atmosphere.'],
            ],
        ])->assertOk()->assertJsonPath('data.score', '2.00')->assertJsonPath('data.max_score', '5.00')
            ->assertJsonPath('data.review_status', 'pending')->assertJsonPath('data.result_outcome', 'pending_review');
        $this->patchJson("{$base}/quizzes/{$quizId}/attempts/{$attemptId}/questions/{$writtenQuestion}/grade", [
            'awarded_points' => 3, 'feedback' => 'Clear and accurate explanation.',
        ])->assertForbidden();

        Sanctum::actingAs($teacherUser);
        $this->patchJson("{$base}/quizzes/{$quizId}/attempts/{$attemptId}/questions/{$writtenQuestion}/grade", [
            'awarded_points' => 4,
        ])->assertUnprocessable()->assertJsonValidationErrors('awarded_points');
        $this->patchJson("{$base}/quizzes/{$quizId}/attempts/{$attemptId}/questions/{$writtenQuestion}/grade", [
            'awarded_points' => 3, 'feedback' => 'Clear and accurate explanation.',
        ])->assertOk()->assertJsonPath('data.score', '5.00')->assertJsonPath('data.review_status', 'graded')
            ->assertJsonPath('data.result_outcome', 'passed')
            ->assertJsonPath("data.manual_grades.{$writtenQuestion}.feedback", 'Clear and accurate explanation.');
        $this->getJson("{$base}/quizzes/{$quizId}/attempts")
            ->assertOk()->assertJsonPath("data.0.answers.{$writtenQuestion}", 'It traps heat in the atmosphere.')
            ->assertJsonPath('data.0.review_status', 'graded');

        Sanctum::actingAs($studentUser);
        $this->getJson("{$base}/quizzes/{$quizId}/attempts")
            ->assertOk()->assertJsonPath("data.0.manual_grades.{$writtenQuestion}.feedback", 'Clear and accurate explanation.');
    }

    public function test_quiz_negative_marking_and_pass_threshold_are_scored_on_the_server(): void
    {
        [, $teacherUser, $studentUser, $organization, $course] = $this->classroom();
        $base = "/api/v1/organizations/{$organization->id}";
        Sanctum::actingAs($teacherUser);
        $quizId = $this->postJson("{$base}/courses/{$course->id}/quizzes", [
            'title' => 'Board scoring practice', 'max_attempts' => 2, 'negative_marking_rate' => 0.25, 'pass_percent' => 50,
        ])->assertCreated()->assertJsonPath('data.negative_marking_rate', '0.25')->assertJsonPath('data.pass_percent', 50)->json('data.id');
        $questionIds = [];
        foreach ([['Mars', 'Venus'], ['Saturn', 'Mercury']] as $index => $options) {
            $questionIds[] = $this->postJson("{$base}/quizzes/{$quizId}/questions", [
                'prompt' => 'Select the correct astronomy answer '.($index + 1), 'options' => $options, 'correct_option' => 0, 'points' => 4,
            ])->assertCreated()->json('data.id');
        }
        $this->patchJson("{$base}/quizzes/{$quizId}", ['status' => 'published'])->assertOk();

        Sanctum::actingAs($studentUser);
        $attempt = $this->postJson("{$base}/quizzes/{$quizId}/attempts")->assertCreated();
        $this->patchJson("{$base}/quizzes/{$quizId}/attempts/{$attempt->json('data.attempt.id')}", [
            'answers' => [
                ['question_id' => $questionIds[0], 'selected_option' => 0],
                ['question_id' => $questionIds[1], 'selected_option' => 1],
            ],
        ])->assertOk()->assertJsonPath('data.score', '3.00')->assertJsonPath('data.max_score', '8.00')
            ->assertJsonPath('data.result_outcome', 'failed');
        $perfect = $this->postJson("{$base}/quizzes/{$quizId}/attempts")->assertCreated();
        $this->patchJson("{$base}/quizzes/{$quizId}/attempts/{$perfect->json('data.attempt.id')}", [
            'answers' => [
                ['question_id' => $questionIds[0], 'selected_option' => 0],
                ['question_id' => $questionIds[1], 'selected_option' => 0],
            ],
        ])->assertOk()->assertJsonPath('data.score', '8.00')->assertJsonPath('data.result_outcome', 'passed');
    }

    public function test_scheduled_quiz_blocks_early_starts_and_closes_active_attempts_at_deadline(): void
    {
        [, $teacherUser, $studentUser, $organization, $course] = $this->classroom();
        $base = "/api/v1/organizations/{$organization->id}";
        $opensAt = now()->addMinutes(10);
        $closesAt = now()->addMinutes(30);
        Sanctum::actingAs($teacherUser);
        $this->postJson("{$base}/courses/{$course->id}/quizzes", [
            'title' => 'Invalid schedule', 'opens_at' => $closesAt->toISOString(), 'closes_at' => $closesAt->toISOString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('closes_at');
        $quizId = $this->postJson("{$base}/courses/{$course->id}/quizzes", [
            'title' => 'Scheduled exam', 'opens_at' => $opensAt->toISOString(), 'closes_at' => $closesAt->toISOString(),
        ])->assertCreated()->json('data.id');
        $this->postJson("{$base}/quizzes/{$quizId}/questions", [
            'prompt' => 'Choose a primary color.', 'options' => ['Red', 'Green', 'Blue'], 'correct_option' => 0,
        ])->assertCreated();
        $this->patchJson("{$base}/quizzes/{$quizId}", ['status' => 'published'])->assertOk();

        Sanctum::actingAs($studentUser);
        $this->postJson("{$base}/quizzes/{$quizId}/attempts")->assertConflict();
        $this->travelTo($opensAt->copy()->addMinute());
        $attempt = $this->postJson("{$base}/quizzes/{$quizId}/attempts")->assertCreated();
        $this->travelTo($closesAt);
        $this->patchJson("{$base}/quizzes/{$quizId}/attempts/{$attempt->json('data.attempt.id')}", [
            'answers' => [['question_id' => $attempt->json('data.questions.0.id'), 'selected_option' => 0]],
        ])->assertConflict()->assertJsonPath('message', 'The quiz availability window closed; this attempt was submitted with no answers.');
        $this->assertDatabaseHas('quiz_attempts', [
            'id' => $attempt->json('data.attempt.id'), 'score' => '0.00',
        ]);
        $this->assertNotNull(QuizAttempt::query()->findOrFail($attempt->json('data.attempt.id'))->submitted_at);
        $this->postJson("{$base}/quizzes/{$quizId}/attempts")->assertConflict();
    }

    public function test_course_question_bank_questions_can_be_reused_only_within_their_course(): void
    {
        [, $teacherUser, , $organization, $course] = $this->classroom();
        $base = "/api/v1/organizations/{$organization->id}";
        Sanctum::actingAs($teacherUser);
        $bank = $this->postJson("{$base}/courses/{$course->id}/question-banks", [
            'title' => 'Earth systems', 'description' => 'Reusable course questions.',
        ])->assertCreated()->json('data.id');
        $bankQuestion = $this->postJson("{$base}/question-banks/{$bank}/questions", [
            'prompt' => 'Select both renewable sources.',
            'options' => ['Solar', 'Coal', 'Wind'],
            'question_type' => 'multiple_select',
            'difficulty' => 'medium',
            'correct_options' => [0, 2],
            'points' => 4,
        ])->assertCreated()->assertJsonPath('data.question_type', 'multiple_select')->json('data.id');
        $this->getJson("{$base}/courses/{$course->id}/question-banks")
            ->assertOk()->assertJsonPath('data.0.id', $bank)->assertJsonPath('data.0.questions_count', 1);
        $this->getJson("{$base}/question-banks/{$bank}/questions?q=renewable")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.correct_options', [0, 2]);

        $quizIds = [];
        foreach (['First quiz', 'Second quiz'] as $title) {
            $quizIds[] = $this->postJson("{$base}/courses/{$course->id}/quizzes", ['title' => $title])
                ->assertCreated()->json('data.id');
        }
        foreach ($quizIds as $quizId) {
            $this->postJson("{$base}/quizzes/{$quizId}/questions/from-bank", ['question_bank_question_id' => $bankQuestion])
                ->assertCreated()->assertJsonPath('data.correct_options', [0, 2]);
        }
        $this->postJson("{$base}/quizzes/{$quizIds[0]}/questions/from-bank", ['question_bank_question_id' => $bankQuestion])
            ->assertConflict();

        $otherCourse = Course::query()->create([
            'organization_id' => $organization->id, 'teacher_id' => Teacher::query()->where('user_id', $teacherUser->id)->value('id'),
            'created_by' => $teacherUser->id, 'title' => 'Other course', 'status' => 'published', 'published_at' => now(),
        ]);
        $otherQuiz = $this->postJson("{$base}/courses/{$otherCourse->id}/quizzes", ['title' => 'Other course quiz'])
            ->assertCreated()->json('data.id');
        $this->postJson("{$base}/quizzes/{$otherQuiz}/questions/from-bank", ['question_bank_question_id' => $bankQuestion])
            ->assertNotFound();
    }

    public function test_students_cannot_read_course_question_banks(): void
    {
        [, , $studentUser, $organization, $course] = $this->classroom();
        Sanctum::actingAs($studentUser);

        $this->getJson("/api/v1/organizations/{$organization->id}/courses/{$course->id}/question-banks")
            ->assertForbidden();
    }

    public function test_bank_questions_can_be_edited_and_deleted_without_changing_quiz_snapshots(): void
    {
        [, $teacherUser, , $organization, $course] = $this->classroom();
        Sanctum::actingAs($teacherUser);
        $base = "/api/v1/organizations/{$organization->id}";
        $bank = $this->postJson("{$base}/courses/{$course->id}/question-banks", ['title' => 'Unit One'])
            ->assertCreated()->json('data.id');
        $question = $this->postJson("{$base}/question-banks/{$bank}/questions", [
            'prompt' => 'Which planet is known as the red planet?',
            'options' => ['Mars', 'Venus', 'Jupiter'],
            'correct_option' => 0,
        ])->assertCreated()->json('data.id');
        $quiz = $this->postJson("{$base}/courses/{$course->id}/quizzes", ['title' => 'Planet quiz'])
            ->assertCreated()->json('data.id');
        $this->postJson("{$base}/quizzes/{$quiz}/questions/from-bank", ['question_bank_question_id' => $question])->assertCreated();

        $this->patchJson("{$base}/question-banks/{$bank}/questions/{$question}", [
            'prompt' => 'Which planet has prominent rings?',
            'options' => ['Saturn', 'Mars', 'Mercury'],
            'correct_option' => 0,
            'difficulty' => 'hard',
        ])->assertOk()->assertJsonPath('data.prompt', 'Which planet has prominent rings?');

        $this->getJson("{$base}/courses/{$course->id}/quizzes")
            ->assertOk()->assertJsonPath('data.0.questions.0.prompt', 'Which planet is known as the red planet?');
        $this->deleteJson("{$base}/question-banks/{$bank}/questions/{$question}")->assertNoContent();
        $this->getJson("{$base}/courses/{$course->id}/quizzes")
            ->assertOk()->assertJsonPath('data.0.questions.0.prompt', 'Which planet is known as the red planet?')
            ->assertJsonPath('data.0.questions.0.question_bank_question_id', null);
        $this->patchJson("{$base}/question-banks/{$bank}", ['title' => 'Unit 1 review'])
            ->assertOk()->assertJsonPath('data.title', 'Unit 1 review');
        $this->deleteJson("{$base}/question-banks/{$bank}")->assertNoContent();
        $this->getJson("{$base}/courses/{$course->id}/question-banks")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_submitted_attempt_history_is_paginated_without_cross_student_results(): void
    {
        [$admin, $teacherUser, $studentUser, $organization, $course] = $this->classroom();
        $base = "/api/v1/organizations/{$organization->id}";
        $student = Student::query()->where('user_id', $studentUser->id)->firstOrFail();
        $students = [$student];
        for ($number = 2; $number <= 5; $number++) {
            $learnerUser = User::factory()->create();
            OrganizationMembership::query()->create([
                'organization_id' => $organization->id, 'user_id' => $learnerUser->id,
                'role' => OrganizationRole::Student, 'status' => MembershipStatus::Active, 'accepted_at' => now(),
            ]);
            $learner = Student::query()->create([
                'organization_id' => $organization->id, 'user_id' => $learnerUser->id,
                'name' => "Learner {$number}", 'admission_number' => "S-10{$number}", 'status' => 'active',
            ]);
            CourseEnrollment::query()->create([
                'organization_id' => $organization->id, 'course_id' => $course->id, 'student_id' => $learner->id,
                'enrolled_by' => $admin->id, 'status' => 'active',
            ]);
            $students[] = $learner;
        }
        $quiz = CourseQuiz::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id, 'created_by' => $teacherUser->id,
            'title' => 'History pagination', 'status' => 'published', 'max_attempts' => 5,
        ]);
        foreach ($students as $studentIndex => $learner) {
            for ($number = 1; $number <= 5; $number++) {
                $submittedAt = now()->subDays(30)->addMinutes(($studentIndex * 5) + $number);
                QuizAttempt::query()->create([
                    'organization_id' => $organization->id, 'quiz_id' => $quiz->id, 'student_id' => $learner->id,
                    'attempt_number' => $number, 'answers' => [], 'score' => '1.00', 'max_score' => '1.00',
                    'started_at' => $submittedAt, 'submitted_at' => $submittedAt,
                ]);
            }
        }

        Sanctum::actingAs($teacherUser);
        $this->getJson("{$base}/quizzes/{$quiz->id}/attempts?per_page=10&page=2")
            ->assertOk()->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.attempt_number', 5)
            ->assertJsonPath('data.0.student.name', 'Learner 3')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 25);
        $this->getJson("{$base}/quizzes/{$quiz->id}/attempts?per_page=101")
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');

        Sanctum::actingAs($studentUser);
        $this->getJson("{$base}/quizzes/{$quiz->id}/attempts?per_page=2&page=3")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student', null)
            ->assertJsonPath('meta.total', 5);

        Sanctum::actingAs($admin);
        $this->getJson("{$base}/quizzes/{$quiz->id}/attempts?per_page=10&page=3")
            ->assertOk()->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.attempt_number', 5)
            ->assertJsonPath('meta.current_page', 3);
    }

    private function classroom(): array
    {
        $admin = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Northstar Academy', 'slug' => 'northstar-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
        $teacherUser = User::factory()->create(['email' => 'teacher@example.com']);
        $studentUser = User::factory()->create(['email' => 'learner@example.com']);
        foreach ([[$admin, OrganizationRole::Admin], [$teacherUser, OrganizationRole::Teacher], [$studentUser, OrganizationRole::Student]] as [$user, $role]) {
            OrganizationMembership::query()->create([
                'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role,
                'status' => MembershipStatus::Active, 'accepted_at' => now(),
            ]);
        }
        $teacher = Teacher::query()->create([
            'organization_id' => $organization->id, 'user_id' => $teacherUser->id,
            'name' => 'Teacher One', 'email' => $teacherUser->email, 'status' => 'active',
        ]);
        $student = Student::query()->create([
            'organization_id' => $organization->id, 'user_id' => $studentUser->id,
            'name' => 'Learner One', 'admission_number' => 'S-100', 'status' => 'active',
        ]);
        $course = Course::query()->create([
            'organization_id' => $organization->id, 'teacher_id' => $teacher->id, 'created_by' => $admin->id,
            'title' => 'Earth Systems', 'status' => 'published', 'published_at' => now(),
        ]);
        CourseEnrollment::query()->create([
            'organization_id' => $organization->id, 'course_id' => $course->id, 'student_id' => $student->id,
            'enrolled_by' => $admin->id, 'status' => 'active',
        ]);

        return [$admin, $teacherUser, $studentUser, $organization, $course];
    }
}
