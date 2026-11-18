<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teaching_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('academic_section_id')->constrained('academic_sections')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique(['academic_session_id', 'academic_section_id', 'subject_id', 'teacher_id'], 'teaching_assignment_unique');
            $table->index(['organization_id', 'teacher_id', 'academic_session_id']);
            $table->index(['organization_id', 'academic_section_id', 'academic_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_assignments');
    }
};
