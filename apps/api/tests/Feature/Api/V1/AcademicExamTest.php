<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\AcademicClass;
use App\Models\AcademicSection;
use App\Models\AcademicSession;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AcademicExamTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_publish_complete_exam_results_to_linked_families(): void
    {
        $workspace = $this->workspace();
        Sanctum::actingAs($workspace['admin']);
        $base = "/api/v1/organizations/{$workspace['organization']->id}";
        [$examId, $paperId] = $this->createExam($base, $workspace);

        $this->postJson("{$base}/exams/{$examId}/publish")->assertUnprocessable();
        $this->putJson("{$base}/exam-papers/{$paperId}/results", ['records' => [
            ['student_id' => $workspace['student']->id, 'outcome' => 'graded', 'score' => '42.50', 'remarks' => 'Good work'],
            ['student_id' => $workspace['secondStudent']->id, 'outcome' => 'absent', 'score' => null],
        ]])->assertOk()->assertJsonPath('saved', 2);
        $this->postJson("{$base}/exams/{$examId}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        $this->getJson("{$base}/reports/exams?session_id={$workspace['session']->id}")->assertOk()
            ->assertJsonPath('data.0.subject', 'Mathematics')->assertJsonPath('data.0.result_count', 2)
            ->assertJsonPath('data.0.graded_count', 1)->assertJsonPath('data.0.absent_count', 1)
            ->assertJsonPath('data.0.average_score', '42.50')->assertJsonPath('data.0.average_percent', 85);
        $this->putJson("{$base}/exam-papers/{$paperId}/results", ['records' => [
            ['student_id' => $workspace['student']->id, 'outcome' => 'graded', 'score' => '43.00'],
        ]])->assertConflict();

        Sanctum::actingAs($workspace['studentUser']);
        $this->getJson("{$base}/my/student/results")->assertOk()
            ->assertJsonPath('data.exams.0.exam', 'Term 1 examinations')
            ->assertJsonPath('data.exams.0.score', '42.50')
            ->assertJsonPath('data.exams.0.subject', 'Mathematics')
            ->assertJsonMissing(['admission_number' => 'S-2']);

        Sanctum::actingAs($workspace['parentUser']);
        $this->getJson("{$base}/my/children/{$workspace['student']->id}/results")->assertOk()
            ->assertJsonPath('data.exams.0.score', '42.50');
        $this->getJson("{$base}/my/children/{$workspace['secondStudent']->id}/results")->assertNotFound();
    }

    public function test_exam_setup_and_result_validation_enforce_session_roster_and_score_caps(): void
    {
        $workspace = $this->workspace();
        Sanctum::actingAs($workspace['admin']);
        $base = "/api/v1/organizations/{$workspace['organization']->id}";
        $start = CarbonImmutable::now('Asia/Kolkata')->toDateString();
        $end = CarbonImmutable::now('Asia/Kolkata')->addDays(8)->toDateString();
        $examId = $this->postJson("{$base}/exams", [
            'academic_session_id' => $workspace['session']->id, 'title' => 'Term 1 examinations', 'starts_on' => $start, 'ends_on' => $end,
        ])->assertCreated()->json('data.id');
        $paperId = $this->postJson("{$base}/exams/{$examId}/papers", [
            'academic_section_id' => $workspace['section']->id, 'subject_id' => $workspace['subject']->id,
            'exam_on' => $start, 'max_marks' => '50.00',
        ])->assertCreated()->json('data.id');
        $this->postJson("{$base}/exams/{$examId}/papers", [
            'academic_section_id' => $workspace['section']->id, 'subject_id' => $workspace['subject']->id,
            'exam_on' => $start, 'max_marks' => '50.00',
        ])->assertConflict();
        $this->putJson("{$base}/exam-papers/{$paperId}/results", ['records' => [
            ['student_id' => $workspace['student']->id, 'outcome' => 'graded', 'score' => '51.00'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('records.0.score');
        $this->putJson("{$base}/exam-papers/{$paperId}/results", ['records' => [
            ['student_id' => $workspace['otherSectionStudent']->id, 'outcome' => 'graded', 'score' => '49.00'],
        ]])->assertUnprocessable();
    }

    public function test_board_style_paper_sections_validate_totals_and_publish_family_breakdown(): void
    {
        $workspace = $this->workspace();
        Sanctum::actingAs($workspace['admin']);
        $base = "/api/v1/organizations/{$workspace['organization']->id}";
        $start = CarbonImmutable::now('Asia/Kolkata')->toDateString();
        $examId = $this->postJson("{$base}/exams", [
            'academic_session_id' => $workspace['session']->id, 'title' => 'Board practice paper',
            'starts_on' => $start, 'ends_on' => CarbonImmutable::now('Asia/Kolkata')->addDays(8)->toDateString(),
        ])->assertCreated()->json('data.id');
        $paperId = $this->postJson("{$base}/exams/{$examId}/papers", [
            'academic_section_id' => $workspace['section']->id, 'subject_id' => $workspace['subject']->id,
            'exam_on' => $start, 'max_marks' => '50', 'paper_sections' => [
                ['title' => 'Part A · Multiple choice', 'max_marks' => '20'],
                ['title' => 'Part B · Written answers', 'max_marks' => '30'],
            ],
        ])->assertCreated()->json('data.id');

        $sections = $this->getJson("{$base}/exams")->assertOk()->json('data.0.papers.0.paper_sections');
        $this->assertCount(2, $sections);
        $this->putJson("{$base}/exam-papers/{$paperId}/results", ['records' => [
            ['student_id' => $workspace['student']->id, 'outcome' => 'graded', 'score' => '40', 'section_scores' => [
                ['section_id' => $sections[0]['id'], 'score' => '15'], ['section_id' => $sections[1]['id'], 'score' => '25'],
            ]],
        ]])->assertOk();
        $this->getJson("{$base}/exam-papers/{$paperId}/results")->assertOk()
            ->assertJsonPath('data.0.section_scores.'.$sections[0]['id'], '15.00');
        $this->putJson("{$base}/exam-papers/{$paperId}/results", ['records' => [
            ['student_id' => $workspace['secondStudent']->id, 'outcome' => 'graded', 'score' => '41', 'section_scores' => [
                ['section_id' => $sections[0]['id'], 'score' => '15'], ['section_id' => $sections[1]['id'], 'score' => '25'],
            ]],
        ]])->assertUnprocessable()->assertJsonValidationErrors('records.0.score');
        $this->putJson("{$base}/exam-papers/{$paperId}/results", ['records' => [
            ['student_id' => $workspace['secondStudent']->id, 'outcome' => 'graded', 'score' => '40', 'section_scores' => [
                ['section_id' => $sections[0]['id'], 'score' => '21'], ['section_id' => $sections[1]['id'], 'score' => '19'],
            ]],
        ]])->assertUnprocessable();
        $this->putJson("{$base}/exam-papers/{$paperId}/results", ['records' => [
            ['student_id' => $workspace['secondStudent']->id, 'outcome' => 'graded', 'score' => '40', 'section_scores' => [
                ['section_id' => $sections[0]['id'], 'score' => '15'], ['section_id' => $sections[1]['id'], 'score' => '25'],
            ]],
        ]])->assertOk();
        $this->postJson("{$base}/exams/{$examId}/publish")->assertOk();
        $this->getJson("{$base}/reports/exams?session_id={$workspace['session']->id}")->assertOk()
            ->assertJsonPath('data.0.paper_sections.0.title', 'Part A · Multiple choice')
            ->assertJsonPath('data.0.paper_sections.0.average_score', '15.00')
            ->assertJsonPath('data.0.paper_sections.0.average_percent', 75);

        Sanctum::actingAs($workspace['studentUser']);
        $this->getJson("{$base}/my/student/results")->assertOk()
            ->assertJsonPath('data.exams.0.section_scores.0.section', 'Part A · Multiple choice')
            ->assertJsonPath('data.exams.0.section_scores.0.score', '15.00');
    }

    public function test_assigned_teacher_can_record_marks_but_cannot_create_or_publish_exams(): void
    {
        $workspace = $this->workspace(withTeacher: true);
        Sanctum::actingAs($workspace['admin']);
        $base = "/api/v1/organizations/{$workspace['organization']->id}";
        [$examId, $paperId] = $this->createExam($base, $workspace);
        $science = Subject::query()->create(['organization_id' => $workspace['organization']->id, 'name' => 'Science', 'code' => 'SCI', 'is_active' => true]);
        $sciencePaperId = $this->postJson("{$base}/exams/{$examId}/papers", [
            'academic_section_id' => $workspace['section']->id, 'subject_id' => $science->id,
            'exam_on' => CarbonImmutable::now('Asia/Kolkata')->toDateString(), 'max_marks' => '50.00',
        ])->assertCreated()->json('data.id');
        $this->putJson("{$base}/exam-papers/{$sciencePaperId}/results", ['records' => [
            ['student_id' => $workspace['student']->id, 'outcome' => 'graded', 'score' => '39.00'],
            ['student_id' => $workspace['secondStudent']->id, 'outcome' => 'graded', 'score' => '44.00'],
        ]])->assertOk();

        Sanctum::actingAs($workspace['teacherUser']);
        $this->getJson("{$base}/exams")->assertOk()->assertJsonPath('data.0.papers.0.id', $paperId)->assertJsonCount(1, 'data.0.papers');
        $this->postJson("{$base}/exams", [])->assertForbidden();
        $this->putJson("{$base}/exam-papers/{$paperId}/results", ['records' => [
            ['student_id' => $workspace['student']->id, 'outcome' => 'graded', 'score' => '38.00'],
        ]])->assertOk();
        Sanctum::actingAs($workspace['admin']);
        $this->putJson("{$base}/exam-papers/{$paperId}/results", ['records' => [
            ['student_id' => $workspace['secondStudent']->id, 'outcome' => 'graded', 'score' => '41.00'],
        ]])->assertOk();
        $this->postJson("{$base}/exams/{$examId}/publish")->assertOk();
        Sanctum::actingAs($workspace['teacherUser']);
        $this->getJson("{$base}/reports/exams")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject', 'Mathematics');
        $this->getJson("{$base}/reports/exams/sections")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.class_section', 'Grade 8 · A')->assertJsonPath('data.0.paper_count', 1)
            ->assertJsonPath('data.0.subject_count', 1)->assertJsonPath('data.0.average_percent', 79);
        $this->postJson("{$base}/exams/{$examId}/publish")->assertForbidden();
    }

    public function test_section_exam_report_aggregates_normalized_subject_scores_without_student_identity(): void
    {
        $workspace = $this->workspace();
        Sanctum::actingAs($workspace['admin']);
        $base = "/api/v1/organizations/{$workspace['organization']->id}";
        [$examId, $mathPaperId] = $this->createExam($base, $workspace);
        $start = CarbonImmutable::now('Asia/Kolkata')->toDateString();
        $science = Subject::query()->create(['organization_id' => $workspace['organization']->id, 'name' => 'Science', 'code' => 'SCI', 'is_active' => true]);
        $sciencePaperId = $this->postJson("{$base}/exams/{$examId}/papers", [
            'academic_section_id' => $workspace['section']->id, 'subject_id' => $science->id,
            'exam_on' => $start, 'max_marks' => '20.00',
        ])->assertCreated()->json('data.id');
        $otherSectionPaperId = $this->postJson("{$base}/exams/{$examId}/papers", [
            'academic_section_id' => $workspace['otherSection']->id, 'subject_id' => $workspace['subject']->id,
            'exam_on' => $start, 'max_marks' => '100.00',
        ])->assertCreated()->json('data.id');

        $this->putJson("{$base}/exam-papers/{$mathPaperId}/results", ['records' => [
            ['student_id' => $workspace['student']->id, 'outcome' => 'graded', 'score' => '40.00'],
            ['student_id' => $workspace['secondStudent']->id, 'outcome' => 'absent', 'score' => null],
        ]])->assertOk();
        $this->putJson("{$base}/exam-papers/{$sciencePaperId}/results", ['records' => [
            ['student_id' => $workspace['student']->id, 'outcome' => 'graded', 'score' => '18.00'],
            ['student_id' => $workspace['secondStudent']->id, 'outcome' => 'graded', 'score' => '12.00'],
        ]])->assertOk();
        $this->putJson("{$base}/exam-papers/{$otherSectionPaperId}/results", ['records' => [
            ['student_id' => $workspace['otherSectionStudent']->id, 'outcome' => 'graded', 'score' => '50.00'],
        ]])->assertOk();
        $this->postJson("{$base}/exams/{$examId}/publish")->assertOk();

        $response = $this->getJson("{$base}/reports/exams/sections?session_id={$workspace['session']->id}")
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.exam', 'Term 1 examinations')
            ->assertJsonPath('data.0.class_section', 'Grade 8 · A')
            ->assertJsonPath('data.0.paper_count', 2)->assertJsonPath('data.0.subject_count', 2)
            ->assertJsonPath('data.0.student_count', 2)->assertJsonPath('data.0.result_count', 4)
            ->assertJsonPath('data.0.graded_count', 3)->assertJsonPath('data.0.absent_count', 1)
            ->assertJsonPath('data.0.average_percent', 76.7)
            ->assertJsonPath('data.1.class_section', 'Grade 8 · B')
            ->assertJsonPath('data.1.paper_count', 1)->assertJsonPath('data.1.average_percent', 50);
        $this->assertStringNotContainsString('Learner One', $response->getContent());
        $this->assertStringNotContainsString('S-1', $response->getContent());

        Sanctum::actingAs($workspace['studentUser']);
        $this->getJson("{$base}/reports/exams/sections")->assertForbidden();
    }

    public function test_exam_series_report_compares_normalized_averages_with_the_previous_published_exam(): void
    {
        $workspace = $this->workspace();
        Sanctum::actingAs($workspace['admin']);
        $base = "/api/v1/organizations/{$workspace['organization']->id}";
        $start = CarbonImmutable::now('Asia/Kolkata')->toDateString();
        foreach ([['Term 1', '40.00'], ['Term 2', '45.00']] as [$title, $score]) {
            $examId = $this->postJson("{$base}/exams", [
                'academic_session_id' => $workspace['session']->id, 'title' => $title, 'series_name' => '2026 annual assessments',
                'starts_on' => $start, 'ends_on' => CarbonImmutable::now('Asia/Kolkata')->addDays(8)->toDateString(),
            ])->assertCreated()->json('data.id');
            $paperId = $this->postJson("{$base}/exams/{$examId}/papers", [
                'academic_section_id' => $workspace['section']->id, 'subject_id' => $workspace['subject']->id,
                'exam_on' => $start, 'max_marks' => '50.00',
            ])->assertCreated()->json('data.id');
            $this->putJson("{$base}/exam-papers/{$paperId}/results", ['records' => [
                ['student_id' => $workspace['student']->id, 'outcome' => 'graded', 'score' => $score],
                ['student_id' => $workspace['secondStudent']->id, 'outcome' => 'absent', 'score' => null],
            ]])->assertOk();
            $this->postJson("{$base}/exams/{$examId}/publish")->assertOk();
        }

        $response = $this->getJson("{$base}/reports/exams/comparisons?session_id={$workspace['session']->id}")
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.exam', 'Term 2')
            ->assertJsonPath('data.0.series', '2026 annual assessments')
            ->assertJsonPath('data.0.average_percent', 90)
            ->assertJsonPath('data.0.previous_exam', 'Term 1')
            ->assertJsonPath('data.0.change_percentage_points', 10)
            ->assertJsonPath('data.1.exam', 'Term 1')
            ->assertJsonPath('data.1.average_percent', 80)
            ->assertJsonPath('data.1.previous_exam', null);
        $this->assertStringNotContainsString('Learner One', $response->getContent());
        $this->assertStringNotContainsString('S-1', $response->getContent());

        Sanctum::actingAs($workspace['studentUser']);
        $this->getJson("{$base}/reports/exams/comparisons")->assertForbidden();
    }

    private function createExam(string $base, array $workspace): array
    {
        $start = CarbonImmutable::now('Asia/Kolkata')->toDateString();
        $examId = $this->postJson("{$base}/exams", [
            'academic_session_id' => $workspace['session']->id, 'title' => 'Term 1 examinations',
            'starts_on' => $start, 'ends_on' => CarbonImmutable::now('Asia/Kolkata')->addDays(8)->toDateString(),
        ])->assertCreated()->json('data.id');
        $paperId = $this->postJson("{$base}/exams/{$examId}/papers", [
            'academic_section_id' => $workspace['section']->id, 'subject_id' => $workspace['subject']->id,
            'exam_on' => $start, 'max_marks' => '50.00',
        ])->assertCreated()->json('data.id');

        return [$examId, $paperId];
    }

    private function workspace(bool $withTeacher = false): array
    {
        $admin = User::factory()->create();
        $studentUser = User::factory()->create();
        $parentUser = User::factory()->create();
        $teacherUser = $withTeacher ? User::factory()->create() : null;
        $organization = Organization::query()->create([
            'name' => 'Northstar Academy', 'slug' => 'northstar-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
        $this->membership($organization, $admin, OrganizationRole::Admin);
        $this->membership($organization, $studentUser, OrganizationRole::Student);
        $this->membership($organization, $parentUser, OrganizationRole::Parent);
        if ($teacherUser) {
            $this->membership($organization, $teacherUser, OrganizationRole::Teacher);
        }
        $today = CarbonImmutable::now('Asia/Kolkata');
        $session = AcademicSession::query()->create([
            'organization_id' => $organization->id, 'name' => '2026–2027',
            'starts_on' => $today->subDays(10)->toDateString(), 'ends_on' => $today->addYear()->toDateString(),
            'is_current' => true, 'is_active' => true,
        ]);
        $class = AcademicClass::query()->create(['organization_id' => $organization->id, 'name' => 'Grade 8', 'is_active' => true]);
        $section = AcademicSection::query()->create([
            'organization_id' => $organization->id, 'academic_class_id' => $class->id,
            'academic_session_id' => $session->id, 'name' => 'A', 'is_active' => true,
        ]);
        $otherSection = AcademicSection::query()->create([
            'organization_id' => $organization->id, 'academic_class_id' => $class->id,
            'academic_session_id' => $session->id, 'name' => 'B', 'is_active' => true,
        ]);
        $subject = Subject::query()->create(['organization_id' => $organization->id, 'name' => 'Mathematics', 'code' => 'MATH', 'is_active' => true]);
        $student = Student::query()->create([
            'organization_id' => $organization->id, 'user_id' => $studentUser->id, 'name' => 'Learner One',
            'admission_number' => 'S-1', 'academic_class_id' => $class->id, 'academic_section_id' => $section->id,
            'academic_session_id' => $session->id, 'status' => 'active',
        ]);
        $secondStudent = Student::query()->create([
            'organization_id' => $organization->id, 'name' => 'Learner Two', 'admission_number' => 'S-2',
            'academic_class_id' => $class->id, 'academic_section_id' => $section->id,
            'academic_session_id' => $session->id, 'status' => 'active',
        ]);
        $otherSectionStudent = Student::query()->create([
            'organization_id' => $organization->id, 'name' => 'Learner Three', 'admission_number' => 'S-3',
            'academic_class_id' => $class->id, 'academic_section_id' => $otherSection->id,
            'academic_session_id' => $session->id, 'status' => 'active',
        ]);
        StudentGuardian::query()->create([
            'organization_id' => $organization->id, 'student_id' => $student->id, 'guardian_user_id' => $parentUser->id,
            'linked_by' => $admin->id, 'relationship' => 'parent', 'is_primary' => true, 'linked_at' => now(),
        ]);
        if ($teacherUser) {
            $teacher = Teacher::query()->create(['organization_id' => $organization->id, 'user_id' => $teacherUser->id, 'name' => 'Teacher One', 'status' => 'active']);
            TeachingAssignment::query()->create([
                'organization_id' => $organization->id, 'academic_session_id' => $session->id,
                'academic_section_id' => $section->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            ]);
        }

        return compact('admin', 'studentUser', 'parentUser', 'teacherUser', 'organization', 'session', 'class', 'section', 'otherSection', 'subject', 'student', 'secondStudent', 'otherSectionStudent');
    }

    private function membership(Organization $organization, User $user, OrganizationRole $role): void
    {
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role,
            'status' => MembershipStatus::Active, 'accepted_at' => now(),
        ]);
    }
}
