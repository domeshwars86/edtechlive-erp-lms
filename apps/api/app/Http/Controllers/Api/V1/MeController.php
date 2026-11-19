<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\LocalAuthLinkFactory;
use App\Services\PlatformOperatorAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

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

    public function updateProfile(Request $request, PlatformOperatorAccess $platformAccess, LocalAuthLinkFactory $authLinks): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:254', Rule::unique('users', 'email')->ignore($user->getKey())],
            'current_password' => ['required', 'current_password'],
            'password' => ['nullable', 'string', Password::min(12)->letters()->numbers(), 'confirmed'],
        ]);
        $emailChanged = $data['email'] !== $user->email;
        $passwordChanged = isset($data['password']) && $data['password'] !== '';
        $allowlistedEmails = array_map('mb_strtolower', config('services.platform.admin_emails', []));
        if ($emailChanged && ($platformAccess->allows($user) || in_array($data['email'], $allowlistedEmails, true))) {
            throw ValidationException::withMessages([
                'email' => ['Platform operator email addresses are managed through the platform allowlist.'],
            ]);
        }

        $user->forceFill([
            'name' => trim($data['name']),
            'email' => $data['email'],
            'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
            ...($passwordChanged ? ['password' => $data['password']] : []),
        ])->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }
        if ($passwordChanged) {
            $currentToken = $user->currentAccessToken();
            $tokens = $user->tokens();
            if ($currentToken instanceof PersonalAccessToken) {
                $tokens->where('id', '!=', $currentToken->getKey())->delete();
            } else {
                $tokens->delete();
            }
        }

        $response = [
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toISOString(),
                'is_platform_admin' => $platformAccess->allows($user),
            ],
            'email_changed' => $emailChanged,
            'password_changed' => $passwordChanged,
        ];
        if ($emailChanged && ($verificationUrl = $authLinks->verification($user)) !== null) {
            $response['verification_url'] = $verificationUrl;
        }

        return response()->json($response);
    }
}
