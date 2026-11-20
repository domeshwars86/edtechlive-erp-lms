<?php

use App\Models\AiAssistanceUsage;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ai:purge-usage', function (): void {
    $deleted = AiAssistanceUsage::query()->where('created_at', '<', now()->subDays(90))->delete();
    $this->info("Purged {$deleted} AI usage records older than 90 days.");
})->purpose('Remove educator AI usage metadata after its 90-day retention period');
