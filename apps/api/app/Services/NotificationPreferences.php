<?php

namespace App\Services;

use App\Models\User;

class NotificationPreferences
{
    public const DEFAULTS = [
        'announcements' => true,
        'meetings' => true,
        'fees' => true,
        'learning' => true,
        'email' => [
            'announcements' => false,
            'meetings' => false,
            'fees' => false,
            'learning' => false,
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
            'meeting_scheduled', 'meeting_reminder' => 'meetings',
            'fee_issued', 'fee_payment' => 'fees',
            'learning_assignment_published', 'learning_quiz_published', 'learning_lesson_published' => 'learning',
            default => null,
        };

        return $category === null || $this->for($user)[$category];
    }

    public function allowsEmail(User $user, string $kind): bool
    {
        $category = match ($kind) {
            'announcement' => 'announcements',
            'meeting_scheduled', 'meeting_reminder' => 'meetings',
            'fee_issued', 'fee_payment' => 'fees',
            'learning_assignment_published', 'learning_quiz_published', 'learning_lesson_published' => 'learning',
            default => null,
        };

        return $category !== null && $this->for($user)['email'][$category];
    }
}
