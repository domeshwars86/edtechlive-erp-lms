<?php

namespace App\Services;

use App\Models\User;

class PlatformOperatorAccess
{
    public function allows(User $user): bool
    {
        $allowedEmails = array_map('mb_strtolower', config('services.platform.admin_emails', []));

        return in_array(mb_strtolower($user->email), $allowedEmails, true);
    }
}
