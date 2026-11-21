<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Notifications\WorkspaceNotification;
use App\Services\ExpoPushService;

class ExpoPushChannel
{
    public function __construct(private readonly ExpoPushService $push) {}

    public function send(object $notifiable, WorkspaceNotification $notification): void
    {
        if ($notifiable instanceof User) {
            $this->push->dispatchFor($notifiable, $notification->toExpoPush($notifiable));
        }
    }
}
