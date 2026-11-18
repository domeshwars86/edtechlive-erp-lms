<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 120);
            $table->text('body');
            $table->string('audience', 24);
            $table->unsignedInteger('recipient_count')->default(0);
            $table->timestampTz('sent_at');
            $table->timestampsTz();
            $table->index(['organization_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
