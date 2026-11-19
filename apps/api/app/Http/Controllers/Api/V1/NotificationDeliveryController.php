<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\NotificationDelivery;
use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationDeliveryController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $this->access->membership($request, $organization);
        $deliveries = NotificationDelivery::query()->where('organization_id', $organization)
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 10), 1), 50));

        return response()->json([
            'data' => $deliveries->getCollection()->map(fn (NotificationDelivery $delivery): array => [
                'id' => $delivery->id,
                'kind' => $delivery->kind,
                'title' => $delivery->title,
                'status' => $delivery->status,
                'attempts' => $delivery->attempts,
                'queued_at' => $delivery->queued_at?->toISOString(),
                'sent_at' => $delivery->sent_at?->toISOString(),
                'failed_at' => $delivery->failed_at?->toISOString(),
                'cancelled_at' => $delivery->cancelled_at?->toISOString(),
            ])->values(),
            'meta' => ['current_page' => $deliveries->currentPage(), 'last_page' => $deliveries->lastPage(), 'total' => $deliveries->total()],
        ]);
    }
}
