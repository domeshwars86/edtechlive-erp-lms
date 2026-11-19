<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_quizzes', function (Blueprint $table): void {
            $table->decimal('negative_marking_rate', 3, 2)->default(0);
            $table->unsignedTinyInteger('pass_percent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('course_quizzes', function (Blueprint $table): void {
            $table->dropColumn(['negative_marking_rate', 'pass_percent']);
        });
    }
};
