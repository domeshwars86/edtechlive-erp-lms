<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Course;
use App\Models\CourseLesson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicCourseController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:120'],
            'level' => ['sometimes', 'string', 'max:80'],
            'subject' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);

        $visible = $this->visibleCourses();
        $levels = (clone $visible)->whereNotNull('level')->select('level')->distinct()->orderBy('level')->pluck('level')->values();
        $subjects = $this->publicSubjects();
        $query = (clone $visible)->with(['organization:id,name,slug', 'subject:id,name,organization_id'])
            ->withCount(['lessons as published_lesson_count' => fn (Builder $lessons) => $lessons->where('status', 'published')]);

        if (isset($filters['q'])) {
            $term = mb_strtolower(trim($filters['q']));
            $query->where(function (Builder $courses) use ($term): void {
                $courses->whereRaw('LOWER(courses.title) LIKE ?', ['%'.$term.'%'])
                    ->orWhereRaw('LOWER(courses.description) LIKE ?', ['%'.$term.'%'])
                    ->orWhereRaw('LOWER(courses.level) LIKE ?', ['%'.$term.'%'])
                    ->orWhereHas('subject', fn (Builder $subject) => $subject->whereColumn('subjects.organization_id', 'courses.organization_id')->whereRaw('LOWER(subjects.name) LIKE ?', ['%'.$term.'%']))
                    ->orWhereHas('organization', fn (Builder $organization) => $organization->where('status', 'active')->whereRaw('LOWER(organizations.name) LIKE ?', ['%'.$term.'%']));
            });
        }
        if (isset($filters['level'])) {
            $query->whereRaw('LOWER(level) = ?', [mb_strtolower(trim($filters['level']))]);
        }
        if (isset($filters['subject'])) {
            $subject = mb_strtolower(trim($filters['subject']));
            $query->whereHas('subject', fn (Builder $record) => $record->whereColumn('subjects.organization_id', 'courses.organization_id')->whereRaw('LOWER(name) = ?', [$subject]));
        }

        $courses = $query->orderBy('title')->orderBy('id')->paginate(
            (int) ($filters['per_page'] ?? 12), ['*'], 'page', (int) ($filters['page'] ?? 1),
        );

        return response()->json([
            'data' => $courses->getCollection()->map(fn (Course $course): array => $this->courseSummary($course))->values(),
            'meta' => [
                'current_page' => $courses->currentPage(),
                'last_page' => $courses->lastPage(),
                'per_page' => $courses->perPage(),
                'total' => $courses->total(),
            ],
            'filters' => ['levels' => $levels, 'subjects' => $subjects],
        ]);
    }

    public function show(string $organizationSlug, string $courseSlug): JsonResponse
    {
        $course = $this->visibleCourses()->with([
            'organization:id,name,slug',
            'subject:id,name,organization_id',
            'lessons' => fn (HasMany $lessons) => $lessons->where('status', 'published')->orderBy('sort_order')->orderBy('id'),
        ])->withCount(['lessons as published_lesson_count' => fn (Builder $lessons) => $lessons->where('status', 'published')])
            ->whereHas('organization', fn (Builder $organization) => $organization->where('slug', $organizationSlug))
            ->where('slug', $courseSlug)->firstOrFail();

        return response()->json(['data' => [
            ...$this->courseSummary($course),
            'lessons' => $course->lessons->values()->map(fn (CourseLesson $lesson): array => [
                'title' => $lesson->title,
                'sort_order' => $lesson->sort_order,
            ]),
        ]]);
    }

    private function visibleCourses(): Builder
    {
        return Course::query()->where('courses.status', 'published')->where('courses.is_public_listing', true)
            ->whereHas('organization', fn (Builder $organization) => $organization->where('status', 'active'))
            ->where(function (Builder $courses): void {
                $courses->whereNull('courses.subject_id')
                    ->orWhereHas('subject', fn (Builder $subject) => $subject->whereColumn('subjects.organization_id', 'courses.organization_id'));
            })
            ->whereHas('lessons', fn (Builder $lessons) => $lessons->where('status', 'published'));
    }

    private function publicSubjects(): array
    {
        return DB::table('courses')
            ->join('organizations', function ($join): void {
                $join->on('organizations.id', '=', 'courses.organization_id')->where('organizations.status', '=', 'active');
            })
            ->join('subjects', function ($join): void {
                $join->on('subjects.id', '=', 'courses.subject_id')->on('subjects.organization_id', '=', 'courses.organization_id');
            })
            ->where('courses.status', 'published')->where('courses.is_public_listing', true)
            ->whereExists(fn ($lessons) => $lessons->selectRaw('1')->from('course_lessons')
                ->whereColumn('course_lessons.course_id', 'courses.id')->where('course_lessons.status', 'published'))
            ->distinct()->orderBy('subjects.name')->pluck('subjects.name')
            ->map(fn (string $name): array => ['name' => $name])->values()->all();
    }

    private function courseSummary(Course $course): array
    {
        return [
            'title' => $course->title,
            'description' => $course->description,
            'level' => $course->level,
            'slug' => $course->slug,
            'subject' => $course->subject?->name,
            'organization' => [
                'name' => $course->organization->name,
                'slug' => $course->organization->slug,
            ],
            'published_lesson_count' => (int) $course->published_lesson_count,
        ];
    }
}
