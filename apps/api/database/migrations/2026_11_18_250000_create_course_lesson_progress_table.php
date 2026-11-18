<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_lesson_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('course_lessons')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['lesson_id', 'student_id']);
            $table->index(['organization_id', 'student_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_lesson_progress');
    }
};
