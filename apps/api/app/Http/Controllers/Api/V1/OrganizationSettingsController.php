<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationSettingsController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function update(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization, true);
        abort_unless(in_array($membership->role, [OrganizationRole::Admin, OrganizationRole::IndependentEducator], true), 403);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:180'],
            'timezone' => ['sometimes', 'required', 'timezone:all'],
        ]);
        abort_if($data === [], 422, 'Provide at least one organization setting to update.');

        $membership->organization->update($data);

        return response()->json([
            'data' => [
                'id' => $membership->organization->id,
                'name' => $membership->organization->name,
                'slug' => $membership->organization->slug,
                'type' => $membership->organization->type->value,
                'status' => $membership->organization->status,
                'timezone' => $membership->organization->timezone,
            ],
        ]);
    }
}
