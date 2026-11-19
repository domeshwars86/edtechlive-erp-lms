<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_exams', function (Blueprint $table): void {
            $table->string('series_name', 120)->nullable();
            $table->index(['organization_id', 'academic_session_id', 'series_name'], 'academic_exams_series_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('academic_exams', function (Blueprint $table): void {
            $table->dropIndex('academic_exams_series_lookup');
            $table->dropColumn('series_name');
        });
    }
};
