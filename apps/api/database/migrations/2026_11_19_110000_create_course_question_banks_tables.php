<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_question_banks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->timestampsTz();
            $table->unique(['course_id', 'title']);
            $table->index(['organization_id', 'course_id']);
        });

        Schema::create('course_question_bank_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_bank_id')->constrained('course_question_banks')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('prompt', 2000);
            $table->json('options');
            $table->string('question_type', 24)->default('single_choice');
            $table->string('difficulty', 16)->default('medium');
            $table->json('correct_options');
            $table->unsignedSmallInteger('points')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->index(['question_bank_id', 'sort_order']);
            $table->index(['organization_id', 'question_bank_id']);
        });

        Schema::table('quiz_questions', function (Blueprint $table): void {
            $table->foreignId('question_bank_question_id')->nullable()
                ->constrained('course_question_bank_questions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('question_bank_question_id');
        });

        Schema::dropIfExists('course_question_bank_questions');
        Schema::dropIfExists('course_question_banks');
    }
};
