<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudentGuardianController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function directory(Request $request, int $organization): JsonResponse
    {
        $this->authorize($request, $organization);
        $guardians = OrganizationMembership::query()->where('organization_id', $organization)
            ->where('role', OrganizationRole::Parent)->where('status', MembershipStatus::Active)
            ->whereHas('user', fn ($query) => $query->whereNotNull('email_verified_at'))
            ->with('user:id,name,email')->get()->pluck('user')->filter()->unique('id')->values()
            ->map(fn ($user): array => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]);

        return response()->json(['data' => $guardians]);
    }

    public function index(Request $request, int $organization, int $student): JsonResponse
    {
        $this->authorize($request, $organization);
        Student::query()->where('organization_id', $organization)->findOrFail($student);
        $guardians = StudentGuardian::query()->where('organization_id', $organization)->where('student_id', $student)
            ->with('guardian:id,name,email')->orderByDesc('is_primary')->orderBy('id')->get();

        return response()->json(['data' => $guardians->map(fn (StudentGuardian $link): array => [
            'guardian_user_id' => $link->guardian_user_id,
            'name' => $link->guardian?->name,
            'email' => $link->guardian?->email,
            'relationship' => $link->relationship,
            'is_primary' => $link->is_primary,
            'linked_at' => $link->linked_at?->toISOString(),
        ])]);
    }

    public function store(Request $request, int $organization, int $student): JsonResponse
    {
        $this->authorize($request, $organization);
        $studentRecord = Student::query()->where('organization_id', $organization)->where('status', 'active')->findOrFail($student);
        $data = $request->validate([
            'guardian_user_id' => ['required', 'integer', Rule::exists('organization_memberships', 'user_id')
                ->where('organization_id', $organization)->where('role', OrganizationRole::Parent->value)
                ->where('status', MembershipStatus::Active->value)],
            'relationship' => ['sometimes', Rule::in(['parent', 'guardian', 'other'])],
            'is_primary' => ['sometimes', 'boolean'],
        ]);
        $verifiedParent = OrganizationMembership::query()->where('organization_id', $organization)
            ->where('user_id', $data['guardian_user_id'])->where('role', OrganizationRole::Parent)
            ->where('status', MembershipStatus::Active)->whereHas('user', fn ($query) => $query->whereNotNull('email_verified_at'))->exists();
        if (! $verifiedParent) {
            throw ValidationException::withMessages(['guardian_user_id' => ['Choose an active, verified parent account in this organization.']]);
        }

        [$link, $created] = DB::transaction(function () use ($data, $organization, $studentRecord, $request): array {
            Student::query()->where('organization_id', $organization)->whereKey($studentRecord->id)->lockForUpdate()->firstOrFail();
            if (($data['is_primary'] ?? false) === true) {
                StudentGuardian::query()->where('organization_id', $organization)->where('student_id', $studentRecord->id)
                    ->update(['is_primary' => false]);
            }

            $link = StudentGuardian::query()->firstOrNew([
                'organization_id' => $organization,
                'student_id' => $studentRecord->id,
                'guardian_user_id' => $data['guardian_user_id'],
            ]);
            $created = ! $link->exists;
            $link->fill([
                'linked_by' => $request->user()->id,
                'relationship' => $data['relationship'] ?? 'parent',
                'is_primary' => $data['is_primary'] ?? false,
            ]);
            $link->linked_at ??= now();
            $link->save();

            return [$link, $created];
        });

        return response()->json(['data' => [
            'guardian_user_id' => $link->guardian_user_id,
            'relationship' => $link->relationship,
            'is_primary' => $link->is_primary,
        ]], $created ? 201 : 200);
    }

    public function destroy(Request $request, int $organization, int $student, int $guardian): JsonResponse
    {
        $this->authorize($request, $organization);
        Student::query()->where('organization_id', $organization)->findOrFail($student);
        StudentGuardian::query()->where('organization_id', $organization)->where('student_id', $student)
            ->where('guardian_user_id', $guardian)->firstOrFail()->delete();

        return response()->json(['status' => 'unlinked']);
    }

    private function authorize(Request $request, int $organization): void
    {
        $membership = $this->access->membership($request, $organization, true);
        abort_unless(in_array($membership->role, [
            OrganizationRole::Admin,
            OrganizationRole::Management,
            OrganizationRole::IndependentEducator,
        ], true), 403);
    }
}
