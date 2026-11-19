<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\URL;

class LocalAuthLinkFactory
{
    public function verification(User $user): ?string
    {
        if (config('app.env') !== 'local' || $user->hasVerifiedEmail()) {
            return null;
        }

        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        );
    }
}
