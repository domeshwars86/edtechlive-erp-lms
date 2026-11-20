<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Teacher;
use App\Models\User;
use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizationInvitationController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $this->authorizeInvitations($request, $organization);
        $invitations = OrganizationInvitation::query()->where('organization_id', $organization)
            ->latest()->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => $invitations->getCollection()->map(fn (OrganizationInvitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'status' => $invitation->revoked_at ? 'revoked' : ($invitation->accepted_at ? 'accepted' : ($invitation->expires_at->isPast() ? 'expired' : 'pending')),
                'expires_at' => $invitation->expires_at->toISOString(),
                'accepted_at' => $invitation->accepted_at?->toISOString(),
                'revoked_at' => $invitation->revoked_at?->toISOString(),
                'created_at' => $invitation->created_at->toISOString(),
            ])->values(),
            'meta' => ['current_page' => $invitations->currentPage(), 'last_page' => $invitations->lastPage(), 'per_page' => $invitations->perPage(), 'total' => $invitations->total()],
        ]);
    }

    public function store(Request $request, int $organization): JsonResponse
    {
        $membership = $this->authorizeInvitations($request, $organization, true);
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'role' => ['required', Rule::in(['management', 'teacher', 'student', 'parent'])],
            'student_ids' => ['required_if:role,parent', 'prohibited_unless:role,parent', 'array', 'min:1', 'max:20'],
            'student_ids.*' => ['required', 'integer', 'distinct', Rule::exists('students', 'id')->where('organization_id', $organization)->where('status', 'active')],
        ]);
        if ($data['role'] === OrganizationRole::Management->value && $membership->role !== OrganizationRole::Admin) {
            throw ValidationException::withMessages(['role' => ['Only an organization administrator can invite management members.']]);
        }

        $email = Str::lower(trim($data['email']));
        $existingUser = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($data['role'] === OrganizationRole::Student->value) {
            $studentProfile = Student::query()->where('organization_id', $organization)->whereRaw('LOWER(email) = ?', [$email])->first();
            if (! $studentProfile || $studentProfile->status !== 'active') {
                throw ValidationException::withMessages(['email' => ['An active student roster profile with this email is required before sending an account invitation.']]);
            }
            if ($studentProfile->user_id && $studentProfile->user_id !== $existingUser?->id) {
                throw ValidationException::withMessages(['email' => ['This student profile is already linked to another account.']]);
            }
        }
        $existingMembership = $existingUser ? OrganizationMembership::query()
            ->where('organization_id', $organization)->where('user_id', $existingUser->id)->first() : null;
        if ($existingMembership?->status === MembershipStatus::Active) {
            throw ValidationException::withMessages(['email' => ['This account is already an active member of the organization.']]);
        }
        if ($existingMembership?->status === MembershipStatus::Suspended) {
            throw ValidationException::withMessages(['email' => ['This account cannot be invited while its organization membership is suspended.']]);
        }
        if ($data['role'] === OrganizationRole::Teacher->value && $existingUser) {
            $linkedTeacher = Teacher::query()->where('organization_id', $organization)->where('user_id', $existingUser->id)->exists();
            $teacherProfile = Teacher::query()->where('organization_id', $organization)->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($linkedTeacher || ($teacherProfile?->user_id && $teacherProfile->user_id !== $existingUser->id)) {
                throw ValidationException::withMessages(['email' => ['This account or teacher profile is already linked.']]);
            }
            if ($teacherProfile && $teacherProfile->status !== 'active') {
                throw ValidationException::withMessages(['email' => ['Activate the teacher roster profile before sending an account invitation.']]);
            }
        }

        $plainToken = Str::random(64);
        $invitation = OrganizationInvitation::query()->updateOrCreate(
            ['organization_id' => $organization, 'email' => $email, 'role' => $data['role'], 'accepted_at' => null],
            [
                'token_hash' => hash('sha256', $plainToken),
                'invited_by' => $request->user()->id,
                'expires_at' => now()->addDays(7),
                'accepted_by' => null,
                'revoked_at' => null,
                'revoked_by' => null,
                'revocation_reason' => null,
            ],
        );
        $invitation->students()->sync(collect($data['student_ids'] ?? [])->mapWithKeys(fn ($studentId): array => [
            $studentId => ['organization_id' => $organization],
        ])->all());
        $organizationRecord = Organization::query()->findOrFail($organization);
        $url = rtrim((string) config('app.frontend_url'), '/').'/invitations/accept?token='.urlencode($plainToken);
        Mail::to($email)->send(new OrganizationInvitationMail(
            $organizationRecord->name,
            $data['role'],
            $url,
            $request->user()->name,
        ));

        return response()->json([
            'data' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'status' => 'pending',
                'expires_at' => $invitation->expires_at->toISOString(),
            ],
            'message' => 'Invitation email sent.',
        ], 201);
    }

    public function destroy(Request $request, int $organization, int $record): JsonResponse
    {
        $this->authorizeInvitations($request, $organization, true);
        $invitation = OrganizationInvitation::query()->where('organization_id', $organization)->findOrFail($record);
        abort_if($invitation->accepted_at !== null, 409, 'Accepted invitations cannot be revoked.');
        abort_if($invitation->revoked_at !== null, 409, 'This invitation has already been revoked.');
        $invitation->update(['revoked_at' => now(), 'revoked_by' => $request->user()->id]);

        return response()->json(['message' => 'Invitation revoked.']);
    }

    public function show(string $token): JsonResponse
    {
        $invitation = $this->pendingInvitation($token);

        return response()->json(['data' => [
            'email' => $invitation->email,
            'role' => $invitation->role,
            'organization_id' => $invitation->organization_id,
            'organization' => $invitation->organization()->value('name'),
            'students' => $invitation->students()->where('students.organization_id', $invitation->organization_id)
                ->get(['students.id', 'students.name', 'students.admission_number'])
                ->map(fn (Student $student): array => ['id' => $student->id, 'name' => $student->name, 'admission_number' => $student->admission_number]),
            'expires_at' => $invitation->expires_at->toISOString(),
        ]]);
    }

    public function registerAndAccept(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'password' => ['required', 'string', 'min:12', 'confirmed', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
        ]);
        $invitation = $this->pendingInvitation($data['token']);
        $existingUser = User::query()->whereRaw('LOWER(email) = ?', [$invitation->email])->first();
        if ($existingUser) {
            if (! Hash::check($data['password'], $existingUser->password)) {
                throw ValidationException::withMessages(['password' => ['The password did not match this existing account.']]);
            }
            $user = DB::transaction(function () use ($existingUser, $invitation): User {
                if (! $existingUser->hasVerifiedEmail()) {
                    $existingUser->forceFill(['email_verified_at' => now()])->save();
                }
                $this->activateInvitation($invitation, $existingUser);

                return $existingUser->refresh();
            });
            if ($request->hasSession()) {
                auth()->guard('web')->login($user);
                $request->session()->regenerate();
            }

            return response()->json(['data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'email_verified_at' => $user->email_verified_at?->toISOString()], 'message' => 'Invitation accepted.']);
        }

        $user = DB::transaction(function () use ($data, $invitation): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => $data['password'],
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $this->activateInvitation($invitation, $user);

            return $user;
        });

        if ($request->hasSession()) {
            auth()->guard('web')->login($user);
            $request->session()->regenerate();
        }

        return response()->json(['data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'email_verified_at' => $user->email_verified_at?->toISOString()], 'message' => 'Invitation accepted.'], 201);
    }

    public function acceptExisting(Request $request, int $organization, string $token): JsonResponse
    {
        $user = $request->user();
        $invitation = $this->pendingInvitation($token);
        abort_unless($invitation->organization_id === $organization, 404);
        abort_unless(Str::lower($user->email) === $invitation->email, 403);

        DB::transaction(fn () => $this->activateInvitation($invitation, $user));

        return response()->json(['message' => 'Invitation accepted.']);
    }

    private function activateInvitation(OrganizationInvitation $invitation, User $user): void
    {
        $invitation = OrganizationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
        if ($invitation->accepted_at !== null || $invitation->revoked_at !== null || $invitation->expires_at->isPast()) {
            abort(410, 'This invitation has expired or has already been accepted.');
        }
        $existing = OrganizationMembership::query()->where('organization_id', $invitation->organization_id)->where('user_id', $user->id)->first();
        if ($existing?->status === MembershipStatus::Active || $existing?->status === MembershipStatus::Suspended) {
            abort(409, 'This account already has an organization membership.');
        }
        if ($existing) {
            $existing->update(['role' => $invitation->role, 'status' => MembershipStatus::Active, 'accepted_at' => now(), 'expires_at' => null]);
        } else {
            OrganizationMembership::query()->create([
                'organization_id' => $invitation->organization_id,
                'user_id' => $user->id,
                'role' => $invitation->role,
                'status' => MembershipStatus::Active,
                'invited_by' => $invitation->invited_by,
                'accepted_at' => now(),
            ]);
        }
        if ($invitation->role === OrganizationRole::Teacher->value) {
            $profile = Teacher::query()->where('organization_id', $invitation->organization_id)
                ->whereRaw('LOWER(email) = ?', [$invitation->email])->first();
            if ($profile && $profile->user_id && $profile->user_id !== $user->id) {
                abort(409, 'This teacher profile is already linked to another account.');
            }
            if ($profile && $profile->status !== 'active') {
                abort(409, 'This teacher profile is inactive. Contact the organization administrator.');
            }
            if ($profile) {
                $profile->update(['user_id' => $user->id]);
            } else {
                Teacher::query()->create([
                    'organization_id' => $invitation->organization_id,
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => 'active',
                ]);
            }
        }
        if ($invitation->role === OrganizationRole::Student->value) {
            $profile = Student::query()->where('organization_id', $invitation->organization_id)
                ->whereRaw('LOWER(email) = ?', [$invitation->email])->first();
            if (! $profile || $profile->status !== 'active') {
                abort(409, 'An active student roster profile with this email is required before accepting the invitation.');
            }
            if ($profile->user_id && $profile->user_id !== $user->id) {
                abort(409, 'This student profile is already linked to another account.');
            }
            $profile->update(['user_id' => $user->id]);
        }
        if ($invitation->role === OrganizationRole::Parent->value) {
            $students = $invitation->students()->wherePivot('organization_id', $invitation->organization_id)->get();
            if ($students->isEmpty()) {
                abort(409, 'At least one active student must be linked to a parent invitation.');
            }
            foreach ($students as $student) {
                if ($student->status !== 'active') {
                    abort(409, 'A linked student profile is no longer active.');
                }
                StudentGuardian::query()->firstOrCreate(
                    ['organization_id' => $invitation->organization_id, 'student_id' => $student->id, 'guardian_user_id' => $user->id],
                    ['linked_by' => $invitation->invited_by, 'relationship' => 'parent', 'is_primary' => false, 'linked_at' => now()],
                );
            }
        }
        $invitation->update(['accepted_at' => now(), 'accepted_by' => $user->id]);
    }

    private function pendingInvitation(string $plainToken): OrganizationInvitation
    {
        $invitation = OrganizationInvitation::query()->with('organization:id,name,status')
            ->where('token_hash', hash('sha256', $plainToken))->first();
        abort_unless($invitation !== null && $invitation->accepted_at === null && $invitation->revoked_at === null && $invitation->expires_at->isFuture(), 404);
        abort_unless($invitation->organization?->status === 'active', 404);

        return $invitation;
    }

    private function authorizeInvitations(Request $request, int $organization, bool $write = false): OrganizationMembership
    {
        $membership = $this->access->membership($request, $organization, $write);
        abort_unless(in_array($membership->role, [OrganizationRole::Admin, OrganizationRole::Management, OrganizationRole::IndependentEducator], true), 403);

        return $membership;
    }
}
