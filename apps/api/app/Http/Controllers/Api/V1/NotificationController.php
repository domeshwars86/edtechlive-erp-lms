<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $this->access->membership($request, $organization);
        $notifications = $request->user()->notifications()
            ->where('data->organization_id', $organization)
            ->orderByRaw('read_at IS NULL DESC')->orderByDesc('created_at')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json([
            'data' => $notifications->getCollection()->map(fn ($notification): array => [
                'id' => $notification->id,
                'type' => $notification->data['kind'] ?? 'notice',
                'title' => $notification->data['title'] ?? 'Notification',
                'body' => $notification->data['body'] ?? '',
                'resource_type' => $notification->data['resource_type'] ?? null,
                'resource_id' => $notification->data['resource_id'] ?? null,
                'read_at' => $notification->read_at?->toISOString(),
                'created_at' => $notification->created_at?->toISOString(),
            ])->values(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
                'unread' => $request->user()->unreadNotifications()->where('data->organization_id', $organization)->count(),
            ],
        ]);
    }

    public function markRead(Request $request, int $organization, string $notification): JsonResponse
    {
        $this->access->membership($request, $organization);
        $record = $request->user()->notifications()
            ->where('id', $notification)->where('data->organization_id', $organization)->firstOrFail();
        $record->markAsRead();

        return response()->json(['data' => ['id' => $record->id, 'read_at' => $record->read_at?->toISOString()]]);
    }
}
