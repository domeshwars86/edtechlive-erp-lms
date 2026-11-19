<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\AcademicSection;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Meeting;
use App\Models\MeetingRecording;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use App\Services\OrganizationAccess;
use App\Services\OrganizationNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MeetingController
{
    public function __construct(private readonly OrganizationAccess $access, private readonly OrganizationNotifier $notifier) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        $query = Meeting::query()->where('organization_id', $organization)
            ->with(['section.academicClass:id,name', 'course:id,title', 'creator:id,name']);

        if ($this->isManager($membership->role)) {
            $this->access->membership($request, $organization, true);
        } elseif ($membership->role === OrganizationRole::Teacher) {
            $teacher = $this->teacherProfile($request, $organization);
            $sectionIds = TeachingAssignment::query()->where('organization_id', $organization)
                ->where('teacher_id', $teacher->id)->pluck('academic_section_id');
            $courseIds = Course::query()->where('organization_id', $organization)->where('teacher_id', $teacher->id)->pluck('id');
            $query->where('kind', 'class')->where(fn (Builder $meetings) => $meetings
                ->whereIn('academic_section_id', $sectionIds)->orWhereIn('course_id', $courseIds)->orWhere('created_by', $request->user()->id));
        } elseif ($membership->role === OrganizationRole::Student) {
            $student = $this->studentProfile($request, $organization);
            $courseIds = CourseEnrollment::query()->where('organization_id', $organization)->where('student_id', $student->id)
                ->where('status', 'active')->pluck('course_id');
            $query->where('kind', 'class')->where(fn (Builder $meetings) => $meetings
                ->where(fn (Builder $sectionMeetings) => $sectionMeetings->where('academic_section_id', $student->academic_section_id)
                    ->where('academic_session_id', $student->academic_session_id))
                ->orWhereIn('course_id', $courseIds));
        } elseif ($membership->role === OrganizationRole::Parent) {
            $children = StudentGuardian::query()->where('organization_id', $organization)
                ->where('guardian_user_id', $request->user()->id)
                ->whereHas('student', fn ($students) => $students->where('status', 'active'))
                ->with('student:id,academic_section_id,academic_session_id')->get()->pluck('student')->filter();
            $courseIds = CourseEnrollment::query()->where('organization_id', $organization)
                ->whereIn('student_id', $children->pluck('id'))->where('status', 'active')->pluck('course_id');
            $query->where('kind', 'class')->where(fn (Builder $meetings) => $meetings
                ->where(function (Builder $sectionMeetings) use ($children): void {
                    foreach ($children as $child) {
                        if ($child->academic_section_id && $child->academic_session_id) {
                            $sectionMeetings->orWhere(fn (Builder $scope) => $scope
                                ->where('academic_section_id', $child->academic_section_id)
                                ->where('academic_session_id', $child->academic_session_id));
                        }
                    }
                })->orWhereIn('course_id', $courseIds));
        } else {
            abort(403);
        }

        $meetings = $query->orderBy('starts_at')->orderBy('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => $meetings->getCollection()->map(fn (Meeting $meeting): array => $this->meetingData($meeting))->values(),
            'meta' => ['current_page' => $meetings->currentPage(), 'last_page' => $meetings->lastPage(), 'per_page' => $meetings->perPage(), 'total' => $meetings->total()],
        ]);
    }

    public function store(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        $teacher = null;
        if ($membership->role === OrganizationRole::Teacher) {
            $teacher = $this->teacherProfile($request, $organization);
        } else {
            $this->access->membership($request, $organization, true);
            abort_unless($this->isManager($membership->role), 403);
        }

        $data = $request->validate([
            'kind' => ['required', Rule::in(['class', 'management'])],
            'academic_section_id' => [Rule::requiredIf(fn () => $request->input('kind') === 'class' && ! $request->filled('course_id')), 'nullable', 'integer', Rule::exists('academic_sections', 'id')->where('organization_id', $organization)->where('is_active', true)],
            'academic_session_id' => [Rule::requiredIf(fn () => $request->filled('academic_section_id')), 'nullable', 'integer', Rule::exists('academic_sessions', 'id')->where('organization_id', $organization)],
            'course_id' => [Rule::requiredIf(fn () => $request->input('kind') === 'class' && ! $request->filled('academic_section_id')), 'nullable', 'integer', Rule::exists('courses', 'id')->where('organization_id', $organization)->where('status', 'published')],
            'title' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date', 'after_or_equal:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'meeting_url' => ['required', 'url:https', 'max:2048'],
        ]);
        if ($data['kind'] === 'management' && (! empty($data['academic_section_id']) || ! empty($data['course_id']) || ! empty($data['academic_session_id']))) {
            throw ValidationException::withMessages(['kind' => ['Management meetings cannot be attached to a class or course.']]);
        }
        if ($data['kind'] === 'class' && ! empty($data['academic_section_id']) && ! empty($data['course_id'])) {
            throw ValidationException::withMessages(['course_id' => ['Choose either a class section or a course for this meeting.']]);
        }
        if (! empty($data['academic_section_id'])) {
            $section = AcademicSection::query()->where('organization_id', $organization)->findOrFail($data['academic_section_id']);
            if ((int) $section->academic_session_id !== (int) $data['academic_session_id']) {
                throw ValidationException::withMessages(['academic_session_id' => ['Select the session assigned to this section.']]);
            }
            if ($teacher && ! TeachingAssignment::query()->where('organization_id', $organization)->where('teacher_id', $teacher->id)
                ->where('academic_section_id', $section->id)->where('academic_session_id', $data['academic_session_id'])->exists()) {
                abort(404);
            }
        }
        if (! empty($data['course_id']) && $teacher) {
            abort_unless(Course::query()->where('organization_id', $organization)->where('id', $data['course_id'])
                ->where('teacher_id', $teacher->id)->exists(), 404);
        }
        if ($data['kind'] === 'management' && $teacher) {
            abort(403);
        }

        $meeting = DB::transaction(function () use ($data, $organization, $request): Meeting {
            if ($data['kind'] === 'class') {
                if (! empty($data['academic_section_id'])) {
                    AcademicSection::query()->where('organization_id', $organization)->lockForUpdate()->findOrFail($data['academic_section_id']);
                    $target = ['academic_section_id' => $data['academic_section_id']];
                } else {
                    Course::query()->where('organization_id', $organization)->where('status', 'published')
                        ->lockForUpdate()->findOrFail($data['course_id']);
                    $target = ['course_id' => $data['course_id']];
                }
                $startsAt = Carbon::parse($data['starts_at']);
                $endsAt = Carbon::parse($data['ends_at']);
                $conflict = Meeting::query()->where('organization_id', $organization)->where('kind', 'class')
                    ->where('status', 'scheduled')->where($target)
                    ->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt)->exists();
                if ($conflict) {
                    throw ValidationException::withMessages(['starts_at' => ['Another class meeting is already scheduled for this group at that time.']]);
                }
            }

            return Meeting::query()->create([
                ...$data,
                'organization_id' => $organization,
                'created_by' => $request->user()->id,
                'status' => 'scheduled',
            ]);
        });
        $this->notifier->meetingScheduled($meeting, $request->user()->id);

        return response()->json(['data' => $this->meetingData($meeting->load(['section.academicClass:id,name', 'course:id,title', 'creator:id,name']))], 201);
    }

    public function update(Request $request, int $organization, int $meeting): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        $record = Meeting::query()->where('organization_id', $organization)->findOrFail($meeting);
        if ($membership->role === OrganizationRole::Teacher) {
            abort_unless($record->created_by === $request->user()->id && $record->kind === 'class', 404);
        } else {
            $this->access->membership($request, $organization, true);
            abort_unless($this->isManager($membership->role), 403);
        }
        $data = $request->validate(['status' => ['required', Rule::in(['cancelled'])]]);
        $record->update($data);

        return response()->json(['data' => $this->meetingData($record->refresh()->load(['section.academicClass:id,name', 'course:id,title', 'creator:id,name']))]);
    }

    public function recordings(Request $request, int $organization, int $meeting): JsonResponse
    {
        [$record, $learnerView, $canManage] = $this->recordingAccess($request, $organization, $meeting);
        $recordings = MeetingRecording::query()->where('organization_id', $organization)->where('meeting_id', $record->id)
            ->when($learnerView, fn ($query) => $query->where('status', 'published'))
            ->orderByDesc('created_at')->orderByDesc('id')->get();

        return response()->json(['data' => $recordings->map(fn (MeetingRecording $item): array => $this->recordingData($item, $organization, $record->id, $canManage))->values()]);
    }

    public function storeRecording(Request $request, int $organization, int $meeting): JsonResponse
    {
        [$record, $learnerView, $canManage] = $this->recordingAccess($request, $organization, $meeting);
        abort_unless(! $learnerView && $canManage, 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'file' => ['nullable', 'required_without:external_url', 'file', 'mimes:mp4,mov,webm', 'max:51200'],
            'external_url' => ['nullable', 'required_without:file', 'url:https', 'max:2048'],
            'consent_confirmed' => ['required', 'accepted'],
        ]);
        if ($request->hasFile('file') && $request->filled('external_url')) {
            throw ValidationException::withMessages(['file' => ['Add a video file or an external HTTPS link, not both.']]);
        }

        $file = $request->file('file');
        $path = $file?->storeAs(
            "organizations/{$organization}/meetings/{$record->id}/recordings",
            Str::uuid().'.'.($file->guessExtension() ?: 'video'),
            'private',
        );
        $originalName = $file
            ? (preg_replace('/[^\pL\pN._() -]/u', '_', basename($file->getClientOriginalName())) ?: 'class-recording')
            : null;
        $recording = MeetingRecording::query()->create([
            'organization_id' => $organization,
            'meeting_id' => $record->id,
            'uploaded_by' => $request->user()->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'original_name' => $originalName ? Str::limit($originalName, 255, '') : null,
            'storage_path' => $path,
            'mime_type' => $file?->getMimeType(),
            'size_bytes' => $file?->getSize(),
            'external_url' => $data['external_url'] ?? null,
            'status' => 'draft',
            'consent_confirmed_at' => now(),
            'consent_confirmed_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->recordingData($recording, $organization, $record->id, $canManage)], 201);
    }

    public function updateRecording(Request $request, int $organization, int $meeting, int $recording): JsonResponse
    {
        [$record, $learnerView, $canManage] = $this->recordingAccess($request, $organization, $meeting);
        abort_unless(! $learnerView && $canManage, 403);
        $item = MeetingRecording::query()->where('organization_id', $organization)->where('meeting_id', $record->id)->findOrFail($recording);
        $data = $request->validate(['status' => ['required', Rule::in(['draft', 'published', 'withdrawn'])]]);
        abort_if($item->status === 'withdrawn' && $data['status'] !== 'withdrawn', 409, 'A withdrawn recording cannot be republished.');
        $item->update([
            'status' => $data['status'],
            'withdrawn_at' => $data['status'] === 'withdrawn' ? now() : null,
        ]);

        return response()->json(['data' => $this->recordingData($item->refresh(), $organization, $record->id, $canManage)]);
    }

    public function downloadRecording(Request $request, int $organization, int $meeting, int $recording): StreamedResponse
    {
        [$record, $learnerView] = $this->recordingAccess($request, $organization, $meeting);
        $item = MeetingRecording::query()->where('organization_id', $organization)->where('meeting_id', $record->id)->findOrFail($recording);
        abort_if($learnerView && $item->status !== 'published', 404);
        abort_unless($item->storage_path && Storage::disk('private')->exists($item->storage_path), 404);

        return Storage::disk('private')->download($item->storage_path, $item->original_name ?: 'class-recording', [
            'Content-Type' => $item->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function downloadRecordingLink(Request $request, int $organization, int $meeting, int $recording): JsonResponse
    {
        [$record] = $this->recordingAccess($request, $organization, $meeting);
        $item = MeetingRecording::query()->where('organization_id', $organization)->where('meeting_id', $record->id)->findOrFail($recording);
        abort_unless($item->status === 'published', 404);
        abort_unless($item->storage_path && Storage::disk('private')->exists($item->storage_path), 404);
        $expiresAt = now()->addMinutes(2);
        $path = URL::temporarySignedRoute(
            'api.v1.meeting-recordings.download-signed',
            $expiresAt,
            ['organization' => $organization, 'meeting' => $record->id, 'recording' => $item->id],
            absolute: false,
        );

        return response()->json(['data' => ['path' => $path, 'expires_at' => $expiresAt->toISOString()]]);
    }

    public function signedDownloadRecording(int $organization, int $meeting, int $recording): StreamedResponse
    {
        $item = MeetingRecording::query()->where('organization_id', $organization)->where('meeting_id', $meeting)
            ->where('status', 'published')->findOrFail($recording);
        abort_unless($item->storage_path && Storage::disk('private')->exists($item->storage_path), 404);

        return Storage::disk('private')->download($item->storage_path, $item->original_name ?: 'class-recording', [
            'Content-Type' => $item->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{Meeting, bool, bool} */
    private function recordingAccess(Request $request, int $organization, int $meeting): array
    {
        $membership = $this->access->membership($request, $organization);
        $record = Meeting::query()->where('organization_id', $organization)->findOrFail($meeting);
        $learnerView = in_array($membership->role, [OrganizationRole::Student, OrganizationRole::Parent], true);
        $visible = false;
        $canManage = false;
        if ($this->isManager($membership->role)) {
            $this->access->membership($request, $organization, true);
            $visible = true;
            $canManage = true;
        } elseif ($membership->role === OrganizationRole::Teacher) {
            $teacher = $this->teacherProfile($request, $organization);
            $sectionIds = TeachingAssignment::query()->where('organization_id', $organization)->where('teacher_id', $teacher->id)->pluck('academic_section_id');
            $courseIds = Course::query()->where('organization_id', $organization)->where('teacher_id', $teacher->id)->pluck('id');
            $visible = $record->kind === 'class' && ($record->created_by === $request->user()->id
                || in_array($record->academic_section_id, $sectionIds->all()) || in_array($record->course_id, $courseIds->all()));
            $canManage = $record->created_by === $request->user()->id && $record->kind === 'class';
        } elseif ($membership->role === OrganizationRole::Student) {
            $student = $this->studentProfile($request, $organization);
            $courseIds = CourseEnrollment::query()->where('organization_id', $organization)->where('student_id', $student->id)
                ->where('status', 'active')->pluck('course_id');
            $visible = $record->kind === 'class' && (($record->academic_section_id === $student->academic_section_id
                && $record->academic_session_id === $student->academic_session_id) || $courseIds->contains($record->course_id));
        } elseif ($membership->role === OrganizationRole::Parent) {
            $children = StudentGuardian::query()->where('organization_id', $organization)->where('guardian_user_id', $request->user()->id)
                ->whereHas('student', fn ($students) => $students->where('status', 'active'))
                ->with('student:id,academic_section_id,academic_session_id')->get()->pluck('student')->filter();
            $courseIds = CourseEnrollment::query()->where('organization_id', $organization)->whereIn('student_id', $children->pluck('id'))
                ->where('status', 'active')->pluck('course_id');
            $visible = $record->kind === 'class' && ($courseIds->contains($record->course_id) || $children->contains(fn ($child) => $child->academic_section_id === $record->academic_section_id && $child->academic_session_id === $record->academic_session_id));
        }
        abort_unless($visible, 404);

        return [$record, $learnerView, $canManage];
    }

    private function recordingData(MeetingRecording $item, int $organization, int $meeting, bool $canManage): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'description' => $item->description,
            'filename' => $item->original_name,
            'mime_type' => $item->mime_type,
            'size_bytes' => $item->size_bytes,
            'external_url' => $item->external_url,
            'status' => $item->status,
            'can_manage' => $canManage,
            'consent_confirmed_at' => $item->consent_confirmed_at?->toISOString(),
            'download_url' => $item->storage_path ? "/api/v1/organizations/{$organization}/meetings/{$meeting}/recordings/{$item->id}/download" : null,
        ];
    }

    private function meetingData(Meeting $meeting): array
    {
        return [
            'id' => $meeting->id,
            'kind' => $meeting->kind,
            'title' => $meeting->title,
            'description' => $meeting->description,
            'starts_at' => $meeting->starts_at?->toISOString(),
            'ends_at' => $meeting->ends_at?->toISOString(),
            'meeting_url' => $meeting->meeting_url,
            'status' => $meeting->status,
            'section' => $meeting->section ? [
                'id' => $meeting->section->id,
                'name' => $meeting->section->name,
                'class' => $meeting->section->academicClass?->name,
            ] : null,
            'course' => $meeting->course?->only(['id', 'title']),
            'creator' => $meeting->creator?->only(['id', 'name']),
        ];
    }

    private function isManager(OrganizationRole $role): bool
    {
        return in_array($role, [OrganizationRole::Admin, OrganizationRole::Management, OrganizationRole::IndependentEducator], true);
    }

    private function teacherProfile(Request $request, int $organization): Teacher
    {
        return Teacher::query()->where('organization_id', $organization)->where('user_id', $request->user()->id)
            ->where('status', 'active')->firstOrFail();
    }

    private function studentProfile(Request $request, int $organization): Student
    {
        return Student::query()->where('organization_id', $organization)->where('user_id', $request->user()->id)
            ->where('status', 'active')->firstOrFail();
    }
}
