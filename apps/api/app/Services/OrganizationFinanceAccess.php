<?php

namespace App\Services;

use App\Models\OrganizationMembership;
use Illuminate\Http\Request;

class OrganizationFinanceAccess
{
    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly OrganizationPermissions $permissions,
    ) {}

    public function read(Request $request, int $organization): OrganizationMembership
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($this->permissions->allows($membership, 'organization.finance.read'), 403);

        return $membership;
    }

    public function manage(Request $request, int $organization): OrganizationMembership
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless($this->permissions->allows($membership, 'organization.finance.manage'), 403);

        return $membership;
    }
}
