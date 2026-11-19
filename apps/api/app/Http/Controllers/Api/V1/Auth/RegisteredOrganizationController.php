<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Http\Requests\Api\V1\Auth\RegisterOrganizationRequest;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\LocalAuthLinkFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisteredOrganizationController
{
    public function store(RegisterOrganizationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $email = $data['email'];

        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser !== null) {
            if (! $existingUser->hasVerifiedEmail()) {
                $existingUser->sendEmailVerificationNotification();
            }

            return $this->verificationResponse($existingUser);
        }

        $user = DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $type = OrganizationType::from($data['organization_type']);
            $baseSlug = Str::slug($data['organization_name']) ?: 'learning-community';
            $organization = Organization::query()->create([
                'name' => $data['organization_name'],
                'slug' => Str::limit($baseSlug, 165, '').'-'.Str::lower(Str::random(8)),
                'type' => $type,
                'status' => 'active',
                'timezone' => 'Asia/Kolkata',
            ]);

            OrganizationMembership::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => $type === OrganizationType::IndependentEducator
                    ? OrganizationRole::IndependentEducator
                    : OrganizationRole::Admin,
                'status' => MembershipStatus::Active,
                'accepted_at' => now(),
            ]);

            return $user;
        });

        $user->sendEmailVerificationNotification();

        return $this->verificationResponse($user);
    }

    private function verificationResponse(User $user): JsonResponse
    {
        $response = [
            'message' => 'If the address can be registered, a verification link has been sent.',
            'status' => 'verification_required',
        ];
        $localLink = app(LocalAuthLinkFactory::class)->verification($user);
        if ($localLink !== null) {
            $response['verification_url'] = $localLink;
        }

        return response()->json($response, 202);
    }
}
