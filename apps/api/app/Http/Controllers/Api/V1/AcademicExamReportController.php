<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\Teacher;
use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AcademicExamReportController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless(in_array($membership->role, [OrganizationRole::Admin, OrganizationRole::Management, OrganizationRole::IndependentEducator, OrganizationRole::Teacher], true), 403);
        $filters = $request->validate([
            'session_id' => ['sometimes', 'integer', Rule::exists('academic_sessions', 'id')->where('organization_id', $organization)],
        ]);
        $query = DB::table('academic_exam_results as results')
            ->join('academic_exam_papers as papers', 'papers.id', '=', 'results.academic_exam_paper_id')
            ->join('academic_exams as exams', 'exams.id', '=', 'papers.academic_exam_id')
            ->join('academic_sessions as sessions', 'sessions.id', '=', 'exams.academic_session_id')
            ->join('academic_sections as sections', 'sections.id', '=', 'papers.academic_section_id')
            ->join('academic_classes as classes', 'classes.id', '=', 'sections.academic_class_id')
            ->join('subjects', 'subjects.id', '=', 'papers.subject_id')
            ->where('results.organization_id', $organization)->where('papers.organization_id', $organization)
            ->where('exams.organization_id', $organization)->where('exams.status', 'published');
        if (isset($filters['session_id'])) {
            $query->where('exams.academic_session_id', $filters['session_id']);
        }
        if ($membership->role === OrganizationRole::Teacher) {
            $teacher = Teacher::query()->where('organization_id', $organization)->where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail();
            $query->whereExists(fn ($assignments) => $assignments->selectRaw('1')->from('teaching_assignments')
                ->whereColumn('teaching_assignments.organization_id', 'papers.organization_id')
                ->whereColumn('teaching_assignments.academic_session_id', 'exams.academic_session_id')
                ->whereColumn('teaching_assignments.academic_section_id', 'papers.academic_section_id')
                ->whereColumn('teaching_assignments.subject_id', 'papers.subject_id')
                ->where('teaching_assignments.teacher_id', $teacher->id));
        }

        $papers = $query->select([
            'papers.id as paper_id', 'exams.id as exam_id', 'exams.title as exam_title',
            'sessions.name as session_name', 'classes.name as class_name', 'sections.name as section_name',
            'subjects.name as subject_name', 'papers.exam_on', 'papers.max_marks',
        ])->selectRaw('COUNT(results.id) as result_count')
            ->selectRaw("SUM(CASE WHEN results.outcome = 'graded' THEN 1 ELSE 0 END) as graded_count")
            ->selectRaw("SUM(CASE WHEN results.outcome = 'absent' THEN 1 ELSE 0 END) as absent_count")
            ->selectRaw("SUM(CASE WHEN results.outcome = 'excused' THEN 1 ELSE 0 END) as excused_count")
            ->selectRaw("AVG(CASE WHEN results.outcome = 'graded' THEN results.score ELSE NULL END) as average_score")
            ->groupBy('papers.id', 'exams.id', 'exams.title', 'sessions.name', 'classes.name', 'sections.name', 'subjects.name', 'papers.exam_on', 'papers.max_marks')
            ->orderByDesc('exams.starts_on')->orderBy('classes.name')->orderBy('sections.name')->orderBy('subjects.name')
            ->limit(500)->get();

        return response()->json(['data' => $papers->map(fn ($paper): array => [
            'paper_id' => (int) $paper->paper_id,
            'exam_id' => (int) $paper->exam_id,
            'exam' => $paper->exam_title,
            'session' => $paper->session_name,
            'class_section' => $paper->class_name.' · '.$paper->section_name,
            'subject' => $paper->subject_name,
            'exam_on' => $paper->exam_on,
            'max_marks' => number_format((float) $paper->max_marks, 2, '.', ''),
            'result_count' => (int) $paper->result_count,
            'graded_count' => (int) $paper->graded_count,
            'absent_count' => (int) $paper->absent_count,
            'excused_count' => (int) $paper->excused_count,
            'average_score' => $paper->average_score === null ? null : number_format((float) $paper->average_score, 2, '.', ''),
            'average_percent' => $paper->average_score === null ? null : round((float) $paper->average_score / (float) $paper->max_marks * 100, 1),
        ])->values()]);
    }
}
