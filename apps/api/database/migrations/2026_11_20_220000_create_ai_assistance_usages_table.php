<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_assistance_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('model', 120);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->string('status', 20);
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['organization_id', 'user_id', 'created_at'], 'ai_usage_org_user_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistance_usages');
    }
};
