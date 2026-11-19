<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\OrganizationAccessAudit;
use App\Models\OrganizationMembership;
use App\Services\OrganizationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizationMembershipController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $this->assertRoleManager($request, $organization);
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'role' => ['sometimes', Rule::enum(OrganizationRole::class)],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = OrganizationMembership::query()->where('organization_id', $organization)
            ->whereIn('status', [MembershipStatus::Active, MembershipStatus::Suspended])
            ->with('user:id,name,email')->orderBy('role')->orderBy('id');
        if (isset($filters['search'])) {
            $term = '%'.mb_strtolower(trim($filters['search'])).'%';
            $query->whereHas('user', fn (Builder $users) => $users->whereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(email) LIKE ?', [$term]));
        }
        $records = $query->when(isset($filters['role']), fn (Builder $builder) => $builder->where('role', $filters['role']))
            ->when(isset($filters['status']), fn (Builder $builder) => $builder->where('status', $filters['status']))
            ->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));

        return response()->json(['data' => $records->getCollection()->map(fn (OrganizationMembership $membership): array => $this->membershipData($membership))->values(), 'meta' => [
            'current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'total' => $records->total(),
        ]]);
    }

    public function update(Request $request, int $organization, int $membership): JsonResponse
    {
        $actor = $this->assertRoleManager($request, $organization);
        $data = $request->validate([
            'role' => ['sometimes', 'required', Rule::enum(OrganizationRole::class)],
            'status' => ['sometimes', 'required', Rule::in(['active', 'suspended'])],
        ]);
        if ($data === []) {
            throw ValidationException::withMessages(['membership' => ['Choose a role or membership status to update.']]);
        }

        $updated = DB::transaction(function () use ($data, $organization, $membership, $request, $actor): OrganizationMembership {
            $target = OrganizationMembership::query()->where('organization_id', $organization)
                ->whereIn('status', [MembershipStatus::Active, MembershipStatus::Suspended])
                ->lockForUpdate()->findOrFail($membership);
            abort_unless((int) $target->user_id !== (int) $request->user()->id, 409, 'You cannot change your own organization access here.');

            $previousRole = $target->role->value;
            $previousStatus = $target->status->value;
            $nextRole = $data['role'] ?? $previousRole;
            $nextStatus = $data['status'] ?? $previousStatus;
            $target->update(['role' => $nextRole, 'status' => $nextStatus]);

            $activeAdministrators = OrganizationMembership::query()->where('organization_id', $organization)
                ->where('status', MembershipStatus::Active)
                ->whereIn('role', [OrganizationRole::Admin, OrganizationRole::IndependentEducator])
                ->lockForUpdate()->get(['id']);
            if ($activeAdministrators->isEmpty()) {
                throw ValidationException::withMessages(['membership' => ['Keep at least one active administrator or independent educator in this organization.']]);
            }

            if ($previousRole !== $nextRole || $previousStatus !== $nextStatus) {
                OrganizationAccessAudit::query()->create([
                    'organization_id' => $organization,
                    'membership_id' => $target->id,
                    'actor_user_id' => $actor->user_id,
                    'subject_user_id' => $target->user_id,
                    'previous_role' => $previousRole,
                    'role' => $nextRole,
                    'previous_status' => $previousStatus,
                    'status' => $nextStatus,
                    'created_at' => now(),
                ]);
            }

            return $target->refresh()->load('user:id,name,email');
        });

        return response()->json(['data' => $this->membershipData($updated)]);
    }

    public function audit(Request $request, int $organization): JsonResponse
    {
        $this->assertRoleManager($request, $organization);
        $records = OrganizationAccessAudit::query()->where('organization_id', $organization)
            ->with(['actor:id,name', 'subject:id,name'])->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json(['data' => $records->getCollection()->map(fn (OrganizationAccessAudit $audit): array => [
            'id' => $audit->id,
            'actor' => $audit->actor?->name,
            'subject' => $audit->subject?->name,
            'previous_role' => $audit->previous_role,
            'role' => $audit->role,
            'previous_status' => $audit->previous_status,
            'status' => $audit->status,
            'created_at' => $audit->created_at?->toISOString(),
        ])->values(), 'meta' => [
            'current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'total' => $records->total(),
        ]]);
    }

    private function assertRoleManager(Request $request, int $organization): OrganizationMembership
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless(in_array($membership->role, [OrganizationRole::Admin, OrganizationRole::IndependentEducator], true), 403);

        return $membership;
    }

    private function membershipData(OrganizationMembership $membership): array
    {
        return [
            'id' => $membership->id,
            'user' => $membership->user?->only(['id', 'name', 'email']),
            'role' => $membership->role->value,
            'status' => $membership->status->value,
            'accepted_at' => $membership->accepted_at?->toISOString(),
        ];
    }
}
