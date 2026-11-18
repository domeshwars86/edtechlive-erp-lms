<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 160);
            $table->text('instructions')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->decimal('max_points', 8, 2)->default(100);
            $table->string('status', 20)->default('draft');
            $table->timestampsTz();
            $table->index(['organization_id', 'course_id', 'status', 'due_at']);
        });

        Schema::create('assignment_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained('course_assignments')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->longText('answer');
            $table->timestampTz('submitted_at');
            $table->decimal('awarded_points', 8, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('graded_at')->nullable();
            $table->timestampsTz();
            $table->unique(['assignment_id', 'student_id']);
            $table->index(['organization_id', 'student_id', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('course_assignments');
    }
};
