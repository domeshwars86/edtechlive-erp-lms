<?php

namespace App\Services;

use App\Models\User;

class NotificationPreferences
{
    public const DEFAULTS = [
        'announcements' => true,
        'meetings' => true,
        'fees' => true,
        'email' => [
            'announcements' => false,
            'meetings' => false,
            'fees' => false,
        ],
    ];

    public function for(User $user): array
    {
        return array_replace_recursive(self::DEFAULTS, $user->notification_preferences ?? []);
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

    public function allowsEmail(User $user, string $kind): bool
    {
        $category = match ($kind) {
            'announcement' => 'announcements',
            'meeting_scheduled' => 'meetings',
            'fee_issued', 'fee_payment' => 'fees',
            default => null,
        };

        return $category !== null && $this->for($user)['email'][$category];
    }
}
