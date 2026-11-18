<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\CourseQuiz;
use App\Models\QuizAttempt;
use App\Models\Teacher;
use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssessmentReportController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless(in_array($membership->role, [
            OrganizationRole::Admin,
            OrganizationRole::Management,
            OrganizationRole::IndependentEducator,
            OrganizationRole::Teacher,
        ], true), 403);

        $teacherId = null;
        if ($membership->role === OrganizationRole::Teacher) {
            $teacherId = Teacher::query()->where('organization_id', $organization)
                ->where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail()->id;
        }

        $quizzes = CourseQuiz::query()->where('organization_id', $organization)->where('status', 'published')
            ->whereHas('course', function ($query) use ($teacherId): void {
                $query->where('status', 'published');
                if ($teacherId !== null) {
                    $query->where('teacher_id', $teacherId);
                }
            })->with('course:id,title')->orderBy('course_id')->orderBy('title')
            ->get(['id', 'course_id', 'title']);
        $quizIds = $quizzes->pluck('id');
        $attemptStats = QuizAttempt::query()->where('organization_id', $organization)->whereIn('quiz_id', $quizIds)
            ->select('quiz_id')
            ->selectRaw('COUNT(*) as attempt_count')
            ->selectRaw('SUM(CASE WHEN submitted_at IS NOT NULL THEN 1 ELSE 0 END) as submitted_attempts')
            ->selectRaw('SUM(CASE WHEN submitted_at IS NULL THEN 1 ELSE 0 END) as in_progress_attempts')
            ->selectRaw('COUNT(DISTINCT CASE WHEN submitted_at IS NOT NULL THEN student_id END) as learners')
            ->selectRaw('COALESCE(SUM(CASE WHEN submitted_at IS NOT NULL THEN score ELSE 0 END), 0) as earned_points')
            ->selectRaw('COALESCE(SUM(CASE WHEN submitted_at IS NOT NULL THEN max_score ELSE 0 END), 0) as possible_points')
            ->groupBy('quiz_id')->get()->keyBy('quiz_id');

        $rows = $quizzes->map(function (CourseQuiz $quiz) use ($attemptStats): array {
            $stats = $attemptStats->get($quiz->id);
            $earned = (float) ($stats?->earned_points ?? 0);
            $possible = (float) ($stats?->possible_points ?? 0);

            return [
                'quiz_id' => $quiz->id,
                'course' => $quiz->course->title,
                'quiz' => $quiz->title,
                'submitted_attempts' => (int) ($stats?->submitted_attempts ?? 0),
                'in_progress_attempts' => (int) ($stats?->in_progress_attempts ?? 0),
                'learners' => (int) ($stats?->learners ?? 0),
                'average_score_percent' => $possible > 0 ? round($earned / $possible * 100, 1) : null,
            ];
        })->values();

        $submitted = $rows->sum('submitted_attempts');
        $inProgress = $rows->sum('in_progress_attempts');
        $attemptedLearners = $attemptStats->sum('learners');
        $scoredQuizRows = $rows->filter(fn (array $row): bool => $row['average_score_percent'] !== null && $row['submitted_attempts'] > 0);
        $weightedTotal = $attemptStats->sum(fn ($stats): float => (float) $stats->possible_points);
        $weightedEarned = $attemptStats->sum(fn ($stats): float => (float) $stats->earned_points);

        return response()->json(['data' => [
            'summary' => [
                'published_quizzes' => $quizzes->count(),
                'submitted_attempts' => $submitted,
                'in_progress_attempts' => $inProgress,
                'learner_quiz_participations' => $attemptedLearners,
                'average_score_percent' => $weightedTotal > 0 ? round($weightedEarned / $weightedTotal * 100, 1) : null,
                'quizzes_with_submissions' => $scoredQuizRows->count(),
            ],
            'quizzes' => $rows,
        ]]);
    }
}
