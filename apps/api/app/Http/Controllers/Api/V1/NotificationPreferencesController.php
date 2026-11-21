<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\ExpoPushService;
use App\Services\NotificationPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferencesController
{
    public function __construct(private readonly NotificationPreferences $preferences, private readonly ExpoPushService $push) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->preferences->for($request->user()),
            'email_available' => config('workspace-notifications.email_enabled'),
            'push_available' => $this->push->enabled(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'announcements' => ['required', 'boolean'],
            'meetings' => ['required', 'boolean'],
            'fees' => ['required', 'boolean'],
            'learning' => ['required', 'boolean'],
            'email' => ['required', 'array:announcements,meetings,fees,learning'],
            'email.announcements' => ['required', 'boolean'],
            'email.meetings' => ['required', 'boolean'],
            'email.fees' => ['required', 'boolean'],
            'email.learning' => ['required', 'boolean'],
        ]);
        if (! config('workspace-notifications.email_enabled') && collect($data['email'])->contains(true)) {
            return response()->json(['message' => 'Email notifications are not enabled for this environment.'], 422);
        }
        $user = $request->user();
        $user->forceFill(['notification_preferences' => $data])->save();

        return response()->json([
            'data' => $this->preferences->for($user),
            'email_available' => config('workspace-notifications.email_enabled'),
            'push_available' => $this->push->enabled(),
        ]);
    }
}
