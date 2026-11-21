<?php

namespace App\Services;

use App\Jobs\SendExpoPush;
use App\Models\ExpoPushDevice;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ExpoPushService
{
    public function enabled(): bool
    {
        return (bool) config('services.expo_push.enabled') && filled(config('services.expo_push.project_id'));
    }

    public function dispatchFor(User $user, array $payload): void
    {
        if (! $this->enabled()) {
            return;
        }

        $data = array_filter([
            'organization_id' => $payload['organization_id'] ?? null,
            'kind' => $payload['kind'] ?? null,
            'resource_type' => $payload['resource_type'] ?? null,
            'resource_id' => $payload['resource_id'] ?? null,
            'course_id' => $payload['course_id'] ?? null,
        ], fn (mixed $value): bool => $value !== null);

        if (! $user->hasVerifiedEmail()) {
            return;
        }

        foreach (ExpoPushDevice::query()->where('user_id', $user->id)->get() as $device) {
            SendExpoPush::dispatch($device->id, $data)->afterCommit();
        }
    }

    public function send(ExpoPushDevice $device, array $data): void
    {
        if (! $this->enabled()) {
            return;
        }

        $user = $device->user;
        if (! $user?->hasVerifiedEmail() || $user->platform_status === 'suspended') {
            return;
        }

        $request = Http::acceptJson()->asJson()->timeout(10);
        $accessToken = config('services.expo_push.access_token');
        if (filled($accessToken)) {
            $request = $request->withToken((string) $accessToken);
        }

        $response = $request->post('https://exp.host/--/api/v2/push/send', [
            'to' => $device->push_token,
            'title' => 'New edTechLive update',
            'body' => 'Sign in to view your latest update.',
            'sound' => 'default',
            'data' => $data,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Expo push service returned HTTP '.$response->status());
        }

        $tickets = $response->json('data');
        if (is_array($tickets)) {
            foreach (array_is_list($tickets) ? $tickets : [$tickets] as $ticket) {
                if (! is_array($ticket) || ($ticket['status'] ?? null) !== 'error') {
                    continue;
                }

                $code = data_get($ticket, 'details.error');
                if ($code === 'DeviceNotRegistered') {
                    ExpoPushDevice::query()->whereKey($device->getKey())->delete();

                    return;
                }

                throw new RuntimeException('Expo push service rejected the notification ('.(string) ($ticket['message'] ?? 'unknown error').').');
            }
        }
    }
}
