<?php

namespace App\Jobs;

use App\Models\ExpoPushDevice;
use App\Services\ExpoPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendExpoPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $deviceId, public readonly array $data) {}

    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(ExpoPushService $push): void
    {
        $device = ExpoPushDevice::query()->find($this->deviceId);
        if ($device) {
            $push->send($device, $this->data);
            $device->forceFill(['last_seen_at' => now()])->save();
        }
    }
}
