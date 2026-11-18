<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\Announcement;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AnnouncementController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $this->manager($request, $organization);
        $announcements = Announcement::query()->where('organization_id', $organization)
            ->orderByDesc('sent_at')->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => $announcements->getCollection()->map(fn (Announcement $announcement): array => $this->row($announcement))->values(),
            'meta' => ['current_page' => $announcements->currentPage(), 'last_page' => $announcements->lastPage(), 'total' => $announcements->total()],
        ]);
    }

    public function store(Request $request, int $organization): JsonResponse
    {
        $this->manager($request, $organization);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'body' => ['required', 'string', 'min:2', 'max:4000'],
            'audience' => ['required', Rule::in(['all', 'staff', 'teachers', 'students', 'parents'])],
        ]);
        $roles = match ($data['audience']) {
            'staff' => [OrganizationRole::Admin, OrganizationRole::Management, OrganizationRole::IndependentEducator, OrganizationRole::Teacher],
            'teachers' => [OrganizationRole::Teacher],
            'students' => [OrganizationRole::Student],
            'parents' => [OrganizationRole::Parent],
            default => [OrganizationRole::Admin, OrganizationRole::Management, OrganizationRole::IndependentEducator, OrganizationRole::Teacher, OrganizationRole::Student, OrganizationRole::Parent],
        };
        $recipients = OrganizationMembership::query()->where('organization_id', $organization)
            ->where('status', MembershipStatus::Active)->whereIn('role', $roles)->pluck('user_id')->unique()->values();

        $announcement = DB::transaction(function () use ($data, $organization, $request, $recipients): Announcement {
            $announcement = Announcement::query()->create([
                ...$data, 'organization_id' => $organization, 'created_by' => $request->user()->id,
                'recipient_count' => $recipients->count(), 'sent_at' => now(),
            ]);
            $now = now();
            $payload = json_encode([
                'organization_id' => $organization, 'kind' => 'announcement', 'title' => $data['title'],
                'body' => $data['body'], 'resource_type' => 'announcement', 'resource_id' => $announcement->id,
            ], JSON_THROW_ON_ERROR);
            if ($recipients->isNotEmpty()) {
                foreach ($recipients->chunk(100) as $recipientChunk) {
                    DB::table('notifications')->insert($recipientChunk->map(fn (int $userId): array => [
                        'id' => (string) Str::uuid(), 'type' => 'announcement',
                        'notifiable_type' => User::class, 'notifiable_id' => $userId,
                        'data' => $payload, 'read_at' => null, 'created_at' => $now, 'updated_at' => $now,
                    ])->all());
                }
            }

            return $announcement;
        });

        return response()->json(['data' => $this->row($announcement)], 201);
    }

    private function manager(Request $request, int $organization): void
    {
        $membership = $this->access->membership($request, $organization, true);
        abort_unless(in_array($membership->role, [OrganizationRole::Admin, OrganizationRole::Management, OrganizationRole::IndependentEducator], true), 403);
    }

    private function row(Announcement $announcement): array
    {
        return [
            'id' => $announcement->id, 'title' => $announcement->title, 'body' => $announcement->body,
            'audience' => $announcement->audience, 'recipient_count' => $announcement->recipient_count,
            'sent_at' => $announcement->sent_at?->toISOString(),
        ];
    }
}
