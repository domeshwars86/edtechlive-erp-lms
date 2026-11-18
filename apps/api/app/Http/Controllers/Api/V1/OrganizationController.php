<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $memberships = $request->user()
            ->memberships()
            ->with('organization:id,name,slug,type,status,timezone')
            ->where('status', MembershipStatus::Active->value)
            ->whereHas('organization', fn ($query) => $query->where('status', 'active'))
            ->orderBy('organization_id')
            ->paginate($perPage);

        return response()->json([
            'data' => $memberships->getCollection()->map(fn ($membership): array => [
                'membership_id' => $membership->id,
                'role' => $membership->role->value,
                'organization' => [
                    'id' => $membership->organization->id,
                    'name' => $membership->organization->name,
                    'slug' => $membership->organization->slug,
                    'type' => $membership->organization->type->value,
                    'status' => $membership->organization->status,
                    'timezone' => $membership->organization->timezone,
                ],
            ])->values(),
            'meta' => [
                'current_page' => $memberships->currentPage(),
                'last_page' => $memberships->lastPage(),
                'per_page' => $memberships->perPage(),
                'total' => $memberships->total(),
            ],
        ]);
    }

    public function show(Request $request, int $organization): JsonResponse
    {
        $membership = $request->user()->memberships()
            ->with('organization:id,name,slug,type,status,timezone')
            ->where('status', MembershipStatus::Active->value)
            ->whereHas('organization', fn ($query) => $query
                ->whereKey($organization)
                ->where('status', 'active'))
            ->first();

        abort_unless($membership !== null, 404);

        return response()->json([
            'data' => [
                'membership_id' => $membership->id,
                'role' => $membership->role->value,
                'organization' => [
                    'id' => $membership->organization->id,
                    'name' => $membership->organization->name,
                    'slug' => $membership->organization->slug,
                    'type' => $membership->organization->type->value,
                    'status' => $membership->organization->status,
                    'timezone' => $membership->organization->timezone,
                ],
            ],
        ]);
    }
}
