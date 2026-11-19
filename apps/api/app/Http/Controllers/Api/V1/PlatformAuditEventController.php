<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PlatformAuditEvent;
use App\Services\PlatformOperatorAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformAuditEventController
{
    public function index(Request $request, PlatformOperatorAccess $platformAccess): JsonResponse
    {
        abort_unless($platformAccess->allows($request->user()), 403);
        $filters = $request->validate([
            'organization_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $events = PlatformAuditEvent::query()
            ->with('actor:id,name,email')
            ->when(isset($filters['organization_id']), fn ($query) => $query->where('organization_id', $filters['organization_id']))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json([
            'data' => $events->getCollection()->map(fn (PlatformAuditEvent $event): array => [
                'id' => $event->id,
                'organization_id' => $event->organization_id,
                'organization_name' => $event->organization_name,
                'action' => $event->action,
                'reason' => $event->reason,
                'before' => $event->before,
                'after' => $event->after,
                'actor' => $event->actor ? ['name' => $event->actor->name, 'email' => $event->actor->email] : null,
                'created_at' => $event->created_at?->toISOString(),
            ])->values(),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
        ]);
    }
}
