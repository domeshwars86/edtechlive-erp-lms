<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\User;
use App\Services\PlatformOperatorAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformUserController
{
    public function index(Request $request, PlatformOperatorAccess $platformAccess): JsonResponse
    {
        abort_unless($platformAccess->allows($request->user()), 403);
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'email_verified' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'role' => ['sometimes', 'string', 'in:'.implode(',', array_map(fn (OrganizationRole $role): string => $role->value, OrganizationRole::cases()))],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $users = User::query()
            ->select(['id', 'name', 'email', 'email_verified_at', 'created_at'])
            ->withCount(['memberships as active_organizations' => fn ($query) => $query
                ->where('status', MembershipStatus::Active->value)])
            ->when(isset($filters['email_verified']), fn ($query) => $request->boolean('email_verified')
                ? $query->whereNotNull('email_verified_at')
                : $query->whereNull('email_verified_at'))
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
            ])->values(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }
}
