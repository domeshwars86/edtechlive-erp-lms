<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationAccessAudit;
use App\Models\OrganizationMembership;
use App\Models\PlatformAuditEvent;
use App\Models\User;
use App\Services\LocalAuthLinkFactory;
use App\Services\PlatformOperatorAccess;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformUserController
{
    public function memberships(Request $request, int $user, PlatformOperatorAccess $platformAccess): JsonResponse
    {
        abort_unless($platformAccess->allows($request->user()), 403);
        $record = User::query()->findOrFail($user);
        abort_if($platformAccess->allows($record), 409, 'Allowlisted platform operator accounts cannot be changed here.');

        $memberships = OrganizationMembership::query()
            ->where('user_id', $record->id)
            ->whereIn('status', [MembershipStatus::Active, MembershipStatus::Suspended])
            ->with('organization:id,name,status')
            ->orderBy('organization_id')->get();

        return response()->json(['data' => $memberships->map(fn (OrganizationMembership $membership): array => [
            'id' => $membership->id,
            'organization_id' => $membership->organization_id,
            'organization' => $membership->organization?->name,
            'organization_status' => $membership->organization?->status,
            'role' => $membership->role->value,
            'status' => $membership->status->value,
        ])->values()]);
    }

    public function updateMembership(Request $request, int $user, int $membership, PlatformOperatorAccess $platformAccess): JsonResponse
    {
        $actor = $request->user();
        abort_unless($platformAccess->allows($actor), 403);
        $data = $request->validate([
            'role' => ['required', Rule::enum(OrganizationRole::class)],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        if (mb_strlen(trim($data['reason'])) < 8) {
            throw ValidationException::withMessages(['reason' => ['The reason must contain at least 8 non-whitespace characters.']]);
        }

        $updated = DB::transaction(function () use ($actor, $user, $membership, $data, $platformAccess): OrganizationMembership {
            $record = User::query()->findOrFail($user);
            abort_if($platformAccess->allows($record), 409, 'Allowlisted platform operator accounts cannot be changed here.');
            $membershipQuery = OrganizationMembership::query()->where('user_id', $record->id)
                ->whereIn('status', [MembershipStatus::Active, MembershipStatus::Suspended])
                ->whereKey($membership);
            $membershipRecord = (clone $membershipQuery)->firstOrFail();

            // Serialize administrative changes for this organization and preserve its last active administrator.
            $organization = Organization::query()->lockForUpdate()->findOrFail($membershipRecord->organization_id);
            $target = $membershipQuery->lockForUpdate()->firstOrFail();
            $previousRole = $target->role;
            $nextRole = OrganizationRole::from($data['role']);
            if ($previousRole === $nextRole) {
                return $target->load('organization:id,name');
            }

            $privilegedRoles = [OrganizationRole::Admin, OrganizationRole::IndependentEducator];
            $previousPermissions = $target->permissionOverrides()->orderBy('permission_key')->pluck('permission_key')->all();
            if ($target->status === MembershipStatus::Active && in_array($previousRole, $privilegedRoles, true) && ! in_array($nextRole, $privilegedRoles, true)) {
                $otherActiveAdministrators = OrganizationMembership::query()
                    ->where('organization_id', $organization->id)
                    ->where('status', MembershipStatus::Active)
                    ->whereIn('role', $privilegedRoles)
                    ->whereKeyNot($target->id)
                    ->lockForUpdate()->exists();
                abort_unless($otherActiveAdministrators, 409, 'Keep at least one active administrator or independent educator in this organization.');
            }

            $previousStatus = $target->status->value;
            $target->update(['role' => $nextRole]);
            if (in_array($nextRole, $privilegedRoles, true)) {
                // Permission overrides must not take effect if this privileged member is later demoted.
                $target->permissionOverrides()->delete();
            }
            $nextPermissions = $target->permissionOverrides()->orderBy('permission_key')->pluck('permission_key')->all();
            $permissionsChanged = $previousPermissions !== $nextPermissions;

            OrganizationAccessAudit::query()->create([
                'organization_id' => $organization->id,
                'membership_id' => $target->id,
                'actor_user_id' => $actor->id,
                'subject_user_id' => $record->id,
                'previous_role' => $previousRole->value,
                'role' => $nextRole->value,
                'previous_status' => $previousStatus,
                'status' => $previousStatus,
                'event_type' => $permissionsChanged ? 'membership_and_permissions' : 'membership',
                'previous_permissions' => $permissionsChanged ? $previousPermissions : null,
                'permissions' => $permissionsChanged ? $nextPermissions : null,
                'created_at' => now(),
            ]);
            PlatformAuditEvent::query()->create([
                'actor_user_id' => $actor->id,
                'organization_id' => $organization->id,
                'organization_name' => $organization->name,
                'action' => 'user.membership_role_changed',
                'reason' => trim($data['reason']),
                'before' => ['user_id' => $record->id, 'membership_id' => $target->id, 'role' => $previousRole->value, 'status' => $previousStatus, 'permissions' => $previousPermissions],
                'after' => ['user_id' => $record->id, 'membership_id' => $target->id, 'role' => $nextRole->value, 'status' => $previousStatus, 'permissions' => $nextPermissions],
            ]);

            return $target->refresh()->load('organization:id,name');
        });

        return response()->json(['data' => [
            'id' => $updated->id,
            'organization_id' => $updated->organization_id,
            'organization' => $updated->organization?->name,
            'role' => $updated->role->value,
            'status' => $updated->status->value,
        ]]);
    }

    public function index(Request $request, PlatformOperatorAccess $platformAccess): JsonResponse
    {
        abort_unless($platformAccess->allows($request->user()), 403);
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'email_verified' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'role' => ['sometimes', 'string', 'in:'.implode(',', array_map(fn (OrganizationRole $role): string => $role->value, OrganizationRole::cases()))],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $users = User::query()
            ->select(['id', 'name', 'email', 'email_verified_at', 'created_at', 'platform_status'])
            ->withCount(['memberships as active_organizations' => fn ($query) => $query
                ->where('status', MembershipStatus::Active->value)])
            ->when(isset($filters['email_verified']), fn ($query) => $request->boolean('email_verified')
                ? $query->whereNotNull('email_verified_at')
                : $query->whereNull('email_verified_at'))
            ->when(isset($filters['status']), fn ($query) => $query->where('platform_status', $filters['status']))
            ->when(isset($filters['role']), fn ($query) => $query->whereHas('memberships', fn ($membership) => $membership
                ->where('status', MembershipStatus::Active->value)
                ->where('role', $filters['role'])))
            ->when(isset($filters['q']), function ($query) use ($filters): void {
                $term = mb_strtolower(trim($filters['q']));
                $query->where(fn ($builder) => $builder
                    ->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$term}%"]));
            })
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => $users->getCollection()->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toISOString(),
                'created_at' => $user->created_at?->toISOString(),
                'active_organizations' => $user->active_organizations,
                'platform_status' => $user->platform_status,
                'status_change_allowed' => ! $platformAccess->allows($user),
            ])->values(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function update(Request $request, int $user, PlatformOperatorAccess $platformAccess, DatabaseManager $database): JsonResponse
    {
        $actor = $request->user();
        abort_unless($platformAccess->allows($actor), 403);
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        if (mb_strlen(trim($data['reason'])) < 8) {
            throw ValidationException::withMessages(['reason' => ['The reason must contain at least 8 non-whitespace characters.']]);
        }
        abort_if((int) $actor->id === $user, 409, 'Platform operators cannot suspend their own account.');

        $record = $database->transaction(function () use ($actor, $user, $data, $platformAccess): User {
            $record = User::query()->lockForUpdate()->findOrFail($user);
            abort_if($platformAccess->allows($record), 409, 'Allowlisted platform operator accounts cannot be changed here.');
            $previous = $record->platform_status;
            if ($previous === $data['status']) {
                return $record;
            }

            $record->forceFill(['platform_status' => $data['status']])->save();
            PlatformAuditEvent::query()->create([
                'actor_user_id' => $actor->id,
                'organization_id' => null,
                'organization_name' => 'Platform accounts',
                'action' => 'user.status_changed',
                'reason' => trim($data['reason']),
                'before' => ['user_id' => $record->id, 'email' => $record->email, 'status' => $previous],
                'after' => ['user_id' => $record->id, 'email' => $record->email, 'status' => $record->platform_status],
            ]);

            return $record;
        });

        return response()->json(['data' => [
            'id' => $record->id,
            'platform_status' => $record->platform_status,
        ]]);
    }

    public function updateProfile(Request $request, int $user, PlatformOperatorAccess $platformAccess, LocalAuthLinkFactory $authLinks, DatabaseManager $database): JsonResponse
    {
        $actor = $request->user();
        abort_unless($platformAccess->allows($actor), 403);
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:254', Rule::unique('users', 'email')->ignore($user)],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        if (mb_strlen(trim($data['reason'])) < 8) {
            throw ValidationException::withMessages(['reason' => ['The reason must contain at least 8 non-whitespace characters.']]);
        }

        $record = $database->transaction(function () use ($actor, $user, $data, $platformAccess): User {
            $record = User::query()->lockForUpdate()->findOrFail($user);
            abort_if($platformAccess->allows($record), 409, 'Allowlisted platform operator accounts cannot be changed here.');

            $before = ['user_id' => $record->id, 'name' => $record->name, 'email' => $record->email, 'email_verified' => $record->hasVerifiedEmail()];
            $emailChanged = $record->email !== $data['email'];
            $record->forceFill([
                'name' => trim($data['name']),
                'email' => $data['email'],
                'email_verified_at' => $emailChanged ? null : $record->email_verified_at,
            ])->save();

            PlatformAuditEvent::query()->create([
                'actor_user_id' => $actor->id,
                'organization_id' => null,
                'organization_name' => 'Platform accounts',
                'action' => 'user.identity_changed',
                'reason' => trim($data['reason']),
                'before' => $before,
                'after' => ['user_id' => $record->id, 'name' => $record->name, 'email' => $record->email, 'email_verified' => $record->hasVerifiedEmail()],
            ]);

            return $record;
        });

        $emailChanged = $record->wasChanged('email');
        if ($emailChanged) {
            $record->sendEmailVerificationNotification();
        }

        $response = [
            'data' => [
                'id' => $record->id,
                'name' => $record->name,
                'email' => $record->email,
                'email_verified_at' => $record->email_verified_at?->toISOString(),
            ],
            'email_changed' => $emailChanged,
        ];
        if ($emailChanged && ($verificationUrl = $authLinks->verification($record)) !== null) {
            $response['verification_url'] = $verificationUrl;
        }

        return response()->json($response);
    }

    public function requestPasswordReset(Request $request, int $user, PlatformOperatorAccess $platformAccess): JsonResponse
    {
        $actor = $request->user();
        abort_unless($platformAccess->allows($actor), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:500']]);
        if (mb_strlen(trim($data['reason'])) < 8) {
            throw ValidationException::withMessages(['reason' => ['The reason must contain at least 8 non-whitespace characters.']]);
        }

        $record = User::query()->findOrFail($user);
        abort_if($platformAccess->allows($record), 409, 'Password resets for allowlisted platform operators must use the account recovery flow.');
        $resetUrl = null;
        $status = Password::sendResetLink(
            ['email' => $record->email],
            config('app.env') === 'local'
                ? function (User $notifiable, string $token) use (&$resetUrl): string {
                    $resetUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/').'/reset-password?'.http_build_query([
                        'token' => $token,
                        'email' => $notifiable->getEmailForPasswordReset(),
                    ]);

                    return Password::RESET_LINK_SENT;
                }
            : null,
        );
        if ($status === Password::RESET_LINK_SENT) {
            PlatformAuditEvent::query()->create([
                'actor_user_id' => $actor->id,
                'organization_id' => null,
                'organization_name' => 'Platform accounts',
                'action' => 'user.password_reset_requested',
                'reason' => trim($data['reason']),
                'before' => ['user_id' => $record->id, 'email' => $record->email],
                'after' => ['user_id' => $record->id, 'email' => $record->email, 'status' => 'reset_link_sent'],
            ]);
        }

        $response = [
            'message' => 'If the account can receive a password reset, instructions have been sent.',
        ];
        if (config('app.env') === 'local' && $status === Password::RESET_LINK_SENT && $resetUrl !== null) {
            $response['reset_url'] = $resetUrl;
        }

        return response()->json($response, 202);
    }
}
