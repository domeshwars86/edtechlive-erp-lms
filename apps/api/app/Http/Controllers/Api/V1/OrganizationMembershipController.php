<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationAccessAudit;
use App\Models\OrganizationMembership;
use App\Services\OrganizationAccess;
use App\Services\OrganizationPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizationMembershipController
{
    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly OrganizationPermissions $permissions,
    ) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $actor = $this->access->membership($request, $organization);
        $this->assertPermission($actor, 'organization.memberships.read');
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'role' => ['sometimes', Rule::enum(OrganizationRole::class)],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = OrganizationMembership::query()->where('organization_id', $organization)
            ->whereIn('status', [MembershipStatus::Active, MembershipStatus::Suspended])
            ->with(['user:id,name,email', 'permissionOverrides'])->orderBy('role')->orderBy('id');
        if (isset($filters['search'])) {
            $term = '%'.mb_strtolower(trim($filters['search'])).'%';
            $query->whereHas('user', fn (Builder $users) => $users->whereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(email) LIKE ?', [$term]));
        }
        $records = $query->when(isset($filters['role']), fn (Builder $builder) => $builder->where('role', $filters['role']))
            ->when(isset($filters['status']), fn (Builder $builder) => $builder->where('status', $filters['status']))
            ->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));

        $canManagePermissions = $this->permissions->isGrantorRole($actor->role);

        return response()->json([
            'data' => $records->getCollection()->map(fn (OrganizationMembership $membership): array => $this->membershipData($membership, $actor, $canManagePermissions))->values(),
            'permission_catalog' => $this->permissions->catalog(),
            'can_manage_memberships' => $this->permissions->allows($actor, 'organization.memberships.manage'),
            'can_manage_permissions' => $canManagePermissions,
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    public function update(Request $request, int $organization, int $membership): JsonResponse
    {
        $actor = $this->access->membership($request, $organization);
        $this->assertPermission($actor, 'organization.memberships.manage');
        $canManagePermissions = $this->permissions->isGrantorRole($actor->role);
        $data = $request->validate([
            'role' => ['sometimes', 'required', Rule::enum(OrganizationRole::class)],
            'status' => ['sometimes', 'required', Rule::in(['active', 'suspended'])],
            'permissions' => ['sometimes', 'array', 'max:'.count($this->permissions->keys())],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in($this->permissions->keys())],
        ]);
        if ($data === []) {
            throw ValidationException::withMessages(['membership' => ['Choose a role or membership status to update.']]);
        }

        if (array_key_exists('permissions', $data)) {
            abort_unless($canManagePermissions, 403, 'Only Admin or Independent Educator memberships can grant or revoke capabilities.');
        }

        $updated = DB::transaction(function () use ($data, $organization, $membership, $request, $actor, $canManagePermissions): OrganizationMembership {
            // Serialize membership and platform role changes so concurrent updates cannot remove the last active administrator.
            Organization::query()->lockForUpdate()->findOrFail($organization);
            $target = OrganizationMembership::query()->where('organization_id', $organization)
                ->whereIn('status', [MembershipStatus::Active, MembershipStatus::Suspended])
                ->lockForUpdate()->findOrFail($membership);
            abort_unless((int) $target->user_id !== (int) $request->user()->id, 409, 'You cannot change your own organization access here.');

            $hasPermissionUpdate = array_key_exists('permissions', $data);
            $nextRole = $data['role'] ?? $target->role->value;
            $nextStatus = $data['status'] ?? $target->status->value;
            if ($hasPermissionUpdate) {
                abort_unless((int) $target->user_id !== (int) $request->user()->id, 409, 'You cannot grant or revoke your own organization permissions.');
                abort_unless($target->status === MembershipStatus::Active, 409, 'Permissions can only be changed for active memberships.');
                abort_unless($nextStatus === MembershipStatus::Active->value, 409, 'Permissions cannot be changed while suspending a membership.');
                abort_if($this->permissions->isPrivilegedRole($target->role), 409, 'Permissions cannot be overridden for privileged memberships.');
                abort_if($this->permissions->isPrivilegedRole(OrganizationRole::from($nextRole)), 409, 'Permissions cannot be overridden for privileged memberships.');
                if (in_array(OrganizationRole::from($nextRole), [OrganizationRole::Student, OrganizationRole::Parent], true)) {
                    abort_if(array_intersect($data['permissions'], ['organization.finance.read', 'organization.finance.manage']) !== [], 422, 'Finance capabilities can only be granted to staff memberships.');
                }
            }

            if ($canManagePermissions === false) {
                abort_if($this->permissions->isPrivilegedRole($target->role), 403, 'Delegated membership managers cannot change privileged memberships.');
                if (isset($data['role'])) {
                    abort_unless(
                        $this->permissions->canAssignRole($target->role, OrganizationRole::from($data['role'])),
                        403,
                        'Delegated membership managers cannot assign a higher-privilege role.',
                    );
                }
            }

            $previousRole = $target->role->value;
            $previousStatus = $target->status->value;
            $previousPermissions = $this->permissions->explicit($target);
            $target->update(['role' => $nextRole, 'status' => $nextStatus]);

            if ($hasPermissionUpdate) {
                $requestedPermissions = array_values(array_unique($data['permissions']));
                $requiredReads = [
                    'organization.memberships.manage' => 'organization.memberships.read',
                    'organization.memberships.audit' => 'organization.memberships.read',
                    'organization.finance.manage' => 'organization.finance.read',
                ];
                foreach ($requiredReads as $permission => $readPermission) {
                    if (in_array($permission, $requestedPermissions, true) && ! in_array($readPermission, $requestedPermissions, true)) {
                        $requestedPermissions[] = $readPermission;
                    }
                }
                $target->permissionOverrides()->delete();
                foreach ($requestedPermissions as $permission) {
                    $target->permissionOverrides()->create([
                        'permission_key' => $permission,
                        'granted_by' => $actor->user_id,
                    ]);
                }
            } elseif (in_array($target->role, [OrganizationRole::Student, OrganizationRole::Parent], true)) {
                // Family members retain profile-scoped access, even after a staff role is removed.
                $target->permissionOverrides()->delete();
            } elseif ($this->permissions->isPrivilegedRole($target->role)) {
                // Overrides must not become effective later if this privileged member is demoted.
                $target->permissionOverrides()->delete();
            }

            $nextPermissions = $this->permissions->explicit($target);

            $activeAdministrators = OrganizationMembership::query()->where('organization_id', $organization)
                ->where('status', MembershipStatus::Active)
                ->whereIn('role', [OrganizationRole::Admin, OrganizationRole::IndependentEducator])
                ->lockForUpdate()->get(['id']);
            if ($activeAdministrators->isEmpty()) {
                throw ValidationException::withMessages(['membership' => ['Keep at least one active administrator or independent educator in this organization.']]);
            }

            $membershipChanged = $previousRole !== $nextRole || $previousStatus !== $nextStatus;
            $permissionsChanged = $previousPermissions !== $nextPermissions;
            if ($membershipChanged || $permissionsChanged) {
                OrganizationAccessAudit::query()->create([
                    'organization_id' => $organization,
                    'membership_id' => $target->id,
                    'actor_user_id' => $actor->user_id,
                    'subject_user_id' => $target->user_id,
                    'previous_role' => $previousRole,
                    'role' => $nextRole,
                    'previous_status' => $previousStatus,
                    'status' => $nextStatus,
                    'event_type' => $membershipChanged && $permissionsChanged
                        ? 'membership_and_permissions'
                        : ($permissionsChanged ? 'permissions' : 'membership'),
                    'previous_permissions' => $permissionsChanged ? $previousPermissions : null,
                    'permissions' => $permissionsChanged ? $nextPermissions : null,
                    'created_at' => now(),
                ]);
            }

            return $target->refresh()->load('user:id,name,email');
        });

        return response()->json(['data' => $this->membershipData($updated, $actor, $canManagePermissions)]);
    }

    public function audit(Request $request, int $organization): JsonResponse
    {
        $actor = $this->access->membership($request, $organization);
        $this->assertPermission($actor, 'organization.memberships.audit');
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
            'event_type' => $audit->event_type,
            'previous_permissions' => $audit->previous_permissions,
            'permissions' => $audit->permissions,
            'created_at' => $audit->created_at?->toISOString(),
        ])->values(), 'meta' => [
            'current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'total' => $records->total(),
        ]]);
    }

    private function assertPermission(OrganizationMembership $membership, string $permission): void
    {
        abort_unless($this->permissions->allows($membership, $permission), 403);
    }

    private function membershipData(OrganizationMembership $membership, OrganizationMembership $actor, bool $canManagePermissions): array
    {
        $targetIsEligible = $membership->status === MembershipStatus::Active
            && $this->permissions->isPrivilegedRole($membership->role) === false
            && ! in_array($membership->role, [OrganizationRole::Student, OrganizationRole::Parent], true)
            && (int) $membership->user_id !== (int) $actor->user_id;

        return [
            'id' => $membership->id,
            'user' => $membership->user?->only(['id', 'name', 'email']),
            'role' => $membership->role->value,
            'status' => $membership->status->value,
            'accepted_at' => $membership->accepted_at?->toISOString(),
            'permissions' => [
                'effective' => $this->permissions->effective($membership),
                'overrides' => $this->permissions->explicit($membership),
                'editable' => $canManagePermissions && $targetIsEligible,
            ],
        ];
    }
}
