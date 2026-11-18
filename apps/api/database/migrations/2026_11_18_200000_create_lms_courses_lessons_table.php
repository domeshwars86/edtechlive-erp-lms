<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 160);
            $table->string('level', 80)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'status', 'updated_at']);
            $table->index(['organization_id', 'teacher_id', 'status']);
        });

        Schema::create('course_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrolled_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['course_id', 'student_id']);
            $table->index(['organization_id', 'student_id', 'status']);
        });

        Schema::create('course_lessons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->unsignedInteger('sort_order')->default(0);
            $table->longText('content')->nullable();
            $table->string('external_url', 2048)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestampsTz();
            $table->index(['course_id', 'status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_lessons');
        Schema::dropIfExists('course_enrollments');
        Schema::dropIfExists('courses');
    }
};
