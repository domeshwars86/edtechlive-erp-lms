<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthenticatedSessionController
{
    public function store(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw new AuthenticationException('The provided credentials are incorrect.');
        }

        if ($user->platform_status === 'suspended') {
            return response()->json([
                'message' => 'This account is suspended. Contact platform support for assistance.',
                'status' => 'account_suspended',
            ], 403);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Verify your email address before signing in.',
                'status' => 'email_verification_required',
            ], 403);
        }

        if (! empty($data['device_name'])) {
            $token = $user->createToken(
                $data['device_name'],
                ['*'],
                now()->addDays(30),
            );

            return response()->json([
                'data' => $this->userData($user),
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at?->toISOString(),
            ]);
        }

        Auth::guard('web')->login($user, $data['remember'] ?? false);
        $request->session()->regenerate();

        return response()->json(['data' => $this->userData($user)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $user->currentAccessToken();

        if ($currentToken instanceof PersonalAccessToken) {
            $currentToken->delete();
        }

        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Signed out.']);
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toISOString(),
        ];
    }
}
