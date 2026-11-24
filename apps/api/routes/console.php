<?php

use App\Models\AiAssistanceUsage;
use App\Models\ContactEnquiry;
use App\Models\Meeting;
use App\Services\OrganizationNotifier;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ai:purge-usage', function (): void {
    $deleted = AiAssistanceUsage::query()->where('created_at', '<', now()->subDays(90))->delete();
    $this->info("Purged {$deleted} AI usage records older than 90 days.");
})->purpose('Remove educator AI usage metadata after its 90-day retention period');

Artisan::command('meetings:send-reminders {--minutes= : Reminder lead time in minutes (1-1440)}', function (OrganizationNotifier $notifier): int {
    $configuredMinutes = config('meetings.reminder_minutes', 15);
    $option = $this->option('minutes');
    $minutes = filter_var($option ?? $configuredMinutes, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1440]]);
    if ($minutes === false) {
        $this->error('Reminder lead time must be a whole number between 1 and 1440 minutes.');

        return Command::FAILURE;
    }

    $now = now();
    $meetingIds = Meeting::query()->where('status', 'scheduled')->whereNull('reminder_sent_at')
        ->where('starts_at', '>', $now)->where('starts_at', '<=', $now->copy()->addMinutes($minutes))
        ->orderBy('starts_at')->orderBy('id')->pluck('id');
    $sent = 0;

    foreach ($meetingIds as $meetingId) {
        $sent += DB::transaction(function () use ($meetingId, $notifier, $minutes, $now): int {
            $meeting = Meeting::query()->lockForUpdate()->find($meetingId);
            if (! $meeting || $meeting->status !== 'scheduled' || $meeting->reminder_sent_at !== null
                || $meeting->starts_at->lte($now) || $meeting->starts_at->gt($now->copy()->addMinutes($minutes))) {
                return 0;
            }

            $notifier->meetingReminder($meeting, $minutes);
            $meeting->forceFill(['reminder_sent_at' => now()])->save();

            return 1;
        });
    }

    $this->info("Sent {$sent} meeting reminders for meetings starting within {$minutes} minutes.");

    return Command::SUCCESS;
})->purpose('Notify meeting participants before scheduled class and management meetings');

Artisan::command('contact-enquiries:purge {--force : Delete enquiries past the configured retention period}', function (): int {
    $configuredDays = config('privacy.contact_enquiry_retention_days');
    if ($configuredDays === null || $configuredDays === '') {
        $this->info('Contact enquiry retention is not configured; no records were removed.');

        return Command::SUCCESS;
    }

    $days = filter_var($configuredDays, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 36500]]);
    if ($days === false) {
        $this->error('CONTACT_ENQUIRY_RETENTION_DAYS must be a whole number between 1 and 36500.');

        return Command::FAILURE;
    }

    if ($this->option('force') && ! config('privacy.contact_enquiry_retention_enabled')) {
        $this->error('Set CONTACT_ENQUIRY_RETENTION_ENABLED=true after approving the retention policy before deleting records.');

        return Command::FAILURE;
    }

    $cutoff = now()->subDays($days);
    $query = ContactEnquiry::query()->where('created_at', '<', $cutoff);
    $count = $query->count();
    $this->info("Found {$count} contact enquiries created before {$cutoff->toDateString()} (retention {$days} days).");

    if (! $this->option('force')) {
        $this->comment('Dry run only; rerun with --force to remove eligible records.');

        return Command::SUCCESS;
    }

    $deleted = $query->delete();
    $this->info("Purged {$deleted} contact enquiries.");

    return Command::SUCCESS;
})->purpose('Review or purge contact enquiries past the explicitly configured retention period');

Schedule::command('contact-enquiries:purge --force')
    ->dailyAt('02:15')
    ->when(function (): bool {
        $days = filter_var(config('privacy.contact_enquiry_retention_days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 36500]]);

        return config('privacy.contact_enquiry_retention_enabled') && $days !== false;
    });
