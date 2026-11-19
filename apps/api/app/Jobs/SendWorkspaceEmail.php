<?php

namespace App\Jobs;

use App\Mail\WorkspaceAlertMail;
use App\Models\NotificationDelivery;
use App\Services\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendWorkspaceEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $deliveryId) {}

    public function handle(NotificationPreferences $preferences): void
    {
        $delivery = NotificationDelivery::query()->with('recipient')->find($this->deliveryId);
        if (! $delivery || $delivery->status !== 'queued') {
            return;
        }

        $recipient = $delivery->recipient;
        if (! $recipient || ! $recipient->hasVerifiedEmail() || ! $preferences->allowsEmail($recipient, $delivery->kind)) {
            $delivery->update(['status' => 'cancelled', 'cancelled_at' => now(), 'body' => null]);

            return;
        }

        $delivery->increment('attempts');
        Mail::to($recipient->email)->send(new WorkspaceAlertMail($delivery->title, $delivery->body));
        $delivery->update(['status' => 'sent', 'sent_at' => now(), 'failure_code' => null, 'body' => null]);
    }

    public function failed(?\Throwable $exception): void
    {
        NotificationDelivery::query()->whereKey($this->deliveryId)->where('status', 'queued')->update([
            'status' => 'failed',
            'failed_at' => now(),
            'failure_code' => $exception ? Str::limit(class_basename($exception), 120, '') : 'DeliveryFailed',
            'body' => null,
        ]);
    }
}
