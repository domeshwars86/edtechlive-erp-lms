<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Services\PlatformOperatorAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformRoleCatalogController
{
    public function __invoke(Request $request, PlatformOperatorAccess $platformAccess): JsonResponse
    {
        abort_unless($platformAccess->allows($request->user()), 403);

        $counts = OrganizationMembership::query()
            ->selectRaw('role, status, COUNT(*) as memberships')
            ->groupBy('role', 'status')
            ->get()
            ->groupBy('role')
            ->map(fn ($rows): array => $rows->mapWithKeys(fn ($row): array => [
                $row->status instanceof MembershipStatus ? $row->status->value : (string) $row->status => (int) $row->memberships,
            ])->all());

        $descriptions = [
            OrganizationRole::Admin->value => 'Organization administration, academic setup and authorized institutional workflows.',
            OrganizationRole::Management->value => 'Operational oversight and aggregate reports; member details require the relevant capability.',
            OrganizationRole::Teacher->value => 'Assigned classes and courses, attendance, learning materials and assessment work.',
            OrganizationRole::Student->value => 'Personal profile, active course enrollment, classwork, attendance and results.',
            OrganizationRole::Parent->value => 'Only explicitly linked children, with access to their permitted learning and finance records.',
            OrganizationRole::IndependentEducator->value => 'Independent teaching workspace, courses, enrolled learners and educator reports.',
        ];

        $organizationRoles = array_map(function (OrganizationRole $role) use ($counts, $descriptions): array {
            $roleCounts = $counts->get($role->value, []);

            return [
                'key' => $role->value,
                'name' => str($role->value)->replace('_', ' ')->title()->toString(),
                'scope' => 'Organization membership',
                'description' => $descriptions[$role->value],
                'active_memberships' => $roleCounts[MembershipStatus::Active->value] ?? 0,
                'suspended_memberships' => $roleCounts[MembershipStatus::Suspended->value] ?? 0,
            ];
        }, OrganizationRole::cases());

        return response()->json([
            'data' => [
                'platform_role' => [
                    'key' => 'super_admin',
                    'name' => 'Super Admin',
                    'scope' => 'Platform operations',
                    'description' => 'Verified operator access is assigned through the deployment allowlist and does not grant default access to organization learning records.',
                ],
                'organization_roles' => $organizationRoles,
                'access_model' => [
                    'editable_here' => false,
                    'summary' => 'These are fixed organization membership roles. Membership and finance capabilities have limited audited overrides; a general cross-domain permission editor is not available.',
                ],
            ],
        ]);
    }
}
