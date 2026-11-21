<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ExpoPushDevice;
use App\Services\ExpoPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushDeviceController
{
    public function __construct(private readonly ExpoPushService $push) {}

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->push->enabled(), 503, 'Push notifications are not configured for this environment.');
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255', 'regex:/\\A(?:Expo|Exponent)PushToken\\[[A-Za-z0-9-]+\\]\\z/'],
            'platform' => ['required', 'in:ios,android'],
        ]);
        $tokenHash = hash('sha256', $data['token']);
        $device = ExpoPushDevice::query()->where('token_hash', $tokenHash)->first();
        abort_if($device && $device->user_id !== $request->user()->id, 409, 'This device is already registered to another account. Sign out of that account on the device first.');
        $device = $device ?? new ExpoPushDevice;
        $device->forceFill([
            'user_id' => $request->user()->id,
            'push_token' => $data['token'],
            'token_hash' => $tokenHash,
            'platform' => $data['platform'],
            'last_seen_at' => now(),
        ])->save();

        return response()->json(['data' => ['id' => $device->id, 'platform' => $device->platform, 'registered' => true]], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255', 'regex:/\\A(?:Expo|Exponent)PushToken\\[[A-Za-z0-9-]+\\]\\z/'],
        ]);
        $deleted = ExpoPushDevice::query()->where('user_id', $request->user()->id)
            ->where('token_hash', hash('sha256', $data['token']))->delete();

        return response()->json(['data' => ['removed' => $deleted > 0]]);
    }
}
