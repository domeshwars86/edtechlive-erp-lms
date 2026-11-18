<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_modules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['course_id', 'title']);
            $table->index(['organization_id', 'course_id', 'sort_order']);
        });

        Schema::table('course_lessons', function (Blueprint $table): void {
            $table->foreignId('module_id')->nullable()->after('course_id')->constrained('course_modules')->nullOnDelete();
            $table->index(['module_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('course_lessons', function (Blueprint $table): void {
            $table->dropIndex(['module_id', 'sort_order']);
            $table->dropConstrainedForeignId('module_id');
        });

        Schema::dropIfExists('course_modules');
    }
};
