<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\AcademicExam;
use App\Models\AcademicExamPaper;
use App\Models\AcademicExamResult;
use App\Models\AcademicSection;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AcademicExamController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless(in_array($membership->role, [OrganizationRole::Admin, OrganizationRole::Management, OrganizationRole::IndependentEducator, OrganizationRole::Teacher], true), 403);
        $teacher = null;
        $query = AcademicExam::query()->where('organization_id', $organization)->with(['academicSession:id,name', 'papers.section:id,name,academic_class_id', 'papers.section.academicClass:id,name', 'papers.subject:id,name,code'])
            ->orderByDesc('starts_on')->orderByDesc('id');
        if ($membership->role === OrganizationRole::Teacher) {
            $teacher = Teacher::query()->where('organization_id', $organization)->where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail();
            $query->whereHas('papers', fn ($papers) => $papers->whereExists(fn ($assignments) => $assignments->selectRaw('1')->from('teaching_assignments')
                ->whereColumn('teaching_assignments.academic_session_id', 'academic_exams.academic_session_id')
                ->whereColumn('teaching_assignments.academic_section_id', 'academic_exam_papers.academic_section_id')
                ->whereColumn('teaching_assignments.subject_id', 'academic_exam_papers.subject_id')
                ->where('teaching_assignments.teacher_id', $teacher->id)));
        }
        $exams = $query->limit(100)->get();
        $assignments = $teacher ? TeachingAssignment::query()->where('organization_id', $organization)->where('teacher_id', $teacher->id)
            ->get(['academic_session_id', 'academic_section_id', 'subject_id'])
            ->mapWithKeys(fn (TeachingAssignment $assignment): array => [implode(':', [$assignment->academic_session_id, $assignment->academic_section_id, $assignment->subject_id]) => true]) : collect();

        return response()->json(['data' => $exams->map(fn (AcademicExam $exam): array => [
            'id' => $exam->id, 'title' => $exam->title, 'status' => $exam->status,
            'starts_on' => $exam->starts_on->toDateString(), 'ends_on' => $exam->ends_on->toDateString(),
            'session_id' => $exam->academic_session_id, 'session' => $exam->academicSession->name, 'published_at' => $exam->published_at?->toISOString(),
            'papers' => $exam->papers->filter(fn (AcademicExamPaper $paper): bool => ! $teacher || $assignments->has(implode(':', [$exam->academic_session_id, $paper->academic_section_id, $paper->subject_id])))->map(fn (AcademicExamPaper $paper): array => [
                'id' => $paper->id, 'section_id' => $paper->academic_section_id,
                'section' => $paper->section->academicClass->name.' · '.$paper->section->name,
                'subject_id' => $paper->subject_id, 'subject' => $paper->subject->name,
                'exam_on' => $paper->exam_on->toDateString(), 'max_marks' => $paper->max_marks,
                'results_recorded' => $paper->results()->count(),
            ])->values(),
        ])->values()]);
    }

    public function store(Request $request, int $organization): JsonResponse
    {
        $this->manager($request, $organization, true);
        $data = $request->validate([
            'academic_session_id' => ['required', 'integer', Rule::exists('academic_sessions', 'id')->where('organization_id', $organization)->where('is_active', true)],
            'title' => ['required', 'string', 'min:2', 'max:160'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ]);
        $session = DB::table('academic_sessions')->where('organization_id', $organization)->where('id', $data['academic_session_id'])->first();
        if (($session->starts_on && $data['starts_on'] < $session->starts_on) || ($session->ends_on && $data['ends_on'] > $session->ends_on)) {
            throw ValidationException::withMessages(['starts_on' => ['Exam dates must fall within the academic session.']]);
        }
        $exam = AcademicExam::query()->create([...$data, 'organization_id' => $organization, 'created_by' => $request->user()->id]);

        return response()->json(['data' => ['id' => $exam->id, 'title' => $exam->title, 'status' => $exam->status]], 201);
    }

    public function storePaper(Request $request, int $organization, int $exam): JsonResponse
    {
        $this->manager($request, $organization, true);
        $examRecord = AcademicExam::query()->where('organization_id', $organization)->where('status', 'draft')->findOrFail($exam);
        $data = $request->validate([
            'academic_section_id' => ['required', 'integer', Rule::exists('academic_sections', 'id')->where('organization_id', $organization)->where('is_active', true)],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->where('organization_id', $organization)->where('is_active', true)],
            'exam_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$examRecord->starts_on->toDateString(), 'before_or_equal:'.$examRecord->ends_on->toDateString()],
            'max_marks' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
        ]);
        $paper = DB::transaction(function () use ($data, $organization, $exam): AcademicExamPaper {
            $lockedExam = AcademicExam::query()->where('organization_id', $organization)->lockForUpdate()->findOrFail($exam);
            abort_unless($lockedExam->status === 'draft', 409, 'Published exam papers are locked.');
            $section = AcademicSection::query()->where('organization_id', $organization)->where('is_active', true)->lockForUpdate()->findOrFail($data['academic_section_id']);
            abort_unless((int) $section->academic_session_id === (int) $lockedExam->academic_session_id, 422, 'The section must belong to the examination session.');
            abort_if($lockedExam->papers()->where('academic_section_id', $section->id)->where('subject_id', $data['subject_id'])->exists(), 409, 'This subject already has a paper for the selected section.');

            return AcademicExamPaper::query()->create([...$data, 'organization_id' => $organization, 'academic_exam_id' => $lockedExam->id]);
        });

        return response()->json(['data' => ['id' => $paper->id, 'exam_on' => $paper->exam_on->toDateString(), 'max_marks' => $paper->max_marks]], 201);
    }

    public function results(Request $request, int $organization, int $paper): JsonResponse
    {
        $teacher = $this->authorizePaper($request, $organization, $paper);
        $paperRecord = AcademicExamPaper::query()->where('organization_id', $organization)->with('exam')->findOrFail($paper);
        $students = Student::query()->where('organization_id', $organization)->where('academic_section_id', $paperRecord->academic_section_id)
            ->where('academic_session_id', $paperRecord->exam->academic_session_id)->where('status', 'active')->orderBy('name')->get();
        $results = $paperRecord->results()->get()->keyBy('student_id');

        return response()->json(['data' => $students->map(fn (Student $student): array => [
            'student_id' => $student->id, 'name' => $student->name, 'admission_number' => $student->admission_number,
            'outcome' => $results->get($student->id)?->outcome,
            'score' => $results->get($student->id)?->score,
            'remarks' => $results->get($student->id)?->remarks,
        ])->values(), 'context' => ['exam' => $paperRecord->exam->title, 'max_marks' => $paperRecord->max_marks, 'exam_on' => $paperRecord->exam_on->toDateString(), 'teacher_scoped' => $teacher !== null]]);
    }

    public function saveResults(Request $request, int $organization, int $paper): JsonResponse
    {
        $this->authorizePaper($request, $organization, $paper, true);
        $data = $request->validate([
            'records' => ['required', 'array', 'min:1', 'max:200'],
            'records.*.student_id' => ['required', 'integer', 'distinct', Rule::exists('students', 'id')->where('organization_id', $organization)],
            'records.*.outcome' => ['required', Rule::in(['graded', 'absent', 'excused'])],
            'records.*.score' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'records.*.remarks' => ['nullable', 'string', 'max:500'],
        ]);
        $paperRecord = AcademicExamPaper::query()->where('organization_id', $organization)->with('exam')->findOrFail($paper);
        abort_unless($paperRecord->exam->status === 'draft', 409, 'Published exam results are locked.');
        $records = collect($data['records']);
        foreach ($records as $index => $record) {
            if (($record['outcome'] === 'graded') !== isset($record['score'])) {
                throw ValidationException::withMessages(["records.{$index}.score" => ['Enter a score for graded results and leave score blank for absent or excused students.']]);
            }
            if (isset($record['score']) && ((int) round((float) $record['score'] * 100) > (int) round((float) $paperRecord->max_marks * 100))) {
                throw ValidationException::withMessages(["records.{$index}.score" => ['The score cannot exceed the paper maximum.']]);
            }
        }
        $studentIds = $records->pluck('student_id')->map(fn ($id): int => (int) $id)->all();
        $eligible = Student::query()->where('organization_id', $organization)->where('academic_section_id', $paperRecord->academic_section_id)
            ->where('academic_session_id', $paperRecord->exam->academic_session_id)->where('status', 'active')->whereIn('id', $studentIds)->count();
        abort_unless($eligible === count($studentIds), 422, 'Every student must be active in this paper’s section and academic session.');

        DB::transaction(function () use ($records, $paperRecord, $organization, $request): void {
            $exam = AcademicExam::query()->whereKey($paperRecord->academic_exam_id)->lockForUpdate()->firstOrFail();
            abort_unless($exam->status === 'draft', 409, 'Published exam results are locked.');
            $now = now();
            $rows = $records->map(fn (array $record): array => [
                'organization_id' => $organization, 'academic_exam_paper_id' => $paperRecord->id,
                'student_id' => $record['student_id'], 'recorded_by' => $request->user()->id,
                'outcome' => $record['outcome'], 'score' => $record['score'] ?? null,
                'remarks' => isset($record['remarks']) ? trim($record['remarks']) : null,
                'created_at' => $now, 'updated_at' => $now,
            ])->all();
            AcademicExamResult::query()->upsert($rows, ['academic_exam_paper_id', 'student_id'], ['recorded_by', 'outcome', 'score', 'remarks', 'updated_at']);
        });

        return response()->json(['message' => 'Examination results saved.', 'saved' => count($records)]);
    }

    public function publish(Request $request, int $organization, int $exam): JsonResponse
    {
        $this->manager($request, $organization, true);
        $examRecord = DB::transaction(function () use ($organization, $exam): AcademicExam {
            $examRecord = AcademicExam::query()->where('organization_id', $organization)->with('papers')->lockForUpdate()->findOrFail($exam);
            abort_unless($examRecord->status === 'draft', 409, 'This examination has already been published.');
            abort_if($examRecord->papers->isEmpty(), 422, 'Add at least one exam paper before publishing.');
            foreach ($examRecord->papers as $paper) {
                $expected = Student::query()->where('organization_id', $organization)->where('academic_section_id', $paper->academic_section_id)
                    ->where('academic_session_id', $examRecord->academic_session_id)->where('status', 'active')->count();
                $recorded = $paper->results()->whereHas('student', fn ($students) => $students
                    ->where('organization_id', $organization)->where('academic_section_id', $paper->academic_section_id)
                    ->where('academic_session_id', $examRecord->academic_session_id)->where('status', 'active'))->count();
                abort_if($expected === 0 || $recorded !== $expected, 422, 'Record a result or absence for every active student on every paper before publishing.');
            }
            $examRecord->update(['status' => 'published', 'published_at' => now()]);

            return $examRecord->refresh();
        });

        return response()->json(['data' => ['id' => $examRecord->id, 'status' => 'published', 'published_at' => $examRecord->published_at?->toISOString()]]);
    }

    private function authorizePaper(Request $request, int $organization, int $paper, bool $write = false): ?Teacher
    {
        $membership = $this->access->membership($request, $organization);
        if (in_array($membership->role, [OrganizationRole::Admin, OrganizationRole::Management, OrganizationRole::IndependentEducator], true)) {
            if ($write) {
                $this->access->membership($request, $organization, true);
            }

            return null;
        }
        abort_unless($membership->role === OrganizationRole::Teacher, 403);
        $teacher = Teacher::query()->where('organization_id', $organization)->where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail();
        $record = AcademicExamPaper::query()->where('organization_id', $organization)->with('exam')->findOrFail($paper);
        abort_unless($record->exam->status === 'draft' || ! $write, 409, 'Published exam results are locked.');
        $sessionId = $record->exam->academic_session_id;
        abort_unless(TeachingAssignment::query()->where('organization_id', $organization)->where('teacher_id', $teacher->id)
            ->where('academic_section_id', $record->academic_section_id)->where('subject_id', $record->subject_id)
            ->where('academic_session_id', $sessionId)->exists(), 403);

        return $teacher;
    }

    private function manager(Request $request, int $organization, bool $write = false): void
    {
        $membership = $this->access->membership($request, $organization, $write);
        abort_unless(in_array($membership->role, [OrganizationRole::Admin, OrganizationRole::Management, OrganizationRole::IndependentEducator], true), 403);
    }
}
