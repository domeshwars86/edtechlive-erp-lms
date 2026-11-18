<?php

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use Illuminate\Http\Request;

class OrganizationAccess
{
    public function membership(Request $request, int $organizationId, bool $write = false): OrganizationMembership
    {
        $membership = $request->user()->memberships()
            ->with('organization')
            ->where('organization_id', $organizationId)
            ->where('status', MembershipStatus::Active->value)
            ->whereHas('organization', fn ($query) => $query->where('status', 'active'))
            ->first();

        abort_unless($membership !== null, 404);

        if ($write) {
            abort_unless(in_array($membership->role, [
                OrganizationRole::Admin,
                OrganizationRole::Management,
                OrganizationRole::IndependentEducator,
            ], true), 403);
        }

        return $membership;
    }
}
