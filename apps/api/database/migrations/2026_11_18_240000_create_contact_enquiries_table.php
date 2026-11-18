<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_enquiries', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 254)->index();
            $table->string('phone', 30)->nullable();
            $table->string('organization_name', 180)->nullable();
            $table->string('role', 60);
            $table->string('category', 80);
            $table->text('message');
            $table->timestampTz('consented_at');
            $table->string('status', 20)->default('new');
            $table->timestampTz('notified_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_enquiries');
    }
};
