<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\ContactEnquiry;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\PlatformOperatorAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformOverviewController
{
    public function __invoke(Request $request, PlatformOperatorAccess $platformAccess): JsonResponse
    {
        abort_unless($platformAccess->allows($request->user()), 403);

        return response()->json([
            'data' => [
                'organizations' => [
                    'total' => Organization::query()->count(),
                    'active' => Organization::query()->where('status', 'active')->count(),
                    'suspended' => Organization::query()->where('status', 'suspended')->count(),
                ],
                'users' => [
                    'total' => User::query()->count(),
                    'verified' => User::query()->whereNotNull('email_verified_at')->count(),
                ],
                'educators' => OrganizationMembership::query()
                    ->where('status', MembershipStatus::Active->value)
                    ->whereIn('role', [OrganizationRole::Teacher->value, OrganizationRole::IndependentEducator->value])
                    ->distinct('user_id')->count('user_id'),
                'open_enquiries' => ContactEnquiry::query()->whereIn('status', ['new', 'in_progress'])->count(),
                'recent_organizations' => Organization::query()->select(['id', 'name', 'type', 'status', 'created_at'])
                    ->latest('created_at')->limit(8)->get(),
            ],
        ]);
    }
}
