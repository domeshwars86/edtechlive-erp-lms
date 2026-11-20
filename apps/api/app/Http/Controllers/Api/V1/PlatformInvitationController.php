<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\PlatformAuditEvent;
use App\Models\Teacher;
use App\Models\User;
use App\Services\PlatformOperatorAccess;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlatformInvitationController
{
    public function index(Request $request, PlatformOperatorAccess $platformAccess): JsonResponse
    {
        abort_unless($platformAccess->allows($request->user()), 403);
        $filters = $request->validate([
            'organization_id' => ['sometimes', 'integer', 'exists:organizations,id'],
            'q' => ['sometimes', 'string', 'max:254'],
            'status' => ['sometimes', 'string', 'in:pending,expired,accepted,revoked'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $invitations = OrganizationInvitation::query()->with(['organization:id,name,status', 'inviter:id,name,email'])
            ->whereIn('role', ['admin', 'management', 'teacher', 'independent_educator'])
            ->when(isset($filters['organization_id']), fn ($query) => $query->where('organization_id', $filters['organization_id']))
            ->when(isset($filters['q']), function ($query) use ($filters): void {
                $search = trim($filters['q']);
                $query->where(function ($nested) use ($search): void {
                    $nested->where('email', 'like', '%'.$search.'%')
                        ->orWhereHas('organization', fn ($organization) => $organization->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when(isset($filters['status']), function ($query) use ($filters): void {
                match ($filters['status']) {
                    'pending' => $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now()),
                    'expired' => $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '<=', now()),
                    'accepted' => $query->whereNotNull('accepted_at'),
                    'revoked' => $query->whereNotNull('revoked_at'),
                };
            })
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json([
            'data' => $invitations->getCollection()->map(function (OrganizationInvitation $invitation): array {
                $status = $invitation->revoked_at ? 'revoked' : ($invitation->accepted_at ? 'accepted' : ($invitation->expires_at->isPast() ? 'expired' : 'pending'));

                return [
                    'id' => $invitation->id,
                    'organization_id' => $invitation->organization_id,
                    'organization' => $invitation->organization?->name,
                    'organization_status' => $invitation->organization?->status,
                    'email' => $invitation->email,
                    'role' => $invitation->role,
                    'status' => $status,
                    'invited_by' => $invitation->inviter ? ['name' => $invitation->inviter->name, 'email' => $invitation->inviter->email] : null,
                    'expires_at' => $invitation->expires_at->toISOString(),
                    'accepted_at' => $invitation->accepted_at?->toISOString(),
                    'revoked_at' => $invitation->revoked_at?->toISOString(),
                    'revocation_reason' => $invitation->revocation_reason,
                    'created_at' => $invitation->created_at?->toISOString(),
                ];
            })->values(),
            'meta' => [
                'current_page' => $invitations->currentPage(),
                'last_page' => $invitations->lastPage(),
                'per_page' => $invitations->perPage(),
                'total' => $invitations->total(),
            ],
        ]);
    }

    public function store(Request $request, PlatformOperatorAccess $platformAccess, DatabaseManager $database): JsonResponse
    {
        $actor = $request->user();
        abort_unless($platformAccess->allows($actor), 403);
        $data = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'role' => ['required', 'string', 'in:admin,management,teacher,independent_educator'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        if (mb_strlen(trim($data['reason'])) < 8) {
            throw ValidationException::withMessages(['reason' => ['The reason must contain at least 8 non-whitespace characters.']]);
        }

        $email = Str::lower(trim($data['email']));
        $plainToken = Str::random(64);
        [$organization, $invitation] = $database->transaction(function () use ($actor, $data, $email, $plainToken): array {
            $organization = Organization::query()->lockForUpdate()->findOrFail($data['organization_id']);
            if ($organization->status !== 'active') {
                throw ValidationException::withMessages(['organization_id' => ['Invitations can only be created for active organizations.']]);
            }

            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
            if ($user?->platform_status === 'suspended') {
                throw ValidationException::withMessages(['email' => ['This account is suspended and cannot be invited.']]);
            }
            $membership = $user ? OrganizationMembership::query()
                ->where('organization_id', $organization->id)->where('user_id', $user->id)->lockForUpdate()->first() : null;
            if ($membership?->status === MembershipStatus::Active) {
                throw ValidationException::withMessages(['email' => ['This account is already an active member of the organization.']]);
            }
            if ($membership?->status === MembershipStatus::Suspended) {
                throw ValidationException::withMessages(['email' => ['This account has a suspended organization membership.']]);
            }

            if ($data['role'] === OrganizationRole::Teacher->value) {
                $teacher = Teacher::query()->where('organization_id', $organization->id)->whereRaw('LOWER(email) = ?', [$email])->first();
                $linkedTeacher = $user ? Teacher::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->exists() : false;
                if ($teacher && $teacher->status !== 'active') {
                    throw ValidationException::withMessages(['email' => ['Activate the teacher roster profile before sending an account invitation.']]);
                }
                if ($linkedTeacher || ($teacher?->user_id && $teacher->user_id !== $user?->id)) {
                    throw ValidationException::withMessages(['email' => ['This account or teacher profile is already linked.']]);
                }
            }

            $invitationQuery = OrganizationInvitation::query()
                ->where('organization_id', $organization->id)
                ->where('email', $email)
                ->where('role', $data['role'])
                ->whereNull('accepted_at');
            $previousInvitation = (clone $invitationQuery)->lockForUpdate()->first();
            $invitation = OrganizationInvitation::query()->updateOrCreate(
                ['organization_id' => $organization->id, 'email' => $email, 'role' => $data['role'], 'accepted_at' => null],
                [
                    'token_hash' => hash('sha256', $plainToken),
                    'invited_by' => $actor->id,
                    'expires_at' => now()->addDays(7),
                    'accepted_by' => null,
                    'revoked_at' => null,
                    'revoked_by' => null,
                    'revocation_reason' => null,
                ],
            );
            PlatformAuditEvent::query()->create([
                'actor_user_id' => $actor->id,
                'organization_id' => $organization->id,
                'organization_name' => $organization->name,
                'action' => 'organization.member_invited',
                'reason' => trim($data['reason']),
                'before' => $previousInvitation ? ['invitation_id' => $previousInvitation->id, 'role' => $previousInvitation->role, 'status' => 'pending'] : null,
                'after' => ['invitation_id' => $invitation->id, 'email' => $email, 'role' => $data['role'], 'expires_at' => $invitation->expires_at->toISOString()],
            ]);

            return [$organization, $invitation];
        });

        $invitationUrl = rtrim((string) config('app.frontend_url'), '/').'/invitations/accept?token='.urlencode($plainToken);
        Mail::to($email)->send(new OrganizationInvitationMail(
            $organization->name,
            $data['role'],
            $invitationUrl,
            $actor->name,
        ));

        $response = [
            'data' => [
                'id' => $invitation->id,
                'organization_id' => $organization->id,
                'organization' => $organization->name,
                'email' => $email,
                'role' => $data['role'],
                'status' => 'pending',
                'expires_at' => $invitation->expires_at->toISOString(),
            ],
            'message' => 'Invitation sent. The recipient creates their account and accepts the organization role using the invitation link.',
        ];
        if (config('app.env') === 'local') {
            $response['invitation_url'] = $invitationUrl;
        }

        return response()->json($response, 201);
    }

    public function revoke(Request $request, int $invitation, PlatformOperatorAccess $platformAccess, DatabaseManager $database): JsonResponse
    {
        $actor = $request->user();
        abort_unless($platformAccess->allows($actor), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:500']]);
        if (mb_strlen(trim($data['reason'])) < 8) {
            throw ValidationException::withMessages(['reason' => ['The reason must contain at least 8 non-whitespace characters.']]);
        }

        $revoked = $database->transaction(function () use ($actor, $data, $invitation): OrganizationInvitation {
            $record = OrganizationInvitation::query()->with('organization:id,name')->lockForUpdate()->findOrFail($invitation);
            abort_if($record->accepted_at !== null, 409, 'Accepted invitations cannot be revoked.');
            abort_if($record->revoked_at !== null, 409, 'This invitation has already been revoked.');

            $before = ['invitation_id' => $record->id, 'email' => $record->email, 'role' => $record->role, 'status' => $record->expires_at->isPast() ? 'expired' : 'pending'];
            $record->update([
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
                'revocation_reason' => trim($data['reason']),
            ]);
            PlatformAuditEvent::query()->create([
                'actor_user_id' => $actor->id,
                'organization_id' => $record->organization_id,
                'organization_name' => $record->organization?->name,
                'action' => 'organization.invitation_revoked',
                'reason' => trim($data['reason']),
                'before' => $before,
                'after' => ['invitation_id' => $record->id, 'email' => $record->email, 'role' => $record->role, 'status' => 'revoked'],
            ]);

            return $record;
        });

        return response()->json(['data' => ['id' => $revoked->id, 'status' => 'revoked'], 'message' => 'Invitation revoked.']);
    }
}
