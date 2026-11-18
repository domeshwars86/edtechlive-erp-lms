<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\Course;
use App\Models\CourseAsset;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CourseAssetController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization, int $course): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        $courseRecord = Course::query()->where('organization_id', $organization)->findOrFail($course);
        $student = $this->authorizeCourseRead($request, $membership->role, $organization, $courseRecord);
        $assets = CourseAsset::query()->where('organization_id', $organization)->where('course_id', $courseRecord->id)
            ->when($student, fn ($query) => $query->where('is_published', true))->orderBy('title')->get();

        return response()->json(['data' => $assets->map(fn (CourseAsset $asset): array => $this->assetData($asset, $organization))->values()]);
    }

    public function store(Request $request, int $organization, int $course): JsonResponse
    {
        $courseRecord = Course::query()->where('organization_id', $organization)->findOrFail($course);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $courseRecord);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:180'],
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,ppt,pptx,png,jpg,jpeg,webp', 'max:20480'],
        ]);
        $file = $data['file'];
        $extension = $file->guessExtension() ?: 'bin';
        $path = $file->storeAs(
            "organizations/{$organization}/courses/{$courseRecord->id}",
            Str::uuid().'.'.$extension,
            'private',
        );
        $originalName = preg_replace('/[^\pL\pN._() -]/u', '_', basename($file->getClientOriginalName())) ?: 'study-material';
        $asset = CourseAsset::query()->create([
            'organization_id' => $organization,
            'course_id' => $courseRecord->id,
            'uploaded_by' => $request->user()->id,
            'title' => $data['title'],
            'original_name' => Str::limit($originalName, 255, ''),
            'storage_path' => $path,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'is_published' => false,
        ]);

        return response()->json(['data' => $this->assetData($asset, $organization)], 201);
    }

    public function update(Request $request, int $organization, int $record): JsonResponse
    {
        $asset = CourseAsset::query()->where('organization_id', $organization)->with('course')->findOrFail($record);
        $membership = $this->access->membership($request, $organization);
        $this->assertCourseEditor($request, $membership->role, $organization, $asset->course);
        $data = $request->validate(['is_published' => ['required', 'boolean']]);
        if ($data['is_published'] && $asset->course->status !== 'published') {
            throw ValidationException::withMessages(['is_published' => ['Publish the course before sharing materials with students.']]);
        }
        $asset->update(['is_published' => $data['is_published']]);

        return response()->json(['data' => $this->assetData($asset->refresh(), $organization)]);
    }

    public function download(Request $request, int $organization, int $record): StreamedResponse
    {
        $asset = CourseAsset::query()->where('organization_id', $organization)->with('course')->findOrFail($record);
        $membership = $this->access->membership($request, $organization);
        $student = $this->authorizeCourseRead($request, $membership->role, $organization, $asset->course);
        abort_if($student && ! $asset->is_published, 404);
        abort_unless(Storage::disk('private')->exists($asset->storage_path), 404);

        return Storage::disk('private')->download($asset->storage_path, $asset->original_name, [
            'Content-Type' => $asset->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeCourseRead(Request $request, OrganizationRole $role, int $organization, Course $course): ?Student
    {
        if ($role === OrganizationRole::Student) {
            $student = Student::query()->where('organization_id', $organization)->where('user_id', $request->user()->id)
                ->where('status', 'active')->firstOrFail();
            abort_unless($course->status === 'published' && $course->enrollments()->where('student_id', $student->id)->where('status', 'active')->exists(), 404);

            return $student;
        }
        $this->assertCourseEditor($request, $role, $organization, $course);

        return null;
    }

    private function assertCourseEditor(Request $request, OrganizationRole $role, int $organization, Course $course): void
    {
        if ($role === OrganizationRole::Teacher) {
            $teacher = Teacher::query()->where('organization_id', $organization)->where('user_id', $request->user()->id)
                ->where('status', 'active')->firstOrFail();
            abort_unless($course->teacher_id === $teacher->id, 404);

            return;
        }
        abort_unless(in_array($role, [OrganizationRole::Admin, OrganizationRole::Management, OrganizationRole::IndependentEducator], true), 403);
        $this->access->membership($request, $organization, true);
    }

    private function assetData(CourseAsset $asset, int $organization): array
    {
        return [
            'id' => $asset->id,
            'title' => $asset->title,
            'filename' => $asset->original_name,
            'mime_type' => $asset->mime_type,
            'size_bytes' => $asset->size_bytes,
            'is_published' => $asset->is_published,
            'download_url' => "/api/v1/organizations/{$organization}/assets/{$asset->id}/download",
        ];
    }
}
