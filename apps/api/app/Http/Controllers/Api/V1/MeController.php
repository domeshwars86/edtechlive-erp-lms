<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\PlatformOperatorAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController
{
    public function __invoke(Request $request, PlatformOperatorAccess $platformAccess): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toISOString(),
                'is_platform_admin' => $platformAccess->allows($user),
            ],
        ]);
    }
}
