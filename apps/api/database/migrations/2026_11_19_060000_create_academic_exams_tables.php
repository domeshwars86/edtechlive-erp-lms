<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_exams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 160);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'academic_session_id', 'starts_on']);
        });

        Schema::create('academic_exam_papers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_exam_id')->constrained('academic_exams')->cascadeOnDelete();
            $table->foreignId('academic_section_id')->constrained('academic_sections')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->date('exam_on');
            $table->decimal('max_marks', 10, 2);
            $table->timestampsTz();
            $table->unique(['academic_exam_id', 'academic_section_id', 'subject_id']);
            $table->index(['organization_id', 'academic_section_id', 'exam_on']);
        });

        Schema::create('academic_exam_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_exam_paper_id')->constrained('academic_exam_papers')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('outcome', 16);
            $table->decimal('score', 10, 2)->nullable();
            $table->string('remarks', 500)->nullable();
            $table->timestampsTz();
            $table->unique(['academic_exam_paper_id', 'student_id']);
            $table->index(['organization_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_exam_results');
        Schema::dropIfExists('academic_exam_papers');
        Schema::dropIfExists('academic_exams');
    }
};
