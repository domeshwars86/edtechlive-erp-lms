<?php

namespace App\Notifications;

use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Notifications\Notification;

class WorkspaceNotification extends Notification
{
    public function __construct(private readonly array $payload) {}

    public function via(object $notifiable): array
    {
        return ['database', ExpoPushChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->payload;
    }

    public function toExpoPush(object $notifiable): array
    {
        return $this->payload;
    }
}
