<?php

namespace App\Services;

use App\Jobs\SendWorkspaceEmail;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Support\Collection;

class WorkspaceEmailDispatcher
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    public function dispatch(Collection|array $users, array $payload): void
    {
        if (! config('workspace-notifications.email_enabled')) {
            return;
        }

        foreach (collect($users)->unique('id') as $user) {
            if (! $user instanceof User || ! $user->hasVerifiedEmail() || ! $this->preferences->allowsEmail($user, $payload['kind'])) {
                continue;
            }

            $delivery = NotificationDelivery::query()->create([
                'organization_id' => $payload['organization_id'],
                'user_id' => $user->id,
                'kind' => $payload['kind'],
                'title' => $payload['title'],
                'body' => $payload['body'],
                'status' => 'queued',
                'queued_at' => now(),
            ]);
            SendWorkspaceEmail::dispatch($delivery->id)->afterCommit();
        }
    }
}
