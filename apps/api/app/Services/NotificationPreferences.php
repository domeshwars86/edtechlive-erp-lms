<?php

namespace App\Services;

use App\Models\User;

class NotificationPreferences
{
    public const DEFAULTS = [
        'announcements' => true,
        'meetings' => true,
        'fees' => true,
    ];

    public function for(User $user): array
    {
        return array_replace(self::DEFAULTS, $user->notification_preferences ?? []);
    }

    public function allows(User $user, string $kind): bool
    {
        $category = match ($kind) {
            'announcement' => 'announcements',
            'meeting_scheduled' => 'meetings',
            'fee_issued', 'fee_payment' => 'fees',
            default => null,
        };

        return $category === null || $this->for($user)[$category];
    }
}
