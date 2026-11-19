<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_quizzes', function (Blueprint $table): void {
            $table->timestampTz('opens_at')->nullable();
            $table->timestampTz('closes_at')->nullable();
        });

        Schema::table('quiz_questions', function (Blueprint $table): void {
            $table->string('question_type', 24)->default('single_choice');
            $table->string('difficulty', 16)->default('medium');
            $table->json('correct_options')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table): void {
            $table->dropColumn(['question_type', 'difficulty', 'correct_options']);
        });

        Schema::table('course_quizzes', function (Blueprint $table): void {
            $table->dropColumn(['opens_at', 'closes_at']);
        });
    }
};
