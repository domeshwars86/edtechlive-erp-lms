<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Models\PlatformAuditEvent;
use App\Services\PlatformOperatorAccess;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformOrganizationController
{
    public function update(Request $request, int $organization, PlatformOperatorAccess $platformAccess, DatabaseManager $database): JsonResponse
    {
        abort_unless($platformAccess->allows($request->user()), 403);
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(['active', 'suspended'])],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $record = $database->transaction(function () use ($request, $organization, $data): Organization {
            $record = Organization::query()->lockForUpdate()->findOrFail($organization);
            $previous = $record->status;
            if ($previous === $data['status']) {
                return $record;
            }

            $record->forceFill(['status' => $data['status']])->save();
            PlatformAuditEvent::query()->create([
                'actor_user_id' => $request->user()->id,
                'organization_id' => $record->id,
                'organization_name' => $record->name,
                'action' => 'organization.status_changed',
                'reason' => trim($data['reason']),
                'before' => ['status' => $previous],
                'after' => ['status' => $record->status],
            ]);

            return $record;
        });

        return response()->json(['data' => ['id' => $record->id, 'name' => $record->name, 'status' => $record->status]]);
    }

    public function index(Request $request, PlatformOperatorAccess $platformAccess): JsonResponse
    {
        abort_unless($platformAccess->allows($request->user()), 403);
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'suspended'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $organizations = Organization::query()
            ->select(['id', 'name', 'slug', 'type', 'status', 'timezone', 'created_at'])
            ->withCount([
                'memberships as active_members_count' => fn ($query) => $query->where('status', 'active'),
                'students as active_students_count' => fn ($query) => $query->where('status', 'active'),
                'teachers as active_teachers_count' => fn ($query) => $query->where('status', 'active'),
            ])
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['q']), fn ($query) => $query->where(fn ($builder) => $builder
                ->where('name', 'like', '%'.trim($filters['q']).'%')
                ->orWhere('slug', 'like', '%'.trim($filters['q']).'%')))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => $organizations->getCollection()->map(fn (Organization $organization): array => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'type' => $organization->type->value,
                'status' => $organization->status,
                'timezone' => $organization->timezone,
                'created_at' => $organization->created_at?->toISOString(),
                'active_members' => $organization->active_members_count,
                'active_students' => $organization->active_students_count,
                'active_teachers' => $organization->active_teachers_count,
            ])->values(),
            'meta' => [
                'current_page' => $organizations->currentPage(),
                'last_page' => $organizations->lastPage(),
                'per_page' => $organizations->perPage(),
                'total' => $organizations->total(),
            ],
        ]);
    }
}
