<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 180);
            $table->string('original_name', 255);
            $table->string('storage_path', 1024)->unique();
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->boolean('is_published')->default(false);
            $table->timestampsTz();
            $table->index(['organization_id', 'course_id', 'is_published']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_assets');
    }
};
