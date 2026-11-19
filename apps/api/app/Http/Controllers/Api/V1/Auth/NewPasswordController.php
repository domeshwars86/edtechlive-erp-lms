<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class NewPasswordController
{
    public function store(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $resetUserId = null;
        $status = Password::reset($data, function ($user, string $password) use (&$resetUserId): void {
            $resetUserId = $user->getAuthIdentifier();
            $user->forceFill([
                'password' => Hash::make($password),
            ])->setRememberToken(Str::random(60));
            $user->save();
            $user->tokens()->delete();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'The password reset link is invalid or has expired.',
                'status' => $status,
            ], 422);
        }

        if ((string) Auth::guard('web')->id() === (string) $resetUserId) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Password updated. You can sign in with your new password.',
        ]);
    }
}
