<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_exam_paper_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_exam_paper_id')->constrained()->cascadeOnDelete();
            $table->string('title', 100);
            $table->decimal('max_marks', 10, 2);
            $table->unsignedSmallInteger('position');
            $table->timestampsTz();
            $table->unique(['academic_exam_paper_id', 'position']);
            $table->index(['organization_id', 'academic_exam_paper_id']);
        });

        Schema::create('academic_exam_section_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_exam_result_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_exam_paper_section_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 10, 2);
            $table->timestampsTz();
            $table->unique(['academic_exam_result_id', 'academic_exam_paper_section_id'], 'exam_section_result_unique');
            $table->index(['organization_id', 'academic_exam_paper_section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_exam_section_results');
        Schema::dropIfExists('academic_exam_paper_sections');
    }
};
