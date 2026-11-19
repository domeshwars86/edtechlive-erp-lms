<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Requests\Api\V1\Auth\EmailAddressRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class PasswordResetLinkController
{
    public function store(EmailAddressRequest $request): JsonResponse
    {
        $resetUrl = null;
        $sendStatus = Password::sendResetLink(
            ['email' => $request->validated('email')],
            config('app.env') === 'local'
                ? function ($user, string $token) use (&$resetUrl): string {
                    $resetUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/')
                        .'/reset-password?'.http_build_query([
                            'token' => $token,
                            'email' => $user->getEmailForPasswordReset(),
                        ]);

                    return Password::RESET_LINK_SENT;
                }
            : null,
        );

        $response = [
            'message' => 'If an account exists for that address, password reset instructions have been sent.',
        ];
        if (config('app.env') === 'local' && $sendStatus === Password::RESET_LINK_SENT && $resetUrl !== null) {
            $response['reset_url'] = $resetUrl;
        }

        return response()->json($response, 202);
    }
}
