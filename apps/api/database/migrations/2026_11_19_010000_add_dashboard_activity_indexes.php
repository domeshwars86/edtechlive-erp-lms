<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_lesson_progress', function (Blueprint $table): void {
            $table->index(['organization_id', 'completed_at'], 'course_progress_org_completed_idx');
        });

        Schema::table('assignment_submissions', function (Blueprint $table): void {
            $table->index(['organization_id', 'submitted_at'], 'assignment_submissions_org_submitted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('assignment_submissions', function (Blueprint $table): void {
            $table->dropIndex('assignment_submissions_org_submitted_idx');
        });

        Schema::table('course_lesson_progress', function (Blueprint $table): void {
            $table->dropIndex('course_progress_org_completed_idx');
        });
    }
};
