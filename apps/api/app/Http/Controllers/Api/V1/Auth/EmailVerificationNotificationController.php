<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Requests\Api\V1\Auth\EmailAddressRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class EmailVerificationNotificationController
{
    public function store(EmailAddressRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        if ($user !== null && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'message' => 'If the address has an unverified account, a verification link has been sent.',
        ], 202);
    }
}
