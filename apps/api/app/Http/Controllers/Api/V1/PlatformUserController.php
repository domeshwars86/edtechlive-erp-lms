<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\PlatformAuditEvent;
use App\Models\User;
use App\Services\LocalAuthLinkFactory;
use App\Services\PlatformOperatorAccess;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformUserController
{
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
}
