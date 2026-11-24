<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table): void {
            $table->dropIndex(['reminder_sent_at']);
            $table->index(['status', 'starts_at', 'reminder_sent_at']);
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table): void {
            $table->dropIndex(['status', 'starts_at', 'reminder_sent_at']);
            $table->index('reminder_sent_at');
        });
    }
};
