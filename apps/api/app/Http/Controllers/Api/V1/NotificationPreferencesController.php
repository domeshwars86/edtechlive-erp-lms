<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\NotificationPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferencesController
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->preferences->for($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'announcements' => ['required', 'boolean'],
            'meetings' => ['required', 'boolean'],
            'fees' => ['required', 'boolean'],
        ]);
        $user = $request->user();
        $user->forceFill(['notification_preferences' => $data])->save();

        return response()->json(['data' => $this->preferences->for($user)]);
    }
}
