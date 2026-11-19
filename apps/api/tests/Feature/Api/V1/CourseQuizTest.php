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
